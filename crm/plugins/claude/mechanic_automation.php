<?php
/**
 * Mechanic Jobs Automation
 * Cron: Every 5 min - see crontab for exact schedule
 *
 * All customer-facing messages go through Kyle's approval gate.
 * Uses Rukovoditel's items::update_by_id() so CRM email rules,
 * SMS rules, and process automations fire on stage changes.
 *
 * Timeline after job completion:
 *   Paid → 1 day → check-in ("how's everything?") → 1 day → review request
 *
 * Estimate follow-up:
 *   Sent → 2 days no response → nudge ("have you decided?")
 */

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/claude_api.php');
require_once(__DIR__ . '/../../config/database.php');

// Load Rukovoditel core so we can use items::update_by_id() etc.
// This fires email rules, SMS rules, and process automations on changes.
$crm_root = realpath(__DIR__ . '/../../');
if (!defined('IS_CRON')) define('IS_CRON', true);
chdir($crm_root);
require_once($crm_root . '/includes/application_core.php');
if (is_file($crm_root . '/includes/languages/' . CFG_APP_LANGUAGE)) {
    require_once($crm_root . '/includes/languages/' . CFG_APP_LANGUAGE);
}
if (is_file($crm_root . '/plugins/ext/languages/' . CFG_APP_LANGUAGE)) {
    require_once($crm_root . '/plugins/ext/languages/' . CFG_APP_LANGUAGE);
}
$app_users_cache = users::get_cache();

/**
 * Update a CRM record using Rukovoditel's built-in function.
 * This fires email rules, SMS modules, and process automations.
 */
function crm_update(int $entity_id, int $item_id, array $data, array $settings = []): bool {
    return items::update_by_id($entity_id, $item_id, $data, $settings) !== false;
}

/**
 * Insert a CRM record using Rukovoditel's built-in function.
 */
function crm_insert(int $entity_id, array $data): int {
    $result = items::insert($entity_id, $data);
    return $result ? (int)$result : 0;
}

// PDF & Stripe integration
require_once '/var/www/ezlead-platform/lib/PDFGenerator.php';
require_once '/var/www/ezlead-platform/lib/StripeClient.php';

// Diagnostic workflow
require_once __DIR__ . '/DiagnosticService.php';

// Stripe config - load from .env
$envFile = '/var/www/ezlead-platform/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $GLOBALS[trim($k)] = trim($v);
    }
}
$GLOBALS['STRIPE_SECRET_KEY'] = $GLOBALS['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
$stripe = new StripeClient($GLOBALS['STRIPE_SECRET_KEY'] ?? null);

// Direct connection to rukovoditel database
$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die("Database connection failed\n");
}

// Get stage choice IDs from database
$stage_ids = [];
$result = $conn->query("SELECT id, name FROM app_fields_choices WHERE fields_id = 362 ORDER BY sort_order");
while ($row = $result->fetch_assoc()) {
    $key = strtolower(str_replace(' ', '_', $row['name']));
    $stage_ids[$key] = $row['id'];
}

$from_email = "kyle@mechanicstaugustine.com";
$from_name = "Ez Mobile Mechanic - St. Augustine";
$business_email = "kyle@ezlead4u.com"; // Your email for notifications
$kyle_phone = '+19046156899'; // Kyle's cell for approval texts

