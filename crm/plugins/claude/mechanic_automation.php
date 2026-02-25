<?php
/**
 * Mechanic Jobs Automation - Email Notifications
 * Cron: Every 5 min - see crontab for exact schedule
 */

require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/claude_api.php');

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
$conn = new mysqli('localhost', 'kylewee', 'rainonin', 'rukovoditel');
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

$from_email = "noreply@mechanicstaugustine.com";
$from_name = "Ez Mobile Mechanic - St. Augustine";
$business_email = "kyle@ezlead4u.com"; // Your email for notifications

/**
 * Send email using PHP mail()
 */
function send_email($to, $subject, $body, $from_email, $from_name) {
    $headers = "From: $from_name <$from_email>\r\n";
    $headers .= "Reply-To: $from_email\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    return mail($to, $subject, $body, $headers);
}

/**
 * Send SMS via SignalWire (10DLC approved Feb 2026)
 */
function send_sms($to, $message) {
    $project_id = 'ce4806cb-ccb0-41e9-8bf1-7ea59536adfd';
    $api_token  = 'PT1c8cf22d1446d4d9daaf580a26ad92729e48a4a33beb769a';
    $space      = 'mobilemechanic.signalwire.com';
    $from       = '+19042175152';

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
// 1. AUTO-ESTIMATE: New leads without estimate
// ============================================
echo "Checking for new leads needing estimates...\n";

$sql = "SELECT id, field_354 as name, field_355 as phone, field_356 as email,
               field_358 as year, field_359 as make, field_360 as model,
               field_361 as problem, field_362 as stage, field_367 as estimate
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['new_lead'] ?? 1) . "
        AND (field_367 IS NULL OR field_367 = '')
        AND (field_359 != '' OR field_360 != '' OR field_361 != '')";

$result = $conn->query($sql);
$estimates_sent = 0;

while ($row = $result->fetch_assoc()) {
    if (empty($row['email']) && empty($row['phone'])) continue;

    // Generate estimate
    $estimate = generate_estimate($row['year'], $row['make'], $row['model'], $row['problem']);

    // Save estimate to job
    $estimate_escaped = $conn->real_escape_string($estimate);
    $conn->query("UPDATE app_entity_42 SET
                  field_367 = '$estimate_escaped',
                  field_362 = " . ($stage_ids['estimate_sent'] ?? 2) . "
                  WHERE id = " . intval($row['id']));

    // Generate PDF estimate
    $pdfPath = PDFGenerator::estimate([
        'id' => $row['id'], 'name' => $row['name'], 'phone' => $row['phone'],
        'email' => $row['email'], 'year' => $row['year'], 'make' => $row['make'],
        'model' => $row['model'], 'problem' => $row['problem'], 'estimate' => $estimate,
    ]);
    $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';
    $pdfLink = $pdfUrl ? "<p><a href='$pdfUrl' style='background:#1e40af; color:white; padding:10px 20px; text-decoration:none; border-radius:6px; display:inline-block;'>View PDF Estimate</a></p>" : '';

    // Email customer
    $subject = "Your Vehicle Repair Estimate - " . $row['year'] . " " . $row['make'] . " " . $row['model'];
    $body = "
    <h2>Hello " . htmlspecialchars($row['name']) . ",</h2>
    <p>Thank you for contacting Ez Mobile Mechanic! Here's your estimate:</p>
    <div style='background:#f5f5f5; padding:15px; border-radius:8px; margin:20px 0;'>
        <h3>Vehicle: " . htmlspecialchars($row['year'] . " " . $row['make'] . " " . $row['model']) . "</h3>
        <p><strong>Issue:</strong> " . htmlspecialchars($row['problem']) . "</p>
        <hr>
        <pre style='white-space:pre-wrap;'>" . htmlspecialchars($estimate) . "</pre>
    </div>
    $pdfLink
    <p>To schedule your repair, simply reply to this email or call us at (904) 706-6669.</p>
    <p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>
    ";

    if (!empty($row['email'])) send_email($row['email'], $subject, $body, $from_email, $from_name);

    // SMS customer
    if (!empty($row['phone'])) {
        $total = number_format(floatval($row['total']), 2);
        $sms = "Hi " . $row['name'] . "! Ez Mobile Mechanic here. Estimate for your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . ": ~$" . $total . ". View details & approve: https://mobilemechanic.best/accept/" . $row['id'] . " Or reply YES to book.";
        send_sms($row['phone'], $sms);
    }

    // Notify business
    send_email($business_email, "Estimate Sent: " . $row['name'],
               "Estimate sent to " . $row['email'] . " for " . $row['year'] . " " . $row['make'] . " " . $row['model'],
               $from_email, $from_name);

    $estimates_sent++;
    echo "Estimate sent to " . $row['email'] . " for job #" . $row['id'] . "\n";
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
    $sms = "Hi " . $row['name'] . "! Great news - your repair is approved. Pick a time:\n";
    foreach ($slots as $i => $slot) {
        $sms .= ($i + 1) . ") " . $slot['display'] . "\n";
    }
    $sms .= "Reply 1, 2, or 3 to book. - Ez Mobile Mechanic";

    send_sms($row['phone'], $sms);
    echo "  SMS to {$row['phone']}: scheduling slots sent for job #{$row['id']}\n";

    // Store pending slots in notes so SMS handler can look them up
    $slotsJson = json_encode(array_map(fn($s) => $s['timestamp'], $slots));
    $conn->query("UPDATE app_entity_42 SET field_372 = CONCAT(IFNULL(field_372,''), '\nPENDING_SLOTS:" . $conn->real_escape_string($slotsJson) . "'), date_updated = " . time() . " WHERE id = " . intval($row['id']));
}

// ============================================
// 2. APPOINTMENT REMINDERS: 24 hours before
//    Parts-aware: won't confirm if parts not ready
// ============================================
echo "Checking for upcoming appointments...\n";

$tomorrow = time() + 86400;
$now = time();

// Parts Status (field_456): 101=Not Needed, 102=Needs Ordering, 103=Ordered, 104=Shipped, 105=Arrived, 106=Backordered
$PARTS_NOT_NEEDED = 101;
$PARTS_ARRIVED = 105;

$sql = "SELECT id, field_354 as name, field_355 as phone, field_356 as email, field_368 as appointment,
               field_358 as year, field_359 as make, field_360 as model,
               /* field_456 as parts_status, field_457 as parts_eta, */ /* fields don't exist yet */
               field_369 as parts_to_order
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['scheduled'] ?? 4) . "
        AND field_368 > $now AND field_368 <= $tomorrow";

