# Mechanic Pipeline Action Board - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Build a single-page "Pipeline" dashboard in the CRM that shows every mechanic job/estimate needing attention, with one-click action buttons to advance the pipeline.

**Architecture:** New iPage (ID 8) with PHP data endpoint + vanilla JS frontend, following the Mission Control pattern (mc_data.php + mc3.js). AJAX action endpoint handles compound pipeline actions (accept+send, schedule, complete, mark paid). No new entities, fields, or cron jobs.

**Tech Stack:** PHP 8.3, vanilla JS, jQuery (already loaded by CRM), existing CRM database.

---

### Task 1: Create pipeline_data.php — JSON data endpoint

**Files:**
- Create: `crm/plugins/claude/pipeline_data.php`

**Step 1: Create the data endpoint**

This file queries the database and returns JSON grouped by pipeline section. Pattern follows `mc_data.php`.

```php
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

// --- Stats ---
$total_jobs = $db->query("SELECT COUNT(*) as c FROM app_entity_42")->fetch_assoc()['c'];
$total_unpaid = $db->query("SELECT COUNT(*) as c FROM app_entity_42 WHERE field_371 IN ($PAY_PENDING, $PAY_INVOICE_SENT) AND field_366 > 0")->fetch_assoc()['c'];
$unpaid_total = $db->query("SELECT COALESCE(SUM(field_366),0) as t FROM app_entity_42 WHERE field_371 IN ($PAY_PENDING, $PAY_INVOICE_SENT) AND field_366 > 0")->fetch_assoc()['t'];

$db->close();

echo json_encode([
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
        'action_count' => count($triage) + count($awaiting) + count($needs_schedule) + count($needs_invoice) + count($awaiting_pay),
    ],
], JSON_PRETTY_PRINT);
```

**Step 2: Test the endpoint**

Run: `cd /var/www/ezlead-hq/crm && php plugins/claude/pipeline_data.php 2>&1 | head -30`

Expected: JSON output with sections. `triage` should have ~20 records (the stuck New Lead jobs). Verify no PHP errors.

**Step 3: Commit**

```bash
git add crm/plugins/claude/pipeline_data.php
git commit -m "feat: pipeline action board data endpoint"
```

---

### Task 2: Create pipeline_action.php — AJAX action endpoint

**Files:**
- Create: `crm/plugins/claude/pipeline_action.php`

**Dependencies:** Requires EstimateEngine and PDFGenerator from ezlead-platform for the `accept_and_send` action. Uses same patterns as `ajax_estimate.php`.

**Step 1: Create the action endpoint**

```php
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
            $subject = "Your Vehicle Repair Estimate - $vehicle";
            $body = "<h2>Hello " . htmlspecialchars($name) . ",</h2>"
                . "<p>Thank you for contacting Ez Mobile Mechanic! Here's your estimate:</p>"
                . "<div style='background:#f5f5f5;padding:15px;border-radius:8px;margin:20px 0;'>"
                . "<h3>Vehicle: " . htmlspecialchars($vehicle) . "</h3>"
                . "<p><strong>Issue:</strong> " . htmlspecialchars($problem) . "</p><hr>"
                . "<pre style='white-space:pre-wrap;'>" . htmlspecialchars($estimate_details) . "</pre></div>"
                . $pdfLink
                . "<p>To schedule your repair, simply reply to this email or call us at (904) 706-6669.</p>"
                . "<p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>";
            $headers = "From: Ez Mobile Mechanic <noreply@mechanicstaugustine.com>\r\nReply-To: noreply@mechanicstaugustine.com\r\nContent-Type: text/html; charset=UTF-8\r\n";
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
        $headers = "From: Ez Mobile Mechanic <noreply@mechanicstaugustine.com>\r\nContent-Type: text/html; charset=UTF-8\r\n";
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

    default:
        echo json_encode(['ok' => false, 'error' => "Unknown action: $action"]);
}

$db->close();
```

**Step 2: Test key actions**

Run: `cd /var/www/ezlead-hq/crm && php -r "
\$_POST = ['action' => 'mark_paid', 'id' => '0'];
\$_SERVER['REQUEST_METHOD'] = 'POST';
include 'plugins/claude/pipeline_action.php';
"`

Expected: `{"ok":false,"error":"Missing job id"}`

**Step 3: Commit**

```bash
git add crm/plugins/claude/pipeline_action.php
git commit -m "feat: pipeline action board AJAX action endpoint"
```

---