// ============================================
// PENDING MESSAGES TABLE (approval gate)
// All customer-facing messages go through Kyle first.
// ============================================
$conn->query("CREATE TABLE IF NOT EXISTS pending_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_id INT NOT NULL,
    msg_type VARCHAR(50) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    recipient VARCHAR(255) NOT NULL,
    subject VARCHAR(255) DEFAULT '',
    body TEXT NOT NULL,
    status VARCHAR(20) DEFAULT 'pending',
    created_at INT NOT NULL,
    sent_at INT DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/**
 * Queue a message for Kyle's approval instead of sending directly.
 * Texts Kyle a preview. He replies "A <id>" to approve, or "D <id>" to deny.
 *
 * @param string $type   e.g. 'estimate', 'invoice', 'checkin', 'review', 'reminder', 'nudge'
 * @param int    $jobId  Mechanic Job ID
 * @param string $channel 'sms' or 'email'
 * @param string $recipient Phone or email
 * @param string $subject Email subject (empty for SMS)
 * @param string $body   Message body
 * @param string $customerName For the preview text to Kyle
 * @return int  Pending message ID
 */
/** Sanitize customer name — "Unknown" or empty becomes "there" for SMS greetings */
function friendly_name($name) {
    return (empty($name) || strtolower(trim($name)) === 'unknown') ? 'there' : $name;
}

function queue_message($type, $jobId, $channel, $recipient, $subject, $body, $customerName = '') {
    global $conn, $kyle_phone;

    $stmt = $conn->prepare("INSERT INTO pending_messages (job_id, msg_type, channel, recipient, subject, body, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $now = time();
    $stmt->bind_param('isssssi', $jobId, $type, $channel, $recipient, $subject, $body, $now);
    $stmt->execute();
    $msgId = $conn->insert_id;
    $stmt->close();

    // Build preview for Kyle
    $preview = strtoupper($type) . " #$msgId (Job #$jobId)";
    if ($customerName) $preview .= "\nTo: $customerName";
    $preview .= "\nVia: $channel → $recipient";
    if ($channel === 'sms') {
        $preview .= "\n\n" . substr($body, 0, 120);
    } else {
        $preview .= "\nSubj: $subject";
    }
    $preview .= "\n\nReply: A $msgId = send, D $msgId = deny";

    // Text Kyle
    send_sms_direct($kyle_phone, $preview);

    return $msgId;
}

/**
 * Send email using PHP mail() — called directly only for Kyle notifications,
 * or when releasing an approved message.
 */
function send_email($to, $subject, $body, $from_email, $from_name) {
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    return mail($to, $subject, $body, $headers);
}

/**
 * Send SMS directly (no approval gate). Used for Kyle notifications
 * and for releasing approved messages.
 */
function send_sms_direct($to, $message) {
    $project_id = SIGNALWIRE_PROJECT_ID;
    $api_token  = SIGNALWIRE_API_TOKEN;
    $space      = SIGNALWIRE_SPACE_URL;
    $from       = SIGNALWIRE_FROM_NUMBER;

    // Normalize to +1XXXXXXXXXX
    $digits = preg_replace('/\D/', '', $to);
    if (strlen($digits) === 10) $digits = '1' . $digits;
    if (strlen($digits) !== 11 || $digits[0] !== '1') return false;
    $to = '+' . $digits;

    $url = "https://{$space}/api/laml/2010-04-01/Accounts/{$project_id}/Messages.json";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => "{$project_id}:{$api_token}",
        CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $to, 'Body' => $message]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "  SMS to $to: HTTP $http_code" . ($http_code === 201 ? " SENT" : " FAILED: $resp") . "\n";
    return $http_code === 201;
}

/** Alias - old code calling send_sms() still works for Kyle-facing messages */
function send_sms($to, $message) { return send_sms_direct($to, $message); }

/**
 * Process approved messages — called by SMS reply handler or can be run standalone.
 * Finds approved pending_messages and actually sends them.
 */
function process_approved_messages($conn) {
    global $from_email, $from_name;

    $result = $conn->query("SELECT * FROM pending_messages WHERE status = 'approved' LIMIT 20");
    $sent = 0;
    while ($row = $result->fetch_assoc()) {
        $ok = false;
        if ($row['channel'] === 'sms') {
            $ok = send_sms_direct($row['recipient'], $row['body']);
        } elseif ($row['channel'] === 'email') {
            $ok = send_email($row['recipient'], $row['subject'], $row['body'], $from_email, $from_name);
        }
        if ($ok) {
            $conn->query("UPDATE pending_messages SET status = 'sent', sent_at = " . time() . " WHERE id = " . (int)$row['id']);
            $sent++;
            echo "  Approved message #{$row['id']} sent ({$row['channel']} to {$row['recipient']})\n";
        }
    }
    return $sent;
}

// Process any previously approved messages first
echo "Processing approved messages...\n";
$approved_sent = process_approved_messages($conn);

/**
 * Generate auto-estimate using OpenAI GPT-4o-mini (primary)
 * Claude/Anthropic API disabled - no credits on account
 */
function generate_estimate($year, $make, $model, $problem) {
    $prompt = "You are an experienced mobile mechanic. Generate a repair estimate for:
Vehicle: $year $make $model
Problem: $problem

Provide:
1. Likely diagnosis
2. Estimated labor hours (rate: \$150 first hour, \$100/hour after)
3. Estimated parts cost range
4. Total estimate range (low to high)

Be concise and professional. Format for email.";

    // Primary: OpenAI GPT-4o-mini
    $openai_key = getenv('OPENAI_API_KEY') ?: '';
    if (empty($openai_key)) {
        $key_file = '/var/www/ezlead-hq/crm/plugins/claude/openai_key.txt';
        if (file_exists($key_file)) $openai_key = trim(file_get_contents($key_file));
    }
    if (!empty($openai_key)) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openai_key],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'gpt-4o-mini',
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => 500,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($resp, true);
        $content = $data['choices'][0]['message']['content'] ?? '';
        if (!empty($content)) {
            echo "  (estimate via OpenAI)\n";
            return $content;
        }
    }

    return "Unable to generate estimate. We'll provide a quote after inspection.";
}

// ============================================
// 1. ESTIMATE DELIVERY: Pending estimates with data -> send to customer
// ============================================
echo "Checking for pending estimates ready to send...\n";

$sql = "SELECT e.id, e.field_515 as title, e.field_516 as customer_id, e.field_517 as vehicle_id,
               e.field_518 as lead_id, e.field_519 as status, e.field_520 as problem,
               e.field_522 as labor_hours, e.field_523 as parts_cost,
               e.field_524 as labor_cost, e.field_525 as total_low, e.field_526 as total_high,
               e.field_527 as estimate_details,
               COALESCE(NULLIF(c.field_427, 'Unknown'), NULLIF(l.field_210, 'Unknown'), 'Customer') as cust_name,
               c.field_428 as cust_phone, c.field_429 as cust_email,
               v.field_434 as year, v.field_435 as make, v.field_436 as model
        FROM app_entity_53 e
        LEFT JOIN app_entity_47 c ON e.field_516 = c.id
        LEFT JOIN app_entity_48 v ON e.field_517 = v.id
        LEFT JOIN app_entity_25 l ON e.field_518 = l.id
        WHERE e.field_519 = 205
        AND (e.field_522 > 0 OR e.field_527 != '')";

$result = $conn->query($sql);
$estimates_sent = 0;