$result = $conn->query($sql);
$reminders_sent = 0;
$parts_warnings = 0;

while ($row = $result->fetch_assoc()) {
    /* COMMENTED OUT: parts_status fields (field_456/457) don't exist yet
    $parts_status = (int)($row['parts_status'] ?: $PARTS_NOT_NEEDED);
    $parts_ready = in_array($parts_status, [$PARTS_NOT_NEEDED, $PARTS_ARRIVED]);

    // If parts NOT ready, warn Kyle instead of confirming with customer
    if (!$parts_ready) {
        $parts_labels = [102 => 'NEEDS ORDERING', 103 => 'ORDERED - NOT YET ARRIVED', 104 => 'SHIPPED - IN TRANSIT', 106 => 'BACKORDERED'];
        $parts_label = $parts_labels[$parts_status] ?? 'UNKNOWN';
        $eta_info = $row['parts_eta'] ? " (ETA: {$row['parts_eta']})" : '';
        $appt_time = date('l, F j \a\t g:i A', $row['appointment']);

        $warning_subject = "PARTS NOT READY - Job #{$row['id']} {$row['name']} tomorrow";
        $warning_body = "
        <h2 style='color:#d32f2f;'>Parts Not Ready for Tomorrow's Appointment</h2>
        <p><strong>Customer:</strong> " . htmlspecialchars($row['name']) . "</p>
        <p><strong>Vehicle:</strong> " . htmlspecialchars($row['year'] . " " . $row['make'] . " " . $row['model']) . "</p>
        <p><strong>Appointment:</strong> $appt_time</p>
        <div style='background:#ffebee; padding:15px; border-radius:8px; margin:20px 0;'>
            <h3 style='color:#c62828;'>Parts Status: $parts_label$eta_info</h3>
            " . ($row['parts_to_order'] ? "<p><strong>Parts:</strong> " . htmlspecialchars($row['parts_to_order']) . "</p>" : "") . "
        </div>
        <p><strong>Action needed:</strong> Either reschedule or confirm parts will arrive in time.</p>
        ";

        send_email($business_email, $warning_subject, $warning_body, $from_email, $from_name);
        $parts_warnings++;
        echo "PARTS WARNING for job #" . $row['id'] . " - " . $row['name'] . " ($parts_label)\n";
        continue; // Don't send confirmation to customer yet
    }
    END COMMENTED OUT */

    // if (empty($row['email'])) continue; // OLD: skip if no email
    if (empty($row['email']) && empty($row['phone'])) continue;

    $appt_time = date('l, F j \a\t g:i A', $row['appointment']);

    $subject = "Reminder: Your Mechanic Appointment Tomorrow";
    $body = "
    <h2>Appointment Reminder</h2>
    <p>Hello " . htmlspecialchars($row['name']) . ",</p>
    <p>This is a reminder that your mobile mechanic appointment is scheduled for:</p>
    <div style='background:#e8f5e9; padding:15px; border-radius:8px; margin:20px 0; text-align:center;'>
        <h3 style='color:#2e7d32;'>$appt_time</h3>
        <p>" . htmlspecialchars($row['year'] . " " . $row['make'] . " " . $row['model']) . "</p>
    </div>
    <p>Please reply to confirm or reschedule.</p>
    <p>Thanks,<br>Ez Mobile Mechanic - St. Augustine</p>
    ";

    // send_email($row['email'], $subject, $body, $from_email, $from_name); // OLD
    if (!empty($row['email'])) send_email($row['email'], $subject, $body, $from_email, $from_name);

    // SMS customer
    if (!empty($row['phone'])) {
        $sms = "Hi " . $row['name'] . "! Reminder: your mechanic appointment is tomorrow, $appt_time for your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . ". Reply to confirm or call (904) 706-6669 to reschedule.";
        send_sms($row['phone'], $sms);
    }

    // Move to Confirmed stage
    $conn->query("UPDATE app_entity_42 SET field_362 = " . ($stage_ids['confirmed'] ?? 6) . " WHERE id = " . intval($row['id']));

    $reminders_sent++;
    echo "Reminder sent to " . $row['email'] . " for job #" . $row['id'] . "\n";
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

    // send_email($row['email'], $subject, $body, $from_email, $from_name); // OLD
    if (!empty($row['email'])) send_email($row['email'], $subject, $body, $from_email, $from_name);

    // SMS customer
    if (!empty($row['phone'])) {
        $sms = "Hi " . $row['name'] . "! Your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . " repair is done. Total: $" . $total . ".";
        if ($payment_link) $sms .= " Pay online: $payment_link";
        $sms .= " We also accept cash, Venmo, or Zelle. Thanks! - Ez Mobile Mechanic";
        send_sms($row['phone'], $sms);
    }

    // Update payment status to Invoice Sent (choice id 92)
    $conn->query("UPDATE app_entity_42 SET field_371 = 92 WHERE id = " . intval($row['id']));

    $invoices_sent++;
    echo "Invoice sent for job #" . $row['id'] . " - $" . $total . "\n";
}