### Task 3: Create pipeline.js — Frontend

**Files:**
- Create: `crm/plugins/claude/pipeline.js`

**Step 1: Create the frontend JavaScript**

This follows the mc3.js pattern: IIFE, jQuery for AJAX, renders into `#pipeline` container.

```javascript
(function() {
    var DATA_URL = 'plugins/claude/pipeline_data.php';
    var ACTION_URL = 'plugins/claude/pipeline_action.php';
    var CRM_BASE = window.location.pathname.replace(/\/index\.php.*/, '/index.php');

    // Section definitions: key, label, icon, color, actions
    var SECTIONS = [
        { key: 'triage',         label: 'Triage',           icon: 'fa-inbox',        color: '#e74c3c', entity: 'job' },
        { key: 'awaiting_reply', label: 'Awaiting Reply',   icon: 'fa-clock-o',      color: '#f39c12', entity: 'estimate' },
        { key: 'needs_schedule', label: 'Needs Scheduling', icon: 'fa-calendar-plus-o', color: '#3498db', entity: 'job' },
        { key: 'upcoming',       label: 'Upcoming',         icon: 'fa-calendar',     color: '#2ecc71', entity: 'job' },
        { key: 'in_progress',    label: 'In Progress',      icon: 'fa-wrench',       color: '#9b59b6', entity: 'job' },
        { key: 'needs_invoice',  label: 'Needs Invoice',    icon: 'fa-file-text-o',  color: '#e67e22', entity: 'job' },
        { key: 'awaiting_pay',   label: 'Awaiting Payment', icon: 'fa-usd',          color: '#27ae60', entity: 'job' },
    ];

    function timeAgo(dateStr) {
        if (!dateStr) return '';
        var ts = parseInt(dateStr);
        if (isNaN(ts)) ts = new Date(dateStr).getTime() / 1000;
        var diff = Math.floor(Date.now() / 1000) - ts;
        if (diff < 60) return 'just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        if (diff < 604800) return Math.floor(diff / 86400) + 'd ago';
        return Math.floor(diff / 604800) + 'w ago';
    }

    function fmt$(n) {
        if (!n) return '';
        return '$' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function apptStr(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000);
        var days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        var h = d.getHours();
        var ampm = h >= 12 ? 'pm' : 'am';
        h = h % 12 || 12;
        return days[d.getDay()] + ' ' + (d.getMonth()+1) + '/' + d.getDate() + ' ' + h + ampm;
    }

    function postAction(action, id, extra, callback) {
        var data = jQuery.extend({ action: action, id: id }, extra || {});
        jQuery.post(ACTION_URL, data, function(resp) {
            if (resp.ok) {
                if (callback) callback(resp);
                else loadData();
            } else {
                alert('Error: ' + (resp.error || 'Unknown'));
            }
        }, 'json').fail(function() { alert('Request failed'); });
    }

    function renderCard(section, item) {
        var card = document.createElement('div');
        card.className = 'pl-card';
        card.dataset.id = item.id;

        // Header line: name + phone
        var phone = item.phone ? '<a href="tel:' + item.phone + '" class="pl-phone">' + item.phone + '</a>' : '';
        var header = '<div class="pl-card-header">'
            + '<span class="pl-name">' + esc(item.name) + '</span>'
            + phone
            + '</div>';

        // Detail line: vehicle + problem
        var problem = item.problem ? ' &bull; ' + esc(item.problem.substring(0, 60)) : '';
        var detail = '<div class="pl-card-detail">'
            + esc(item.vehicle) + problem
            + '</div>';

        // Meta line: price + time
        var price = '';
        if (section.key === 'awaiting_reply') {
            if (item.total_low || item.total_high) price = fmt$(item.total_low) + '–' + fmt$(item.total_high);
        } else if (item.total) {
            price = fmt$(item.total);
        }
        var appt = item.appt ? apptStr(item.appt) : '';
        var age = timeAgo(item.updated || item.created);
        var metaParts = [price, appt, age].filter(Boolean);
        var meta = metaParts.length ? '<div class="pl-card-meta">' + metaParts.join(' &bull; ') + '</div>' : '';

        // Actions
        var actions = '<div class="pl-card-actions">' + getActions(section, item) + '</div>';

        card.innerHTML = header + detail + meta + actions;

        // Wire up action buttons
        card.querySelectorAll('[data-action]').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                handleAction(btn, section, item);
            });
        });

        return card;
    }

    function esc(s) {
        if (!s) return '';
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function getActions(section, item) {
        switch (section.key) {
            case 'triage':
                return '<button data-action="accept_and_send" class="pl-btn pl-btn-primary">Accept & Send</button>'
                    + '<button data-action="mark_junk" class="pl-btn pl-btn-danger">Junk</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'awaiting_reply':
                return '<button data-action="mark_accepted" class="pl-btn pl-btn-primary">Accepted</button>'
                    + '<button data-action="mark_dead" class="pl-btn pl-btn-muted">Dead</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=53-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'needs_schedule':
                return '<button data-action="schedule_tomorrow" class="pl-btn pl-btn-primary">Tomorrow 9am</button>'
                    + '<button data-action="schedule_pick" class="pl-btn pl-btn-secondary">Pick Date</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'upcoming':
                return '<button data-action="start_job" class="pl-btn pl-btn-primary">Start Job</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'in_progress':
                return '<button data-action="complete_job" class="pl-btn pl-btn-primary">Complete</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'needs_invoice':
                return '<button data-action="send_invoice" class="pl-btn pl-btn-primary">Send Invoice ' + fmt$(item.total) + '</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            case 'awaiting_pay':
                return '<button data-action="mark_paid" class="pl-btn pl-btn-primary">Mark Paid ' + fmt$(item.total) + '</button>'
                    + '<a href="' + CRM_BASE + '?module=items/info&path=42-' + item.id + '" class="pl-btn pl-btn-link">View</a>';
            default:
                return '';
        }
    }

    function handleAction(btn, section, item) {
        var action = btn.dataset.action;
        btn.disabled = true;
        btn.textContent = '...';

        switch (action) {
            case 'accept_and_send':
            case 'mark_junk':
            case 'mark_accepted':
            case 'mark_dead':
            case 'start_job':
            case 'send_invoice':
            case 'mark_paid':
                postAction(action, item.id, {}, function() { loadData(); });
                break;

            case 'schedule_tomorrow':
                postAction('schedule_job', item.id, {}, function() { loadData(); });
                break;

            case 'schedule_pick':
                var dt = prompt('Enter date/time (YYYY-MM-DD HH:MM):', '');
                if (dt) {
                    postAction('schedule_job', item.id, { datetime: dt }, function() { loadData(); });
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Pick Date';
                }
                break;

            case 'complete_job':
                var total = prompt('Final total ($):', item.total || '');
                if (total !== null) {
                    postAction('complete_job', item.id, { total: total }, function() { loadData(); });
                } else {
                    btn.disabled = false;
                    btn.textContent = 'Complete';
                }
                break;

            default:
                btn.disabled = false;
        }
    }

    function renderSection(section, items) {
        if (!items || items.length === 0) return '';

        var el = document.createElement('div');
        el.className = 'pl-section';
        el.innerHTML = '<div class="pl-section-header" style="border-left: 4px solid ' + section.color + '">'
            + '<i class="fa ' + section.icon + '" style="color:' + section.color + '"></i> '
            + '<span class="pl-section-label">' + section.label + '</span>'
            + '<span class="pl-section-count">' + items.length + '</span>'
            + '</div>';

        var body = document.createElement('div');
        body.className = 'pl-section-body';
        items.forEach(function(item) {
            body.appendChild(renderCard(section, item));
        });
        el.appendChild(body);

        return el;
    }

    function render(data) {
        var container = document.getElementById('pl-content');
        container.innerHTML = '';

        // Stats bar
        var stats = data.stats || {};
        var statsEl = document.createElement('div');
        statsEl.className = 'pl-stats';
        statsEl.innerHTML = '<div class="pl-stat"><span class="pl-stat-num">' + (stats.action_count || 0) + '</span><span class="pl-stat-label">Need Action</span></div>'
            + '<div class="pl-stat"><span class="pl-stat-num">' + (stats.triage_count || 0) + '</span><span class="pl-stat-label">To Triage</span></div>'
            + '<div class="pl-stat"><span class="pl-stat-num">' + (stats.total_unpaid || 0) + '</span><span class="pl-stat-label">Unpaid</span></div>'
            + '<div class="pl-stat"><span class="pl-stat-num">' + fmt$(stats.unpaid_total) + '</span><span class="pl-stat-label">Owed</span></div>';
        container.appendChild(statsEl);

        // Sections
        var hasContent = false;
        SECTIONS.forEach(function(section) {
            var items = data[section.key] || [];
            if (items.length > 0) {
                container.appendChild(renderSection(section, items));
                hasContent = true;
            }
        });

        if (!hasContent) {
            container.innerHTML += '<div class="pl-empty">Pipeline clear. No jobs need attention right now.</div>';
        }

        // Refresh timestamp
        document.getElementById('pl-refresh').textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    function loadData() {
        jQuery.getJSON(DATA_URL, function(data) {
            if (data.error) {
                document.getElementById('pl-content').innerHTML = '<div class="pl-error">' + data.error + '</div>';
                return;
            }
            render(data);
        }).fail(function() {
            document.getElementById('pl-content').innerHTML = '<div class="pl-error">Failed to load pipeline data</div>';
        });
    }

    // Initial load
    loadData();

    // Auto-refresh every 60 seconds
    setInterval(loadData, 60000);

    // Manual refresh button
    var refreshBtn = document.getElementById('pl-refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() { loadData(); });
    }
})();
```