while ($row = $result->fetch_assoc()) {
    if (empty($row['cust_email']) && empty($row['cust_phone'])) continue;

    $vehicleStr = trim("{$row['year']} {$row['make']} {$row['model']}");
    // Sanitize customer name — don't greet people as "Unknown"
    if (empty($row['cust_name']) || strtolower(trim($row['cust_name'])) === 'unknown') {
        $row['cust_name'] = 'there';
    }

    // If estimate fields are empty but we have vehicle+problem, generate now
    if (empty($row['estimate_details']) && !empty($row['year']) && !empty($row['make']) && !empty($row['model']) && !empty($row['problem'])) {
        $estimateText = generate_estimate($row['year'], $row['make'], $row['model'], $row['problem']);
        $estimate_escaped = $conn->real_escape_string($estimateText);
        $conn->query("UPDATE app_entity_53 SET field_527 = '{$estimate_escaped}' WHERE id = " . intval($row['id']));
        $row['estimate_details'] = $estimateText;
    }

    // Generate PDF estimate
    $pdfPath = PDFGenerator::estimate([
        'id' => $row['id'], 'name' => $row['cust_name'], 'phone' => $row['cust_phone'],
        'email' => $row['cust_email'], 'year' => $row['year'], 'make' => $row['make'],
        'model' => $row['model'], 'problem' => $row['problem'],
        'estimate' => $row['estimate_details'],
    ]);
    $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';
    $pdfLink = $pdfUrl ? "<p><a href='$pdfUrl' style='background:#1e40af; color:white; padding:10px 20px; text-decoration:none; border-radius:6px; display:inline-block;'>View PDF Estimate</a></p>" : '';

    // Generate accept link
    require_once '/var/www/ezlead-platform/accept/token.php';
    $acceptToken = estimate_token($row['id']);
    $acceptUrl = "https://mechanicstaugustine.com/accept/?type=estimate&id={$row['id']}&token={$acceptToken}";
    $acceptBtn = "<p style='text-align:center;'><a href='$acceptUrl' style='background:#22c55e; color:white; padding:14px 28px; text-decoration:none; border-radius:6px; display:inline-block; font-size:18px; font-weight:bold;'>Approve Estimate</a></p>";

    // Email customer
    $subject = "Your Vehicle Repair Estimate - " . $vehicleStr;
    $body = "
    <h2>Hello " . htmlspecialchars($row['cust_name']) . ",</h2>
    <p>Thank you for contacting Ez Mobile Mechanic! Here's your estimate:</p>
    <div style='background:#f5f5f5; padding:15px; border-radius:8px; margin:20px 0;'>
        <h3>Vehicle: " . htmlspecialchars($vehicleStr) . "</h3>
        <p><strong>Issue:</strong> " . htmlspecialchars($row['problem']) . "</p>
        <hr>
        <pre style='white-space:pre-wrap;'>" . htmlspecialchars($row['estimate_details']) . "</pre>
    </div>
    $pdfLink
    $acceptBtn
    <p>To schedule your repair, simply reply to this email or call us at (904) 706-6669.</p>
    <p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>
    ";

    // Queue for Kyle's approval instead of sending directly
    if (!empty($row['cust_email'])) {
        queue_message('estimate', (int)$row['id'], 'email', $row['cust_email'], $subject, $body, $row['cust_name']);
    }
    if (!empty($row['cust_phone'])) {
        $totalLow = number_format(floatval($row['total_low']), 0);
        $totalHigh = number_format(floatval($row['total_high']), 0);
        $priceStr = ($totalLow && $totalHigh) ? "\${$totalLow}-\${$totalHigh}" : "see details";
        $sms = "Hi {$row['cust_name']}! Ez Mobile Mechanic here. Estimate for your {$vehicleStr}: {$priceStr}. Reply YES to approve or call (904) 217-5152. Thanks!";
        queue_message('estimate', (int)$row['id'], 'sms', $row['cust_phone'], '', $sms, $row['cust_name']);
    }

    // Update estimate status to Sent (206)
    crm_update(53, (int)$row['id'], ['field_519' => 206]);

    // Advance linked Lead stage to Quoted (77)
    if (!empty($row['lead_id'])) {
        crm_update(25, (int)$row['lead_id'], ['field_268' => 77]);
    }

    // Notify business (this goes directly - it's to Kyle, not a customer)
    send_email($business_email, "Estimate Sent: " . $row['cust_name'],
               "Estimate #{$row['id']} queued for approval. " . ($row['cust_email'] ?: $row['cust_phone']) . " - " . $vehicleStr,
               $from_email, $from_name);

    $estimates_sent++;
    echo "Estimate #{$row['id']} sent to " . ($row['cust_email'] ?: $row['cust_phone']) . "\n";
}

// ============================================
// 1a. ESTIMATE ACCEPTED: Create Job from accepted Estimate
// ============================================
echo "Checking for accepted estimates...\n";

$sql = "SELECT e.id as estimate_id, e.field_515 as title, e.field_516 as customer_id,
               e.field_517 as vehicle_id, e.field_518 as lead_id,
               e.field_520 as problem, e.field_522 as labor_hours,
               e.field_523 as parts_cost, e.field_524 as labor_cost,
               e.field_525 as total_low, e.field_526 as total_high,
               e.field_527 as estimate_details, e.field_529 as existing_job,
               COALESCE(NULLIF(c.field_427, 'Unknown'), NULLIF(l.field_210, 'Unknown'), 'Customer') as cust_name,
               c.field_428 as cust_phone,
               c.field_429 as cust_email, c.field_430 as cust_address,
               v.field_434 as year, v.field_435 as make, v.field_436 as model
        FROM app_entity_53 e
        LEFT JOIN app_entity_47 c ON e.field_516 = c.id
        LEFT JOIN app_entity_48 v ON e.field_517 = v.id
        LEFT JOIN app_entity_25 l ON e.field_518 = l.id
        WHERE e.field_519 = 207
        AND (e.field_529 IS NULL OR e.field_529 = '' OR e.field_529 = '0')";

$result = $conn->query($sql);
$jobs_created = 0;