// ============================================
// 4. FOLLOW UP: Check on paid jobs (3 days after)
// ============================================
echo "Checking for jobs needing follow-up...\n";

$three_days_ago = time() - (3 * 86400);

$sql = "SELECT id, field_354 as name, field_355 as phone, field_356 as email,
               field_358 as year, field_359 as make, field_360 as model,
               date_updated
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['paid'] ?? 90) . "
        AND date_updated < $three_days_ago
        AND date_updated > 0";

$result = $conn->query($sql);
$followups_sent = 0;

while ($row = $result->fetch_assoc()) {
    // if (empty($row['email'])) continue; // OLD: skip if no email
    if (empty($row['email']) && empty($row['phone'])) continue;

    // Email customer
    if (!empty($row['email'])) {
        $subject = "How's Your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . " Running?";
        $body = "
        <h2>Hi " . htmlspecialchars($row['name']) . "!</h2>
        <p>Just checking in to see how your " . htmlspecialchars($row['year'] . " " . $row['make'] . " " . $row['model']) . " is running after our recent repair.</p>
        <p>If you have any questions or concerns, please don't hesitate to reach out!</p>
        <p>Thanks again for choosing Ez Mobile Mechanic - St. Augustine.</p>
        <p>Best regards,<br>Kyle<br>Ez Mobile Mechanic</p>
        ";
        send_email($row['email'], $subject, $body, $from_email, $from_name);
    }

    // SMS customer
    if (!empty($row['phone'])) {
        $sms = "Hi " . $row['name'] . "! Kyle from Ez Mobile Mechanic. How's your " . $row['year'] . " " . $row['make'] . " " . $row['model'] . " running after the repair? Let me know if you have any issues! (904) 706-6669";
        send_sms($row['phone'], $sms);
    }

    // Move to Follow Up stage
    $conn->query("UPDATE app_entity_42 SET field_362 = " . ($stage_ids['follow_up'] ?? 95) . ", date_updated = " . time() . " WHERE id = " . intval($row['id']));

    $followups_sent++;
    echo "Follow-up sent for job #" . $row['id'] . "\n";
}

