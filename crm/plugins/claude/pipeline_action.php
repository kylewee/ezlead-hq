<?php
/**
 * Pipeline Action Board - AJAX Action Endpoint
 * Handles compound pipeline actions via POST.
 *
 * Actions:
 *   accept_and_send  - Generate estimate + send email + advance to Estimate Sent (83)
 *   mark_junk        - Remove job from pipeline (delete or mark terminal)
 *   mark_accepted    - Set estimate to Accepted (207), cron creates Job
 *   mark_dead        - Set estimate to Declined (208)
 *   schedule_job     - Set appointment datetime + advance to Scheduled (85)
 *   start_job        - Advance to In Progress (88)
 *   complete_job     - Set final total + advance to Complete (89)
 *   send_invoice     - Trigger invoice send immediately (reuses mechanic_automation logic)
 *   mark_paid        - Set payment=Paid (93) + advance to Paid (90)
 *   update_field     - Generic field update (for inline editing name/phone)
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../config/database.php');

$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($db->connect_error) {
    echo json_encode(['ok' => false, 'error' => 'DB connection failed']);
    exit;
}
$db->set_charset('utf8');

$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);
$now    = time();

if (!$action) {
    echo json_encode(['ok' => false, 'error' => 'Missing action']);
    exit;
}

// Stage constants
$STAGE_NEW_LEAD      = 82;
$STAGE_ESTIMATE_SENT = 83;
$STAGE_ACCEPTED      = 84;
$STAGE_SCHEDULED     = 85;
$STAGE_IN_PROGRESS   = 88;
$STAGE_COMPLETE      = 89;
$STAGE_PAID          = 90;

// Payment constants
$PAY_PENDING      = 91;
$PAY_INVOICE_SENT = 92;
$PAY_PAID         = 93;

// Estimate status
$EST_ACCEPTED = 207;
$EST_DECLINED = 208;

switch ($action) {

    // ─── TRIAGE: Accept & Send Estimate ───
    case 'accept_and_send':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing job id']); break; }

        // Get job data
        $job = $db->query("SELECT * FROM app_entity_42 WHERE id = $id")->fetch_assoc();
        if (!$job) { echo json_encode(['ok' => false, 'error' => 'Job not found']); break; }

        $name    = $job['field_354'] ?? 'Unknown';
        $phone   = $job['field_355'] ?? '';
        $email   = $job['field_356'] ?? '';
        $year    = $job['field_358'] ?? '';
        $make    = $job['field_359'] ?? '';
        $model   = $job['field_360'] ?? '';
        $problem = $job['field_361'] ?? '';
        $vehicle = trim("$year $make $model");

        if (!$email && !$phone) {
            echo json_encode(['ok' => false, 'error' => 'No email or phone on this job']);
            break;
        }

        // Generate estimate if none exists
        $estimate_details = $job['field_367'] ?? '';
        if (empty($estimate_details) && $year && $make && $model && $problem) {
            // Use EstimateEngine
            $_SERVER['HTTP_HOST'] = 'mechanicstaugustine.com';
            require_once '/var/www/ezlead-platform/core/config/bootstrap.php';
            require_once '/var/www/ezlead-platform/core/lib/EstimateEngine.php';

            $est = EstimateEngine::estimate($year, $make, $model, $problem);
            $estimate_details = $est['procedure_note'] ?? '';
            $labor_hours = $est['labor_hours'] ?? 0;
            $labor_cost  = $est['labor_cost'] ?? 0;
            $parts_cost  = $est['parts_high'] ?? 0;
            $total_low   = $est['total_low'] ?? 0;
            $total_high  = $est['total_high'] ?? 0;

            // Update job with estimate data
            $stmt = $db->prepare("UPDATE app_entity_42 SET field_363=?, field_364=?, field_365=?, field_366=?, field_367=?, date_updated=? WHERE id=?");
            $stmt->bind_param('ddddsii', $labor_hours, $parts_cost, $labor_cost, $total_high, $estimate_details, $now, $id);
            $stmt->execute();
            $stmt->close();
        }

        // Generate PDF
        require_once '/var/www/ezlead-platform/lib/PDFGenerator.php';
        $pdfPath = PDFGenerator::estimate([
            'id' => $id, 'name' => $name, 'phone' => $phone, 'email' => $email,
            'year' => $year, 'make' => $make, 'model' => $model,
            'problem' => $problem, 'estimate' => $estimate_details,
        ]);
        $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';

        // Send email
        $sent_via = [];
        if ($email) {
            $pdfLink = $pdfUrl ? "<p><a href='$pdfUrl' style='background:#1e40af;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-block;'>View PDF Estimate</a></p>" : '';

            // Accept link
            require_once '/var/www/ezlead-platform/accept/token.php';
            $acceptToken = estimate_token($id);
            $acceptUrl = "https://mechanicstaugustine.com/accept/?type=estimate&id={$id}&token={$acceptToken}";
            $acceptBtn = "<p style='text-align:center;'><a href='$acceptUrl' style='background:#22c55e;color:white;padding:14px 28px;text-decoration:none;border-radius:6px;display:inline-block;font-size:18px;font-weight:bold;'>Approve Estimate</a></p>";

            $subject = "Your Vehicle Repair Estimate - $vehicle";
            $body = "<h2>Hello " . htmlspecialchars($name) . ",</h2>"
                . "<p>Thank you for contacting Ez Mobile Mechanic! Here's your estimate:</p>"
                . "<div style='background:#f5f5f5;padding:15px;border-radius:8px;margin:20px 0;'>"
                . "<h3>Vehicle: " . htmlspecialchars($vehicle) . "</h3>"
                . "<p><strong>Issue:</strong> " . htmlspecialchars($problem) . "</p><hr>"
                . "<pre style='white-space:pre-wrap;'>" . htmlspecialchars($estimate_details) . "</pre></div>"
                . $pdfLink
                . $acceptBtn
                . "<p>To schedule your repair, simply reply to this email or call us at (904) 706-6669.</p>"
                . "<p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>";
            $headers = "From: Ez Mobile Mechanic <kyle@mechanicstaugustine.com>\r\nReply-To: kyle@mechanicstaugustine.com\r\nContent-Type: text/html; charset=UTF-8\r\n";
            if (mail($email, $subject, $body, $headers)) $sent_via[] = 'email';
        }

        // Advance to Estimate Sent
        $db->query("UPDATE app_entity_42 SET field_362 = $STAGE_ESTIMATE_SENT, date_updated = $now WHERE id = $id");

        echo json_encode(['ok' => true, 'sent_via' => $sent_via, 'pdf_url' => $pdfUrl]);
        break;

    // ─── TRIAGE: Mark Junk ───
    case 'mark_junk':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); break; }
        // Delete the job record (junk leads aren't worth keeping)
        $db->query("DELETE FROM app_entity_42 WHERE id = $id");
        echo json_encode(['ok' => true]);
        break;

    // ─── AWAITING: Mark Estimate Accepted ───
    case 'mark_accepted':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing estimate id']); break; }
        // Set estimate to Accepted (207) — cron Block 1a will create the job
        $db->query("UPDATE app_entity_53 SET field_519 = $EST_ACCEPTED, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => true, 'note' => 'Estimate marked accepted. Job will be created on next cron run (5 min).']);
        break;

    // ─── AWAITING: Mark Dead ───
    case 'mark_dead':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing estimate id']); break; }
        $db->query("UPDATE app_entity_53 SET field_519 = $EST_DECLINED, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => true]);
        break;

    // ─── SCHEDULE JOB ───
    case 'schedule_job':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing job id']); break; }
        $datetime = $_POST['datetime'] ?? '';
        if (!$datetime) {
            // Default: tomorrow 9am
            $datetime = date('Y-m-d', strtotime('tomorrow')) . ' 09:00:00';
        }
        $ts = strtotime($datetime);
        if (!$ts) { echo json_encode(['ok' => false, 'error' => 'Invalid datetime']); break; }
        $db->query("UPDATE app_entity_42 SET field_362 = $STAGE_SCHEDULED, field_368 = $ts, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => true, 'appointment' => $ts, 'display' => date('D n/j g:ia', $ts)]);
        break;

    // ─── START JOB ───
    case 'start_job':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing job id']); break; }
        $db->query("UPDATE app_entity_42 SET field_362 = $STAGE_IN_PROGRESS, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => true]);
        break;

    // ─── COMPLETE JOB ───
    case 'complete_job':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing job id']); break; }
        $total = floatval($_POST['total'] ?? 0);
        if ($total > 0) {
            $db->query("UPDATE app_entity_42 SET field_362 = $STAGE_COMPLETE, field_366 = $total, date_updated = $now WHERE id = $id");
        } else {
            $db->query("UPDATE app_entity_42 SET field_362 = $STAGE_COMPLETE, date_updated = $now WHERE id = $id");
        }
        echo json_encode(['ok' => true]);
        break;

    // ─── SEND INVOICE ───
    case 'send_invoice':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing job id']); break; }
        // Reuse the mechanic_automation invoice logic inline
        $job = $db->query("SELECT * FROM app_entity_42 WHERE id = $id")->fetch_assoc();
        if (!$job) { echo json_encode(['ok' => false, 'error' => 'Job not found']); break; }

        $total = floatval($job['field_366']);
        $email = $job['field_356'] ?? '';
        $name  = $job['field_354'] ?? '';
        $vehicle = trim(($job['field_358'] ?? '') . ' ' . ($job['field_359'] ?? '') . ' ' . ($job['field_360'] ?? ''));

        if (!$email) { echo json_encode(['ok' => false, 'error' => 'No email on job']); break; }
        if ($total <= 0) { echo json_encode(['ok' => false, 'error' => 'No total on job']); break; }

        $subject = "Invoice: Mobile Mechanic Repair - $" . number_format($total, 2);
        $body = "<h2>Thank You For Your Business!</h2>"
            . "<p>Hello " . htmlspecialchars($name) . ",</p>"
            . "<p>Your repair has been completed on your " . htmlspecialchars($vehicle) . ".</p>"
            . "<div style='background:#fff3e0;padding:20px;border-radius:8px;margin:20px 0;text-align:center;'>"
            . "<h2 style='color:#e65100;'>Total Due: $" . number_format($total, 2) . "</h2></div>"
            . "<p>We accept cash, card, Venmo, or Zelle.</p>"
            . "<p>Thanks for choosing Ez Mobile Mechanic!</p>";
        $headers = "From: Ez Mobile Mechanic <kyle@mechanicstaugustine.com>\r\nContent-Type: text/html; charset=UTF-8\r\n";
        $sent = mail($email, $subject, $body, $headers);

        $db->query("UPDATE app_entity_42 SET field_371 = $PAY_INVOICE_SENT, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => $sent]);
        break;

    // ─── MARK PAID ───
    case 'mark_paid':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing job id']); break; }
        $db->query("UPDATE app_entity_42 SET field_371 = $PAY_PAID, field_362 = $STAGE_PAID, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => true]);
        break;

    // ─── UPDATE FIELD (inline edit) ───
    case 'update_field':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); break; }
        $entity = (int)($_POST['entity'] ?? 42);
        $field  = $_POST['field'] ?? '';
        $value  = $_POST['value'] ?? '';

        // Whitelist allowed fields for safety
        $allowed = [
            42 => ['field_354', 'field_355', 'field_356', 'field_357', 'field_361', 'field_366'],
        ];
        if (!isset($allowed[$entity]) || !in_array($field, $allowed[$entity])) {
            echo json_encode(['ok' => false, 'error' => 'Field not allowed']);
            break;
        }

        $table = "app_entity_$entity";
        $stmt = $db->prepare("UPDATE $table SET $field = ?, date_updated = ? WHERE id = ?");
        $stmt->bind_param('sii', $value, $now, $id);
        $ok = $stmt->execute();
        $stmt->close();
        echo json_encode(['ok' => $ok]);
        break;

    // ─── CONVERT LEAD TO JOB ───
    case 'convert_lead':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing lead id']); break; }

        $lead = $db->query("SELECT * FROM app_entity_25 WHERE id = $id")->fetch_assoc();
        if (!$lead) { echo json_encode(['ok' => false, 'error' => 'Lead not found']); break; }

        $jobFields = [
            'field_354' => $lead['field_210'] ?? 'Unknown',
            'field_355' => $lead['field_211'] ?? '',
            'field_356' => $lead['field_212'] ?? '',
            'field_357' => '',
            'field_358' => $_POST['year'] ?? '',
            'field_359' => $_POST['make'] ?? '',
            'field_360' => $_POST['model'] ?? '',
            'field_361' => $_POST['problem'] ?? '',
            'field_362' => $STAGE_NEW_LEAD,
            'field_367' => '',
            'field_369' => '',
            'field_371' => $PAY_PENDING,
            'field_372' => 'Converted from Lead #' . $id,
            'field_374' => '',
            'field_475' => 2,
        ];

        $sqlCols = [];
        $sqlVals = [];
        foreach ($jobFields as $field => $value) {
            $sqlCols[] = $field;
            $sqlVals[] = "'" . $db->real_escape_string((string)$value) . "'";
        }

        $db->query("INSERT INTO app_entity_42 (" . implode(', ', $sqlCols) . ", date_added, created_by, parent_item_id, sort_order)
                     VALUES (" . implode(', ', $sqlVals) . ", NOW(), 0, 0, 0)");
        $jobId = $db->insert_id;

        if ($jobId) {
            $db->query("UPDATE app_entity_25 SET field_268 = 78, date_updated = $now WHERE id = $id");
            echo json_encode(['ok' => true, 'job_id' => $jobId]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Insert failed: ' . $db->error]);
        }
        break;

    // ─── DISMISS LEAD ───
    case 'dismiss_lead':
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing lead id']); break; }
        $db->query("UPDATE app_entity_25 SET field_268 = 216, date_updated = $now WHERE id = $id");
        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => "Unknown action: $action"]);
}

$db->close();