while ($row = $result->fetch_assoc()) {
    // Create Job (entity 42) from Estimate data
    $jobFields = [
        'field_354' => $row['cust_name'] ?? 'Unknown',
        'field_355' => $row['cust_phone'] ?? '',
        'field_356' => $row['cust_email'] ?? '',
        'field_357' => $row['cust_address'] ?? '',
        'field_358' => $row['year'] ?? '',
        'field_359' => $row['make'] ?? '',
        'field_360' => $row['model'] ?? '',
        'field_361' => $row['problem'] ?? '',
        'field_362' => ($stage_ids['accepted'] ?? 84),
        'field_363' => $row['labor_hours'] ?? 0,
        'field_364' => $row['parts_cost'] ?? 0,
        'field_365' => $row['labor_cost'] ?? 0,
        'field_366' => $row['total_high'] ?? 0,
        'field_367' => $row['estimate_details'] ?? '',
        'field_371' => 91,  // Payment Status = Pending
        'field_372' => "Created from Estimate #{$row['estimate_id']}",
    ];

    if ($row['customer_id']) $jobFields['field_439'] = $row['customer_id'];
    if ($row['vehicle_id']) $jobFields['field_440'] = $row['vehicle_id'];

    // Use CRM insert so email rules, SMS rules, and process automations fire
    $jobFields['created_by'] = 0;
    items::insert(42, $jobFields);
    $jobId = db_insert_id();

    if ($jobId) {
        // Link Job back to Estimate
        items::update_by_id(53, intval($row['estimate_id']), ['field_529' => $jobId]);

        // Link Lead to Job if available, and advance Lead stage to Won (78)
        if ($row['lead_id']) {
            items::update_by_id(42, $jobId, ['field_445' => intval($row['lead_id'])]);
            crm_update(25, (int)$row['lead_id'], ['field_268' => 78]);
        }

        // Notify business
        $vehicleStr = trim("{$row['year']} {$row['make']} {$row['model']}");
        send_email($business_email, "Estimate Accepted: {$row['cust_name']} - {$vehicleStr}",
                   "Job #{$jobId} created from accepted Estimate #{$row['estimate_id']}. "
                   . "Customer: {$row['cust_name']} ({$row['cust_phone']}). "
                   . "Vehicle: {$vehicleStr}. Total: \${$row['total_high']}",
                   $from_email, $from_name);

        $jobs_created++;
        echo "Job #{$jobId} created from Estimate #{$row['estimate_id']}\n";
    }
}

// ============================================
// 1b. SMART SCHEDULING: Accepted -> send time slots
//     Sends 3 available slots, customer replies 1/2/3
// ============================================
echo "Checking for accepted jobs needing scheduling...\n";

// Kyle's availability: Mon-Fri 8am-5pm, Sat 9am-1pm
$availability = [
    1 => ['08:00', '17:00'], // Monday
    2 => ['08:00', '17:00'], // Tuesday
    3 => ['08:00', '17:00'], // Wednesday
    4 => ['08:00', '17:00'], // Thursday
    5 => ['08:00', '17:00'], // Friday
    6 => ['09:00', '13:00'], // Saturday
    // 0 => Sunday - not available
];

// Find next 3 available 2-hour slots (skip days with existing appointments)
function get_available_slots($conn, $availability, $count = 3) {
    $slots = [];
    $checkDate = new DateTime('tomorrow');
    $maxDays = 14; // Look up to 2 weeks out

    // Get existing appointments
    $result = $conn->query("SELECT field_368 FROM app_entity_42 WHERE field_368 > 0 AND field_362 IN (85, 86, 87, 88)");
    $booked = [];
    while ($row = $result->fetch_assoc()) {
        $booked[] = date('Y-m-d', (int)$row['field_368']);
    }

    for ($i = 0; $i < $maxDays && count($slots) < $count; $i++) {
        $dow = (int)$checkDate->format('w'); // 0=Sun, 1=Mon...
        if (isset($availability[$dow])) {
            $dateStr = $checkDate->format('Y-m-d');
            // Count bookings on this day
            $dayBookings = array_count_values($booked)[$dateStr] ?? 0;
            if ($dayBookings < 3) { // Max 3 jobs per day
                $startHour = (int)substr($availability[$dow][0], 0, 2);
                // Pick a slot: morning if available, else midday
                $slotHour = ($dayBookings === 0) ? $startHour : $startHour + 2 * $dayBookings;
                $endHour = (int)substr($availability[$dow][1], 0, 2);
                if ($slotHour < $endHour - 1) {
                    $slotTime = $checkDate->format('Y-m-d') . ' ' . sprintf('%02d:00:00', $slotHour);
                    $slots[] = [
                        'datetime' => $slotTime,
                        'timestamp' => strtotime($slotTime),
                        'display' => $checkDate->format('D n/j') . ' ' . date('ga', strtotime($slotTime)),
                    ];
                }
            }
        }
        $checkDate->modify('+1 day');
    }
    return $slots;
}

$sql = "SELECT id, field_354 as name, field_355 as phone
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['accepted'] ?? 84) . "
        AND field_368 = 0";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    if (empty($row['phone'])) continue;

    $slots = get_available_slots($conn, $availability);
    if (empty($slots)) continue;

    // Build SMS with slot options
    $sms = "Hi " . friendly_name($row['name']) . "! Great news - your repair is approved. Pick a time:\n";
    foreach ($slots as $i => $slot) {
        $sms .= ($i + 1) . ") " . $slot['display'] . "\n";
    }
    $sms .= "Reply 1, 2, or 3 to book. - Ez Mobile Mechanic";

    queue_message('schedule', (int)$row['id'], 'sms', $row['phone'], '', $sms, $row['name']);
    echo "  Scheduling slots queued for approval - job #{$row['id']}\n";

    // Store pending slots in notes so SMS handler can look them up
    $slotsJson = json_encode(array_map(fn($s) => $s['timestamp'], $slots));
    $conn->query("UPDATE app_entity_42 SET field_372 = CONCAT(IFNULL(field_372,''), '\nPENDING_SLOTS:" . $conn->real_escape_string($slotsJson) . "'), date_updated = " . time() . " WHERE id = " . intval($row['id']));
}

// ============================================
// 2. APPOINTMENT REMINDERS: 24 hours before
//    Email: CRM email rule #3 fires on stage change to Confirmed (87)
//    SMS: still queued here for approval
// ============================================
echo "Checking for upcoming appointments...\n";