// ============================================
// 5. REVIEW REQUEST: Ask for review (2 days after follow-up)
// ============================================
echo "Checking for jobs needing review request...\n";

$two_days_ago = time() - (2 * 86400);
$review_link = "https://g.page/r/CQepHCWnvxq4EAE/review";

$sql = "SELECT id, field_354 as name, field_355 as phone, field_356 as email,
               field_358 as year, field_359 as make, field_360 as model,
               date_updated
        FROM app_entity_42
        WHERE field_362 = " . ($stage_ids['follow_up'] ?? 95) . "
        AND date_updated < $two_days_ago
        AND date_updated > 0";

$result = $conn->query($sql);
$reviews_sent = 0;

while ($row = $result->fetch_assoc()) {
    // if (empty($row['email'])) continue; // OLD: skip if no email
    if (empty($row['email']) && empty($row['phone'])) continue;

    $subject = "Ez Mobile Mechanic Would Love Your Feedback!";
    $body = "
    <h2>Hi " . htmlspecialchars($row['name']) . "!</h2>
    <p>Thank you for choosing Ez Mobile Mechanic - St. Augustine for your " . htmlspecialchars($row['year'] . " " . $row['make'] . " " . $row['model']) . " repair!</p>
    <p>We'd really appreciate it if you could take a moment to share your experience. Your feedback helps other customers find quality mobile mechanic services.</p>
    <div style='background:#e3f2fd; padding:25px; border-radius:12px; margin:25px 0; text-align:center;'>
        <h3 style='color:#1565c0; margin-bottom:20px;'>Leave Us a Review</h3>
        <a href='$review_link' style='background:#4285f4; color:white; padding:15px 30px; text-decoration:none; border-radius:8px; display:inline-block; font-size:18px; font-weight:bold;'>Post a Review on Google</a>
        <p style='margin-top:20px; color:#666;'>Or scan this QR code:</p>
        <img src='cid:qrcode' alt='QR Code for Review' style='width:150px; height:150px; margin-top:10px;'>
    </div>
    <p>Thank you for your support!</p>
    <p>Best regards,<br>Kyle<br>Ez Mobile Mechanic - St. Augustine</p>
    ";

    // send_email($row['email'], $subject, $body, $from_email, $from_name); // OLD
    if (!empty($row['email'])) send_email($row['email'], $subject, $body, $from_email, $from_name);

    // SMS customer
    if (!empty($row['phone'])) {
        $sms = "Hi " . $row['name'] . "! Thanks for choosing Ez Mobile Mechanic! Would you mind leaving us a quick Google review? It really helps: $review_link Thanks! - Kyle";
        send_sms($row['phone'], $sms);
    }

    // Move to Review Request stage (final stage)
    $conn->query("UPDATE app_entity_42 SET field_362 = " . ($stage_ids['review_request'] ?? 96) . ", date_updated = " . time() . " WHERE id = " . intval($row['id']));

    $reviews_sent++;
    echo "Review request sent for job #" . $row['id'] . "\n";
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

            send_email($customerEmail, $subject, $body, $from_email, $from_name);
        }

        // SMS customer
        if ($customerPhone) {
            $sms = "Hi " . $customerName . "! Your diagnostic is done for your " . $vehicleStr . ". "
                . ($row['conclusion'] ?? '') . " "
                . "Estimate: $" . number_format($estimateData['total_low'] ?? 0, 0) . "-$" . number_format($estimateData['total_high'] ?? 0, 0) . ". "
                . "Call/text (904) 706-6669 to approve & schedule.";
            send_sms($customerPhone, $sms);
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
echo "Estimates sent: $estimates_sent\n";
echo "Reminders sent: $reminders_sent\n";
echo "Parts warnings: $parts_warnings\n";
echo "Invoices sent: $invoices_sent\n";
echo "Follow-ups sent: $followups_sent\n";
echo "Review requests sent: $reviews_sent\n";
echo "Parts synced: $parts_synced\n";
echo "Diag M1 lookups: $diag_lookups\n";
echo "Diag reports sent: $diag_reports\n";

$conn->close();
