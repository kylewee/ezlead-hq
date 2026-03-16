<?php
/**
 * DiagnosticService - Auto-populates diagnostic records from Mitchell1 API
 *
 * When a diagnostic is created with a linked Mechanic Job (which has vehicle info),
 * this service queries Mitchell1Client::getEstimate() to pull:
 * - Book labor hours
 * - OEM parts pricing
 * - Component/repair name
 * - Skill level and procedure notes
 *
 * Existing files reused:
 * - /home/kylewee/mitchell1/Mitchell1Client.php (API wrapper)
 * - /var/www/ezlead-platform/core/lib/EstimateEngine.php (isMitchell1Alive, calcLaborCost)
 * - /var/www/ezlead-platform/core/lib/CRMHelper.php (entity/field constants)
 */

require_once '/home/kylewee/mitchell1/Mitchell1Client.php';
require_once '/var/www/ezlead-platform/core/lib/EstimateEngine.php';
require_once '/var/www/ezlead-platform/core/lib/CRMHelper.php';

class DiagnosticService
{
    private $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Auto-populate a diagnostic record with Mitchell1 data.
     * Called when a new diagnostic has status "Pending" and M1 fields are empty.
     *
     * @param int $diagId  The diagnostic record ID
     * @param int $jobId   The linked Mechanic Job ID
     * @return array       M1 results summary or error info
     */
    public function autoLookup(int $diagId, int $jobId): array
    {
        // 1. Get vehicle info from the Mechanic Job
        $job = $this->db->query(
            "SELECT * FROM app_entity_42 WHERE id = " . intval($jobId)
        )->fetch_assoc();

        if (!$job) {
            $this->log("Auto-lookup failed for diag #{$diagId}: Job #{$jobId} not found");
            return ['error' => 'Job not found'];
        }

        $year    = $job['field_358'] ?? '';
        $make    = $job['field_359'] ?? '';
        $model   = $job['field_360'] ?? '';
        $problem = $job['field_361'] ?? '';

        if (!$year || !$make || !$model || !$problem) {
            $this->log("Auto-lookup skipped for diag #{$diagId}: Missing vehicle info on job #{$jobId}");
            return ['error' => 'Missing vehicle info on job'];
        }

        // 2. Check if Mitchell1 is alive (reuse existing health check)
        $statusFile = '/var/lib/mitchell1/status.json';
        if (file_exists($statusFile)) {
            $status = json_decode(file_get_contents($statusFile), true);
            if (!($status['alive'] ?? true)) {
                $this->log("Auto-lookup for diag #{$diagId}: M1 session expired, skipping");
                return ['error' => 'Mitchell1 session expired', 'fallback' => 'ai'];
            }
        }

        // 3. Try Mitchell1 API via existing client
        $client = new Mitchell1Client();
        if (!$client->hasSession()) {
            $this->log("Auto-lookup for diag #{$diagId}: No M1 session cookie");
            return ['error' => 'No M1 session cookie', 'fallback' => 'ai'];
        }

        $m1Result = $client->getEstimate($year, $make, $model, '', $problem);

        // 4. Extract labor hours and parts pricing
        $m1LaborHours = 0;
        $m1RepairName = '';
        $m1PartsCost = 0;
        $skillLevel = '';
        $procedureNote = '';

        if (!empty($m1Result['labor'])) {
            $firstLabor = $m1Result['labor'][0];
            $m1RepairName = $firstLabor['component'] ?? $problem;

            $laborDetail = $firstLabor['detail'] ?? null;
            if ($laborDetail) {
                $hours = Mitchell1Client::extractLaborHours($laborDetail);
                $m1LaborHours = $hours[0]['standardTime'] ?? 0;
                $skillLevel = $laborDetail['skillLevel'] ?? '';
                $procedureNote = $laborDetail['applicationGroups'][0]['procedureNote'] ?? '';
            }
        }

        if (!empty($m1Result['parts'])) {
            foreach ($m1Result['parts'] as $p) {
                foreach (Mitchell1Client::extractParts($p['detail']) as $pt) {
                    $m1PartsCost += $pt['price'];
                }
            }
        }

        // Aftermarket estimate: 40-80% of OEM list price
        $aftermarketLow = round($m1PartsCost * 0.4, 2);
        $aftermarketHigh = round($m1PartsCost * 0.8, 2);
        $aftermarketMid = round(($aftermarketLow + $aftermarketHigh) / 2, 2);

        // 5. Build Mitchell1 reference text
        $m1Ref = "Mitchell1 ProDemand - {$year} {$make} {$model}\n";
        $m1Ref .= "Search: \"{$problem}\"\n";
        $m1Ref .= "Source: mitchell1\n";
        if ($skillLevel) $m1Ref .= "Skill Level: {$skillLevel}\n";
        if ($procedureNote) $m1Ref .= "Note: {$procedureNote}\n";
        $m1Ref .= "Components found: " . count($m1Result['labor']) . " labor, "
                . count($m1Result['parts']) . " parts\n";
        $m1Ref .= "OEM parts total: $" . round($m1PartsCost, 2);

        // 6. Update diagnostic record with M1 data
        $updates = [
            'field_' . CRMHelper::DIAG_M1_LABOR_HOURS => $m1LaborHours,
            'field_' . CRMHelper::DIAG_M1_PARTS_COST  => round($m1PartsCost, 2),
            'field_' . CRMHelper::DIAG_M1_REPAIR_NAME => $m1RepairName,
            'field_' . CRMHelper::DIAG_M1_RAW_DATA    => json_encode($m1Result, JSON_PRETTY_PRINT),
            'field_' . CRMHelper::DIAG_EST_HOURS       => $m1LaborHours, // Default to M1, Kyle can override
            'field_' . CRMHelper::DIAG_EST_PARTS       => $aftermarketMid, // Aftermarket midpoint
            'field_' . CRMHelper::DIAG_MITCHELL1_REF   => $m1Ref,
        ];

        $setParts = [];
        foreach ($updates as $field => $value) {
            $setParts[] = "{$field} = '" . $this->db->real_escape_string($value) . "'";
        }

        $diagEntity = CRMHelper::ENTITY_DIAGNOSTICS;
        $this->db->query(
            "UPDATE app_entity_{$diagEntity} SET "
            . implode(', ', $setParts)
            . " WHERE id = " . intval($diagId)
        );

        $this->log("M1 auto-lookup for diag #{$diagId} (job #{$jobId}): "
            . "{$m1LaborHours}hrs, \${$m1PartsCost} OEM parts, repair={$m1RepairName}");

        return [
            'source' => 'mitchell1',
            'labor_hours' => $m1LaborHours,
            'parts_cost_oem' => round($m1PartsCost, 2),
            'parts_cost_aftermarket' => $aftermarketMid,
            'repair_name' => $m1RepairName,
            'skill_level' => $skillLevel,
        ];
    }

    private function log(string $msg): void
    {
        $logDir = '/home/kylewee/logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        file_put_contents($logDir . '/mechanic.log',
            date('Y-m-d H:i:s') . " [DIAG] {$msg}\n", FILE_APPEND);
    }
}