$tomorrow = time() + 86400;
$now = time();

$sql = "SELECT id, field_354 as name, field_355 as phone, field_368 as appointment,
               field_358 as year, field_359 as make, field_360 as model
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['scheduled'] ?? 4) . "
        AND field_368 > $now AND field_368 <= $tomorrow";

$result = $conn->query($sql);
$reminders_sent = 0;

while ($row = $result->fetch_assoc()) {
    $appt_time = date('l, F j \a\t g:i A', $row['appointment']);

    // Queue SMS for approval (email handled by CRM rule #3)
    if (!empty($row['phone'])) {
        $sms = "Hi " . friendly_name($row['name']) . "! Reminder: your mechanic appointment is tomorrow, $appt_time for your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . ". Reply to confirm or call (904) 706-6669 to reschedule.";
        queue_message('reminder', (int)$row['id'], 'sms', $row['phone'], '', $sms, $row['name']);
    }

    // Move to Confirmed stage — triggers CRM email rule #3
    crm_update(42, (int)$row['id'], ['field_362' => ($stage_ids['confirmed'] ?? 6)]);

    $reminders_sent++;
    echo "Reminder sent - job #" . $row['id'] . "\n";
}

// ============================================
// 3. PAYMENT REQUEST: Complete jobs
// ============================================
echo "Checking for completed jobs needing payment...\n";

$sql = "SELECT id, field_354 as name, field_355 as phone, field_356 as email, field_366 as total,
               field_358 as year, field_359 as make, field_360 as model,
               field_370 as payment_link
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['complete'] ?? 8) . "
        AND field_366 > 0
        AND field_371 NOT IN (92, 93)";

$result = $conn->query($sql);
$invoices_sent = 0;

while ($row = $result->fetch_assoc()) {
    // if (empty($row['email'])) continue; // OLD: skip if no email
    if (empty($row['email']) && empty($row['phone'])) continue;

    $total = number_format($row['total'], 2);
    $payment_link = $row['payment_link'] ?: "";

    // Auto-create Stripe payment link if none exists and Stripe is configured
    if (empty($payment_link) && $stripe->isConfigured()) {
        $stripeResult = $stripe->createPaymentLink([
            'id' => $row['id'], 'name' => $row['name'], 'total' => $row['total'],
            'year' => $row['year'], 'make' => $row['make'], 'model' => $row['model'],
            'problem' => $row['problem'] ?? '',
        ]);
        if ($stripeResult) {
            $payment_link = $stripeResult['url'];
            // Save payment link to CRM
            $link_escaped = $conn->real_escape_string($payment_link);
            $conn->query("UPDATE app_entity_42 SET field_370 = '$link_escaped' WHERE id = " . intval($row['id']));
            echo "Stripe payment link created for job #" . $row['id'] . "\n";
        }
    }

    // Generate PDF invoice
    $pdfPath = PDFGenerator::invoice([
        'id' => $row['id'], 'name' => $row['name'], 'email' => $row['email'],
        'year' => $row['year'], 'make' => $row['make'], 'model' => $row['model'],
        'total' => $row['total'], 'payment_link' => $payment_link,
    ]);
    $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';

    $subject = "Invoice: Mobile Mechanic Repair - $" . $total;
    $body = "
    <h2>Thank You For Your Business!</h2>
    <p>Hello " . htmlspecialchars($row['name']) . ",</p>
    <p>Your repair has been completed on your " . htmlspecialchars($row['year'] . " " . $row['make'] . " " . $row['model']) . ".</p>
    <div style='background:#fff3e0; padding:20px; border-radius:8px; margin:20px 0; text-align:center;'>
        <h2 style='color:#e65100;'>Total Due: \$$total</h2>
        " . ($payment_link ? "<p><a href='" . htmlspecialchars($payment_link) . "' style='background:#4caf50; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; display:inline-block; font-size:18px;'>Pay Now</a></p>" : "") . "
    </div>
    " . ($pdfUrl ? "<p><a href='$pdfUrl' style='color:#1e40af;'>View PDF Invoice</a></p>" : "") . "
    <p>We accept cash, card, Venmo, or Zelle.</p>
    <p>Thanks for choosing Ez Mobile Mechanic!</p>
    ";

    // Queue for approval
    if (!empty($row['email'])) {
        queue_message('invoice', (int)$row['id'], 'email', $row['email'], $subject, $body, $row['name']);
    }
    if (!empty($row['phone'])) {
        $sms = "Hi " . friendly_name($row['name']) . "! Your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . " repair is done. Total: $" . $total . ".";
        if ($payment_link) $sms .= " Pay online: $payment_link";
        $sms .= " We also accept cash, Venmo, or Zelle. Thanks! - Ez Mobile Mechanic";
        queue_message('invoice', (int)$row['id'], 'sms', $row['phone'], '', $sms, $row['name']);
    }

    // Update payment status to Invoice Sent (choice id 92)
    crm_update(42, (int)$row['id'], ['field_371' => 92]);

    $invoices_sent++;
    echo "Invoice queued for approval - job #" . $row['id'] . " - $" . $total . "\n";
}

// ============================================
// 4. CHECK-IN: Soft check-in on paid jobs (1 day after)
//    Email: CRM email rule #4 fires on stage change to Follow Up (95)
//    SMS: still queued here for approval
// ============================================
echo "Checking for jobs needing check-in...\n";

$one_day_ago = time() - (1 * 86400);

$sql = "SELECT id, field_354 as name, field_355 as phone,
               field_358 as year, field_359 as make, field_360 as model,
               date_updated
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['paid'] ?? 90) . "
        AND date_updated < $one_day_ago
        AND date_updated > 0";

$result = $conn->query($sql);
$followups_sent = 0;