**Step 2: Verify no syntax errors**

Run: `node --check /var/www/ezlead-hq/crm/plugins/claude/pipeline.js`

Expected: No output (no syntax errors)

**Step 3: Commit**

```bash
git add crm/plugins/claude/pipeline.js
git commit -m "feat: pipeline action board frontend"
```

---

### Task 4: Create iPage and CSS — wire it all together

**Files:**
- Modify: database `app_ext_ipages` table (INSERT for iPage 8)
- Modify: `crm/plugins/claude/sidebar.php` (add Pipeline link)

**Step 1: Insert the iPage with HTML/CSS container**

Run this SQL to create iPage 8:

```sql
INSERT INTO app_ext_ipages (id, parent_id, name, short_name, menu_icon, icon_color, bg_color, description, html_code, users_groups, assigned_to, sort_order, is_menu, attachments)
VALUES (8, 0, 'Pipeline', 'pipeline', 'fa-road', '#e74c3c', '', '', '
<style>
#pipeline{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:960px;margin:0 auto;padding:15px}
#pipeline h2{margin:0 0 5px;font-size:22px;display:flex;align-items:center;gap:10px}
#pipeline h2 .pl-refresh-link{font-size:13px;color:#888;cursor:pointer;margin-left:auto;font-weight:normal}
#pipeline h2 .pl-refresh-link:hover{color:#333}
.pl-stats{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.pl-stat{background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px 16px;flex:1;min-width:100px;text-align:center}
.pl-stat-num{display:block;font-size:24px;font-weight:700;color:#333}
.pl-stat-label{font-size:11px;color:#888;text-transform:uppercase;letter-spacing:0.5px}
.pl-section{margin-bottom:16px}
.pl-section-header{display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f8f9fa;border-radius:6px;font-weight:600;font-size:14px}
.pl-section-count{background:#e9ecef;color:#666;font-size:12px;padding:1px 8px;border-radius:10px;margin-left:auto}
.pl-section-body{padding:4px 0}
.pl-card{display:flex;flex-direction:column;padding:12px 14px;border-bottom:1px solid #f0f0f0;gap:4px}
.pl-card:last-child{border-bottom:none}
.pl-card:hover{background:#fafbfc}
.pl-card-header{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pl-name{font-weight:600;font-size:14px;color:#333}
.pl-phone{font-size:13px;color:#3498db;text-decoration:none;margin-left:auto}
.pl-phone:hover{text-decoration:underline}
.pl-card-detail{font-size:13px;color:#666}
.pl-card-meta{font-size:12px;color:#999}
.pl-card-actions{display:flex;gap:6px;margin-top:4px;flex-wrap:wrap}
.pl-btn{padding:4px 12px;font-size:12px;border:1px solid #ddd;border-radius:4px;background:#fff;cursor:pointer;text-decoration:none;color:#555;white-space:nowrap;display:inline-block;line-height:1.5}
.pl-btn:hover{background:#f0f0f0}
.pl-btn:disabled{opacity:0.5;cursor:not-allowed}
.pl-btn-primary{background:#3498db;color:#fff;border-color:#3498db}
.pl-btn-primary:hover{background:#2980b9}
.pl-btn-secondary{background:#fff;color:#3498db;border-color:#3498db}
.pl-btn-danger{background:#fff;color:#e74c3c;border-color:#e74c3c}
.pl-btn-danger:hover{background:#e74c3c;color:#fff}
.pl-btn-muted{color:#999;border-color:#ddd}
.pl-btn-link{border:none;background:none;color:#3498db;padding:4px 8px}
.pl-btn-link:hover{text-decoration:underline;background:none}
.pl-empty{text-align:center;color:#999;padding:40px;font-size:16px}
.pl-error{text-align:center;color:#e74c3c;padding:20px}
#pl-refresh{color:#888;font-size:11px;text-align:right;margin-top:10px}
@media(max-width:600px){#pipeline{padding:10px}.pl-stats{flex-direction:column}.pl-card-actions{flex-wrap:wrap}.pl-phone{margin-left:0}}
</style>
<div id="pipeline">
<h2><i class="fa fa-road" style="color:#e74c3c"></i> Pipeline <span class="pl-refresh-link" id="pl-refresh-btn">Refresh</span></h2>
<div id="pl-content">Loading...</div>
<div id="pl-refresh"></div>
</div>
<script src="plugins/claude/pipeline.js"></script>
', '0', '', -2, 0, '');
```

