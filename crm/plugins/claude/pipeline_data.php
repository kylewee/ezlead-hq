<?php
/**
 * Pipeline Action Board - Data Endpoint
 * Returns JSON: jobs/estimates grouped by what action is needed.
 *
 * Sections:
 *   triage         - Jobs stage=82 (New Lead)
 *   awaiting_reply - Estimates status=206 (Sent) with no linked job yet
 *   needs_schedule - Jobs stage=84 (Accepted), no appointment set
 *   upcoming       - Jobs stage 85-87, appointment in next 7 days
 *   in_progress    - Jobs stage=88
 *   needs_invoice  - Jobs stage=89, payment_status=91 (Pending)
 *   awaiting_pay   - Jobs payment_status=92 (Invoice Sent)
 *   new_leads      - Website form leads (entity 25) needing triage
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../config/database.php');

$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($db->connect_error) {
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

$now = time();
$week_ahead = $now + 7 * 86400;

// Stage choice IDs (from field 362)
$STAGE_NEW_LEAD     = 82;
$STAGE_ESTIMATE_SENT = 83;
$STAGE_ACCEPTED     = 84;
$STAGE_SCHEDULED    = 85;
$STAGE_PARTS        = 86;
$STAGE_CONFIRMED    = 87;
$STAGE_IN_PROGRESS  = 88;
$STAGE_COMPLETE     = 89;
$STAGE_PAID         = 90;

// Payment status (field 371)
$PAY_PENDING      = 91;
$PAY_INVOICE_SENT = 92;
$PAY_PAID         = 93;

// Estimate status (field 519)
$EST_PENDING  = 205;
$EST_SENT     = 206;
$EST_ACCEPTED = 207;

// Helper: format job row into card data
function job_card($row) {
    $vehicle = trim(($row['year'] ?? '') . ' ' . ($row['make'] ?? '') . ' ' . ($row['model'] ?? ''));
    return [
        'id'      => (int)$row['id'],
        'name'    => $row['name'] ?: 'Unknown',
        'phone'   => $row['phone'] ?? '',
        'email'   => $row['email'] ?? '',
        'vehicle' => $vehicle ?: 'No vehicle info',
        'problem' => $row['problem'] ?? '',
        'total'   => floatval($row['total'] ?? 0),
        'stage'   => (int)($row['stage'] ?? 0),
        'payment' => (int)($row['payment'] ?? 0),
        'appt'    => (int)($row['appt'] ?? 0),
        'created' => $row['date_added'] ?? '',
        'updated' => $row['date_updated'] ?? '',
    ];
}

// --- TRIAGE: New Lead jobs ---
$triage = [];
$r = $db->query("SELECT id, field_354 as name, field_355 as phone, field_356 as email,
                         field_358 as year, field_359 as make, field_360 as model,
                         field_361 as problem, field_362 as stage, field_366 as total,
                         field_371 as payment, field_368 as appt, date_added, date_updated
                  FROM app_entity_42
                  WHERE field_362 = $STAGE_NEW_LEAD
                  ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $triage[] = job_card($row);

// --- AWAITING REPLY: Estimates sent, no job linked yet ---
$awaiting = [];
$r = $db->query("SELECT e.id, c.field_427 as name, c.field_428 as phone, c.field_429 as email,
                        v.field_434 as year, v.field_435 as make, v.field_436 as model,
                        e.field_520 as problem, e.field_525 as total_low, e.field_526 as total_high,
                        e.field_529 as job_id, e.date_added, e.date_updated
                 FROM app_entity_53 e
                 LEFT JOIN app_entity_47 c ON e.field_516 = c.id
                 LEFT JOIN app_entity_48 v ON e.field_517 = v.id
                 WHERE e.field_519 = $EST_SENT
                 AND (e.field_529 IS NULL OR e.field_529 = '' OR e.field_529 = '0')
                 ORDER BY e.id DESC");
if ($r) while ($row = $r->fetch_assoc()) {
    $vehicle = trim(($row['year'] ?? '') . ' ' . ($row['make'] ?? '') . ' ' . ($row['model'] ?? ''));
    $awaiting[] = [
        'id'        => (int)$row['id'],
        'name'      => $row['name'] ?: 'Unknown',
        'phone'     => $row['phone'] ?? '',
        'email'     => $row['email'] ?? '',
        'vehicle'   => $vehicle ?: 'No vehicle info',
        'problem'   => $row['problem'] ?? '',
        'total_low' => floatval($row['total_low'] ?? 0),
        'total_high'=> floatval($row['total_high'] ?? 0),
        'created'   => $row['date_added'] ?? '',
        'updated'   => $row['date_updated'] ?? '',
    ];
}

// --- NEEDS SCHEDULING: Accepted, no appointment ---
$needs_schedule = [];
$r = $db->query("SELECT id, field_354 as name, field_355 as phone, field_356 as email,
                        field_358 as year, field_359 as make, field_360 as model,
                        field_361 as problem, field_362 as stage, field_366 as total,
                        field_371 as payment, field_368 as appt, date_added, date_updated
                 FROM app_entity_42
                 WHERE field_362 = $STAGE_ACCEPTED
                 AND (field_368 IS NULL OR field_368 = 0)
                 ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $needs_schedule[] = job_card($row);

// --- UPCOMING: Scheduled/Parts/Confirmed with appointment in next 7 days ---
$upcoming = [];
$r = $db->query("SELECT id, field_354 as name, field_355 as phone, field_356 as email,
                        field_358 as year, field_359 as make, field_360 as model,
                        field_361 as problem, field_362 as stage, field_366 as total,
                        field_371 as payment, field_368 as appt, date_added, date_updated
                 FROM app_entity_42
                 WHERE field_362 IN ($STAGE_SCHEDULED, $STAGE_PARTS, $STAGE_CONFIRMED)
                 ORDER BY field_368 ASC");
if ($r) while ($row = $r->fetch_assoc()) $upcoming[] = job_card($row);

// --- IN PROGRESS ---
$in_progress = [];
$r = $db->query("SELECT id, field_354 as name, field_355 as phone, field_356 as email,
                        field_358 as year, field_359 as make, field_360 as model,
                        field_361 as problem, field_362 as stage, field_366 as total,
                        field_371 as payment, field_368 as appt, date_added, date_updated
                 FROM app_entity_42
                 WHERE field_362 = $STAGE_IN_PROGRESS
                 ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $in_progress[] = job_card($row);

// --- NEEDS INVOICE: Complete + payment pending ---
$needs_invoice = [];
$r = $db->query("SELECT id, field_354 as name, field_355 as phone, field_356 as email,
                        field_358 as year, field_359 as make, field_360 as model,
                        field_361 as problem, field_362 as stage, field_366 as total,
                        field_371 as payment, field_368 as appt, date_added, date_updated
                 FROM app_entity_42
                 WHERE field_362 = $STAGE_COMPLETE
                 AND field_371 = $PAY_PENDING
                 ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $needs_invoice[] = job_card($row);

// --- AWAITING PAYMENT: Invoice sent ---
$awaiting_pay = [];
$r = $db->query("SELECT id, field_354 as name, field_355 as phone, field_356 as email,
                        field_358 as year, field_359 as make, field_360 as model,
                        field_361 as problem, field_362 as stage, field_366 as total,
                        field_371 as payment, field_368 as appt, date_added, date_updated
                 FROM app_entity_42
                 WHERE field_371 = $PAY_INVOICE_SENT
                 ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) $awaiting_pay[] = job_card($row);

// --- NEW LEADS: Website form leads from mechanic sites ---
$new_leads = [];
$r = $db->query("SELECT id, field_210 as name, field_211 as phone, field_212 as email,
                        field_215 as source, field_217 as notes, date_added
                 FROM app_entity_25
                 WHERE field_268 = 75
                 AND field_215 LIKE '%mechanic%'
                 ORDER BY id DESC");
if ($r) while ($row = $r->fetch_assoc()) {
    $new_leads[] = [
        'id'      => (int)$row['id'],
        'name'    => $row['name'] ?: 'Unknown',
        'phone'   => $row['phone'] ?? '',
        'email'   => $row['email'] ?? '',
        'source'  => $row['source'] ?? '',
        'notes'   => $row['notes'] ?? '',
        'created' => $row['date_added'] ?? '',
    ];
}

// --- Stats ---
$total_jobs = $db->query("SELECT COUNT(*) as c FROM app_entity_42")->fetch_assoc()['c'];
$total_unpaid = $db->query("SELECT COUNT(*) as c FROM app_entity_42 WHERE field_371 IN ($PAY_PENDING, $PAY_INVOICE_SENT) AND field_366 > 0")->fetch_assoc()['c'];
$unpaid_total = $db->query("SELECT COALESCE(SUM(field_366),0) as t FROM app_entity_42 WHERE field_371 IN ($PAY_PENDING, $PAY_INVOICE_SENT) AND field_366 > 0")->fetch_assoc()['t'];

$db->close();

echo json_encode([
    'new_leads'      => $new_leads,
    'triage'         => $triage,
    'awaiting_reply' => $awaiting,
    'needs_schedule' => $needs_schedule,
    'upcoming'       => $upcoming,
    'in_progress'    => $in_progress,
    'needs_invoice'  => $needs_invoice,
    'awaiting_pay'   => $awaiting_pay,
    'stats' => [
        'total_jobs'   => (int)$total_jobs,
        'total_unpaid' => (int)$total_unpaid,
        'unpaid_total' => round((float)$unpaid_total, 2),
        'triage_count' => count($triage),
        'new_leads_count' => count($new_leads),
        'action_count' => count($new_leads) + count($triage) + count($awaiting) + count($needs_schedule) + count($needs_invoice) + count($awaiting_pay),
    ],
], JSON_PRETTY_PRINT);