while ($row = $result->fetch_assoc()) {
    // Queue SMS for approval (email handled by CRM rule #4)
    if (!empty($row['phone'])) {
        $sms = "Hi " . friendly_name($row['name']) . "! Kyle from Ez Mobile Mechanic. How's your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . " running after the repair? Let me know if you have any questions or concerns! (904) 706-6669";
        queue_message('checkin', (int)$row['id'], 'sms', $row['phone'], '', $sms, $row['name']);
    }

    // Move to Follow Up stage — triggers CRM email rule #4
    crm_update(42, (int)$row['id'], ['field_362' => ($stage_ids['follow_up'] ?? 95)]);

    $followups_sent++;
    echo "Check-in sent - job #" . $row['id'] . "\n";
}

// ============================================
// 5. REVIEW REQUEST: Ask for review (1 day after check-in)
//    Email: CRM email rule #5 fires on stage change to Review Request (96)
//    SMS: still queued here for approval
// ============================================
echo "Checking for jobs needing review request...\n";

$one_day_ago_review = time() - (1 * 86400);
$review_link = "https://g.page/r/CQepHCWnvxq4EAE/review";

$sql = "SELECT id, field_354 as name, field_355 as phone,
               field_358 as year, field_359 as make, field_360 as model,
               date_updated
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['follow_up'] ?? 95) . "
        AND date_updated < $one_day_ago_review
        AND date_updated > 0";

$result = $conn->query($sql);
$reviews_sent = 0;

while ($row = $result->fetch_assoc()) {
    // Queue SMS for approval (email handled by CRM rule #5)
    if (!empty($row['phone'])) {
        $sms = "Hi " . friendly_name($row['name']) . "! Thanks for choosing Ez Mobile Mechanic! Would you mind leaving us a quick Google review? It really helps: $review_link Thanks! - Kyle";
        queue_message('review', (int)$row['id'], 'sms', $row['phone'], '', $sms, $row['name']);
    }

    // Move to Review Request stage — triggers CRM email rule #5
    crm_update(42, (int)$row['id'], ['field_362' => ($stage_ids['review_request'] ?? 96)]);

    $reviews_sent++;
    echo "Review request sent - job #" . $row['id'] . "\n";
}


// ============================================
// 5b. ESTIMATE NUDGE: Follow up on sent estimates with no response (2 days)
// ============================================
echo "Checking for estimates needing follow-up nudge...\n";

$two_days_ago_nudge = time() - (2 * 86400);
$nudge_sent = 0;

$sql = "SELECT e.id, e.field_515 as title, e.field_518 as lead_id, e.field_520 as problem,
               e.field_525 as total_low, e.field_526 as total_high,
               COALESCE(NULLIF(c.field_427, 'Unknown'), NULLIF(l.field_210, 'Unknown'), 'Customer') as cust_name,
               c.field_428 as cust_phone, c.field_429 as cust_email,
               v.field_434 as year, v.field_435 as make, v.field_436 as model,
               e.date_updated
        FROM app_entity_53 e
        LEFT JOIN app_entity_47 c ON e.field_516 = c.id
        LEFT JOIN app_entity_48 v ON e.field_517 = v.id
        LEFT JOIN app_entity_25 l ON e.field_518 = l.id
        WHERE e.field_519 = 206
        AND e.date_updated < $two_days_ago_nudge
        AND e.date_updated > 0
        AND (e.field_529 IS NULL OR e.field_529 = '' OR e.field_529 = '0')";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    if (empty($row['cust_phone']) && empty($row['cust_email'])) continue;

    // Check we haven't already sent a nudge for this estimate
    $existingNudge = $conn->query("SELECT id FROM pending_messages WHERE job_id = " . (int)$row['id'] . " AND msg_type = 'nudge' LIMIT 1");
    if ($existingNudge && $existingNudge->num_rows > 0) continue;

    $vehicleStr = trim("{$row['year']} {$row['make']} {$row['model']}");

    if (!empty($row['cust_phone'])) {
        $sms = "Hi " . friendly_name($row['cust_name']) . "! Just following up on the estimate for your {$vehicleStr}. Have you had a chance to look it over? Let me know if you have any questions. - Kyle, Ez Mobile Mechanic (904) 706-6669";
        queue_message('nudge', (int)$row['id'], 'sms', $row['cust_phone'], '', $sms, $row['cust_name']);
    }
    if (!empty($row['cust_email'])) {
        $subject = "Following Up: Your Estimate for " . $vehicleStr;
        $body = "<h2>Hi " . htmlspecialchars(friendly_name($row['cust_name'])) . ",</h2>"
            . "<p>Just checking in - have you had a chance to review the estimate for your " . htmlspecialchars($vehicleStr) . "?</p>"
            . "<p>If you have any questions or want to adjust anything, just reply to this email or call (904) 706-6669.</p>"
            . "<p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>";
        queue_message('nudge', (int)$row['id'], 'email', $row['cust_email'], $subject, $body, $row['cust_name']);
    }

    $nudge_sent++;
    echo "Estimate nudge queued for approval - estimate #{$row['id']}\n";
}

// ============================================
// 6. PARTS SYNC: parts_orders → CRM Parts Status
// ============================================
echo "Syncing parts orders to CRM...\n";

$parts_synced = 0;
$status_map = [
    'requested' => 102,  // Needs Ordering
    'ordered'   => 103,  // Ordered
    'received'  => 105,  // Arrived
];

$sql = "SELECT po.id, po.lead_id, po.job_id, po.status,
               COALESCE(po.job_id, po.lead_id) AS mechanic_job_id
        FROM parts_orders po
        WHERE po.status IS NOT NULL";