Note: `sort_order = -2` puts it before Mission Control (-1) in iPage listing. `users_groups = '0'` gives access to all users.

**Step 2: Verify the iPage was created**

Run: `mysql -N -e "SELECT id, name FROM app_ext_ipages WHERE id=8" rukovoditel`

Expected: `8	Pipeline`

**Step 3: Add Pipeline to the sidebar**

In `crm/plugins/claude/sidebar.php`, add a Pipeline link as the second nav item (right after Dashboard). Find this block:

```php
    <li class="<?= is_active('dashboard') ?>">
        <a href="index.php?module=dashboard">
            <i class="fa fa-home"></i>
            <span class="title">Dashboard</span>
        </a>
    </li>
```

Add immediately after it:

```php
    <li class="<?= is_active('ext/ipages/view') && ($_GET['id'] ?? '') == '8' ? ' active' : '' ?>">
        <a href="index.php?module=ext/ipages/view&id=8">
            <i class="fa fa-road" style="color:#e74c3c"></i>
            <span class="title">Pipeline</span>
        </a>
    </li>
```

**Step 4: Commit**

```bash
git add crm/plugins/claude/sidebar.php
git commit -m "feat: pipeline iPage + sidebar link"
```

---

### Task 5: Smoke test the full pipeline board

**Step 1: Load the Pipeline page in a browser**