$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $jobId = $row['mechanic_job_id'];
    if (!$jobId) continue;

    $crm_status = $status_map[$row['status']] ?? null;
    if (!$crm_status) continue;

    // Check if CRM status differs
    $check = $conn->query("SELECT field_456 FROM app_entity_42 WHERE id = " . intval($jobId));
    $current = $check ? $check->fetch_assoc() : null;
    if ($current && (int)$current['field_456'] !== $crm_status) {
        $conn->query("UPDATE app_entity_42 SET field_456 = $crm_status WHERE id = " . intval($jobId));

        // Also sync parts cost from line items
        $cost_result = $conn->query("SELECT SUM(quantity * unit_cost) as total_parts_cost
                                     FROM parts_order_items
                                     WHERE parts_order_id = " . intval($row['id']));
        $cost_row = $cost_result ? $cost_result->fetch_assoc() : null;
        if ($cost_row && $cost_row['total_parts_cost'] > 0) {
            $cost = floatval($cost_row['total_parts_cost']);
            $conn->query("UPDATE app_entity_42 SET field_364 = $cost WHERE id = " . intval($jobId));
        }

        $parts_synced++;
        echo "Parts synced for job #$jobId: {$row['status']} → CRM status $crm_status\n";
    }
}
// ============================================
// 7. DIAGNOSTIC AUTO-LOOKUP: Pending diagnostics needing M1 data
// ============================================
echo "Checking for pending diagnostics needing M1 lookup...\n";

$diag_lookups = 0;
$diagSvc = new DiagnosticService($conn);

$sql = "SELECT d.id as diag_id, d.field_448 as job_id, d.field_449 as status,
               d.field_458 as m1_labor_hours
        FROM app_entity_49 d
        WHERE d.field_449 = " . CRMHelper::DIAG_STATUS_PENDING . "
        AND (d.field_458 IS NULL OR d.field_458 = 0 OR d.field_458 = '')
        AND d.field_448 != '' AND d.field_448 != '0'";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $jobId = intval($row['job_id']);
        if (!$jobId) continue;

        $lookupResult = $diagSvc->autoLookup(intval($row['diag_id']), $jobId);

        if (isset($lookupResult['error'])) {
            echo "Diag #{$row['diag_id']}: M1 lookup skipped - {$lookupResult['error']}\n";
        } else {
            echo "Diag #{$row['diag_id']}: M1 auto-filled {$lookupResult['labor_hours']}hrs, "
                . "\${$lookupResult['parts_cost_oem']} OEM parts\n";
            $diag_lookups++;
        }
    }
}

// ============================================
// 8. DIAGNOSTIC COMPLETE: Generate estimate + PDF + send to customer
// ============================================
echo "Checking for completed diagnostics...\n";

$diag_reports = 0;