Navigate to: `https://ezlead4u.com/crm/index.php?module=ext/ipages/view&id=8`

**Expected:**
- Stats bar at top showing counts
- "Triage" section with ~20 New Lead jobs
- Cards showing name, phone, vehicle, problem
- "Accept & Send" and "Junk" buttons on triage cards
- Other sections may be empty (which is correct — nothing is in those stages yet)

**Step 2: Test the Accept & Send action on a test record**

Click "Accept & Send" on any triage card with an email address. Verify:
1. Button shows "..." while processing
2. Page refreshes and the card moves from Triage to a different section (or disappears if estimate was sent)
3. Check the job in CRM — stage should be 83 (Estimate Sent)

**Step 3: Test Mark Junk on a junk record**

Click "Junk" on any "Unknown" lead. Verify the card disappears and the record is deleted.

**Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix: pipeline action board smoke test fixes"
```

---

### Task 6: (Optional) Add quick-action buttons to Jobs listing

**Files:**
- Modify: `crm/plugins/claude/sidebar.php` (add JS injection for entity 42 listing)

**Step 1: Add an "Advance" button to each row**

In the existing quick-actions section of `sidebar.php` (around line 170), inside the `case 42:` block, add an additional action:

```php
$qa_actions[] = ['label' => 'Open Pipeline', 'icon' => 'fa-road', 'url' => 'index.php?module=ext/ipages/view&id=8'];
```

This adds a "Pipeline" link in the Jobs listing dropdown, giving users a quick way to jump to the action board from the standard listing.

**Step 2: Commit**

```bash
git add crm/plugins/claude/sidebar.php
git commit -m "feat: add Pipeline link to Jobs listing dropdown"
```

---

## Summary

| Task | File | Purpose |
|------|------|---------|
| 1 | `pipeline_data.php` | JSON endpoint: jobs grouped by action needed |
| 2 | `pipeline_action.php` | AJAX endpoint: 10 pipeline actions |
| 3 | `pipeline.js` | Frontend: cards + buttons + auto-refresh |
| 4 | iPage 8 + sidebar.php | CSS container + navigation |
| 5 | Smoke test | Verify end-to-end |
| 6 | sidebar.php | Optional: listing page shortcut |