$sql = "SELECT d.id as diag_id, d.field_448 as job_id,
               d.field_456 as conclusion, d.field_457 as recommended,
               d.field_452 as trouble_areas, d.field_450 as dtcs,
               d.field_451 as tsbs, d.field_455 as pan_inspection,
               d.field_453 as components, d.field_454 as torque_specs,
               d.field_460 as m1_repair_name,
               d.field_458 as m1_labor_hours, d.field_459 as m1_parts_cost,
               d.field_462 as est_labor_hours, d.field_463 as est_parts_cost,
               d.field_464 as mitchell1_ref
        FROM app_entity_49 d
        WHERE d.field_449 = " . CRMHelper::DIAG_STATUS_COMPLETE . "
        AND d.field_448 != '' AND d.field_448 != '0'";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $jobId = intval($row['job_id']);
        if (!$jobId) continue;

        // Get job data for customer info
        $job = $conn->query("SELECT * FROM app_entity_42 WHERE id = " . $jobId)->fetch_assoc();
        if (!$job) continue;

        // Skip if job already has an estimate from this diagnostic
        // (check if estimate field mentions 'diagnostic' to avoid re-processing)
        $existingEstimate = $job['field_367'] ?? '';
        if (stripos($existingEstimate, '[Diagnostic Report]') !== false) continue;

        $estHours = floatval($row['est_labor_hours'] ?: $row['m1_labor_hours']);
        $estParts = floatval($row['est_parts_cost'] ?: $row['m1_parts_cost']);

        // Generate structured estimate via EstimateEngine
        $estimateData = EstimateEngine::diagnosticEstimate([
            'est_labor_hours' => $estHours,
            'est_parts_cost' => $estParts,
            'recommended' => $row['recommended'],
            'conclusion' => $row['conclusion'],
            'trouble_areas' => $row['trouble_areas'],
            'problem' => $job['field_361'] ?? '',
            'year' => $job['field_358'] ?? '',
            'make' => $job['field_359'] ?? '',
            'model' => $job['field_360'] ?? '',
        ]);

        // Generate diagnostic report PDF
        $pdfPath = PDFGenerator::diagnosticReport([
            'id' => $job['id'],
            'name' => $job['field_354'] ?? '',
            'phone' => $job['field_355'] ?? '',
            'email' => $job['field_356'] ?? '',
            'address' => $job['field_357'] ?? '',
            'year' => $job['field_358'] ?? '',
            'make' => $job['field_359'] ?? '',
            'model' => $job['field_360'] ?? '',
            'problem' => $job['field_361'] ?? '',
            'conclusion' => $row['conclusion'],
            'recommended' => $row['recommended'],
            'dtcs' => $row['dtcs'],
            'tsbs' => $row['tsbs'],
            'trouble_areas' => $row['trouble_areas'],
            'pan_inspection' => $row['pan_inspection'],
            'components' => $row['components'],
            'torque_specs' => $row['torque_specs'],
            'm1_repair_name' => $row['m1_repair_name'],
            'est_labor_hours' => $estHours,
            'est_parts_cost' => $estParts,
            'labor_cost' => $estimateData['labor_cost'] ?? 0,
            'total_low' => $estimateData['total_low'] ?? 0,
            'total_high' => $estimateData['total_high'] ?? 0,
            'source' => $estimateData['source'] ?? 'diagnostic',
        ]);
        $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';

        // Build estimate text for CRM field
        $estimateText = "[Diagnostic Report] " . ($row['conclusion'] ?? '') . "\n"
            . "Recommended: " . ($row['recommended'] ?? '') . "\n"
            . "Labor: " . number_format($estHours, 1) . " hrs = $" . number_format($estimateData['labor_cost'] ?? 0, 2) . "\n"
            . "Parts: ~$" . number_format($estParts, 2) . "\n"
            . "Total: $" . number_format($estimateData['total_low'] ?? 0, 2)
            . " - $" . number_format($estimateData['total_high'] ?? 0, 2) . "\n"
            . "Source: " . ($estimateData['source'] ?? 'diagnostic');

        // Update Mechanic Job with estimate data
        $conn->query("UPDATE app_entity_42 SET
            field_363 = '" . $conn->real_escape_string($estHours) . "',
            field_364 = '" . $conn->real_escape_string($estParts) . "',
            field_365 = '" . $conn->real_escape_string($estimateData['labor_cost'] ?? 0) . "',
            field_366 = '" . $conn->real_escape_string($estimateData['total_high'] ?? 0) . "',
            field_367 = '" . $conn->real_escape_string($estimateText) . "',
            field_362 = " . ($stage_ids['estimate_sent'] ?? 2) . "
            WHERE id = " . $jobId);

        // Email customer with diagnostic report
        $customerEmail = $job['field_356'] ?? '';
        $customerPhone = $job['field_355'] ?? '';
        $customerName = $job['field_354'] ?? '';
        $vehicleStr = trim(($job['field_358'] ?? '') . ' ' . ($job['field_359'] ?? '') . ' ' . ($job['field_360'] ?? ''));

        if ($customerEmail) {
            $pdfLink = $pdfUrl ? "<p><a href='$pdfUrl' style='background:#1e40af; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; display:inline-block; font-size:16px;'>View Diagnostic Report &amp; Estimate</a></p>" : '';

            $subject = "Diagnostic Report & Estimate - " . $vehicleStr;
            $body = "
            <h2>Hello " . htmlspecialchars($customerName) . ",</h2>
            <p>We've completed the diagnostic on your <strong>" . htmlspecialchars($vehicleStr) . "</strong>. Here's what we found:</p>
            <div style='background:#f8fafc; padding:20px; border-radius:8px; margin:20px 0; border-left:4px solid #1e40af;'>
                <h3 style='color:#1e40af; margin-top:0;'>Diagnosis</h3>
                <p>" . htmlspecialchars($row['conclusion'] ?? '') . "</p>
                <h3 style='color:#1e40af;'>Recommended Repair</h3>
                <p>" . htmlspecialchars($row['recommended'] ?? '') . "</p>
            </div>
            <div style='background:#eff6ff; padding:20px; border-radius:8px; margin:20px 0; text-align:center;'>
                <h3 style='color:#1d4ed8;'>Estimated Cost</h3>
                <p style='font-size:24px; font-weight:bold; color:#1e40af;'>\$" . number_format($estimateData['total_low'] ?? 0, 2) . " - \$" . number_format($estimateData['total_high'] ?? 0, 2) . "</p>
                <p style='color:#64748b; font-size:12px;'>Labor: " . number_format($estHours, 1) . " hrs | Parts: ~\$" . number_format($estParts, 2) . "</p>
            </div>
            $pdfLink
            <p>To approve the repair and schedule, reply to this email or call us at (904) 706-6669.</p>
            <p>Thanks,<br>Kyle<br>Ez Mobile Mechanic - St. Augustine</p>
            ";

            queue_message('diagnostic', $jobId, 'email', $customerEmail, $subject, $body, $customerName);
        }

        // SMS customer
        if ($customerPhone) {
            $sms = "Hi " . friendly_name($customerName) . "! Your diagnostic is done for your " . $vehicleStr . ". "
                . ($row['conclusion'] ?? '') . " "
                . "Estimate: $" . number_format($estimateData['total_low'] ?? 0, 0) . "-$" . number_format($estimateData['total_high'] ?? 0, 0) . ". "
                . "Call/text (904) 706-6669 to approve & schedule.";
            queue_message('diagnostic', $jobId, 'sms', $customerPhone, '', $sms, $customerName);
        }

        // Notify Kyle
        send_email($business_email, "Diagnostic Complete: " . $customerName . " - " . $vehicleStr,
            "Diagnostic report sent to customer. Est: $" . number_format($estimateData['total_low'] ?? 0, 2)
            . " - $" . number_format($estimateData['total_high'] ?? 0, 2)
            . ($pdfUrl ? "\nPDF: $pdfUrl" : ''),
            $from_email, $from_name);

        $diag_reports++;
        echo "Diagnostic report sent for job #$jobId (diag #{$row['diag_id']})\n";

        // Log it
        file_put_contents('/home/kylewee/logs/mechanic.log',
            date('Y-m-d H:i:s') . " [DIAG-CRON] Report sent for job #$jobId, "
            . "est \${$estimateData['total_low']}-\${$estimateData['total_high']}, "
            . "source={$estimateData['source']}\n", FILE_APPEND);
    }
}

// ============================================
// Summary
// ============================================
echo "\n--- Summary ---\n";
echo "Approved msgs released: $approved_sent\n";
echo "Estimates queued: $estimates_sent\n";
echo "Jobs from estimates: $jobs_created\n";
echo "Reminders queued: $reminders_sent\n";
echo "Invoices queued: $invoices_sent\n";
echo "Check-ins queued: $followups_sent\n";
echo "Review requests queued: $reviews_sent\n";
echo "Estimate nudges queued: $nudge_sent\n";
echo "Parts synced: $parts_synced\n";
echo "Diag M1 lookups: $diag_lookups\n";
echo "Diag reports queued: $diag_reports\n";

$conn->close();
