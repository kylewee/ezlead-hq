<?php
/**
 * Quick Estimate AJAX Endpoint
 *
 * Actions:
 *   POST action=generate  - Create CRM records + run EstimateEngine
 *   POST action=send      - Generate PDF + email/SMS customer, mark Sent
 *
 * Called from Quick Estimate iPage.
 */

header('Content-Type: application/json');

// CRM database
require_once __DIR__ . '/../../config/database.php';
$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8');

// Platform bootstrap (for EstimateEngine + config)
$_SERVER['HTTP_HOST'] = 'mechanicstaugustine.com';
require_once '/var/www/ezlead-platform/core/config/bootstrap.php';
require_once '/var/www/ezlead-platform/core/lib/EstimateEngine.php';
require_once '/var/www/ezlead-platform/core/voice/smartcall.php';

$action = $_POST['action'] ?? '';

// ─────────────────────────────────────────────
// GENERATE: Create estimate + CRM records
// ─────────────────────────────────────────────
if ($action === 'generate') {
    $name    = trim($_POST['name'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $year    = trim($_POST['year'] ?? '');
    $make    = trim($_POST['make'] ?? '');
    $model   = trim($_POST['model'] ?? '');
    $problem = trim($_POST['problem'] ?? '');

    if (!$phone && !$name) {
        echo json_encode(['error' => 'Name or phone required']);
        exit;
    }
    if (!$year || !$make || !$model || !$problem) {
        echo json_encode(['error' => 'Year, make, model, and problem are required']);
        exit;
    }

    // Run EstimateEngine
    $estimate = EstimateEngine::estimate($year, $make, $model, $problem);

    // Create CRM records via smartcall
    $customerData = [
        'name'    => $name ?: 'Unknown',
        'phone'   => $phone,
        'email'   => $email,
        'year'    => $year,
        'make'    => $make,
        'model'   => $model,
        'problem' => $problem,
    ];

    $estimateId = smartcall_create_estimate($customerData, $estimate);

    // Create Lead + link
    $customerId = smartcall_find_or_create_customer($customerData);
    $leadId = smartcall_create_lead($customerData, $customerId, 'CRM Quick Estimate');
    if ($estimateId && $leadId) {
        smartcall_link_estimate_lead($estimateId, $leadId);
    }

    // Format price for display
    $totalLow  = number_format($estimate['total_low'] ?? 0, 0);
    $totalHigh = number_format($estimate['total_high'] ?? 0, 0);

    echo json_encode([
        'ok'          => true,
        'estimate_id' => $estimateId,
        'lead_id'     => $leadId,
        'customer_id' => $customerId,
        'source'      => $estimate['source'] ?? 'unknown',
        'repair_name' => $estimate['repair_name'] ?? '',
        'labor_hours' => $estimate['labor_hours'] ?? 0,
        'labor_cost'  => $estimate['labor_cost'] ?? 0,
        'parts_low'   => $estimate['parts_low'] ?? 0,
        'parts_high'  => $estimate['parts_high'] ?? 0,
        'total_low'   => $estimate['total_low'] ?? 0,
        'total_high'  => $estimate['total_high'] ?? 0,
        'price_str'   => "\${$totalLow} - \${$totalHigh}",
        'details'     => $estimate['procedure_note'] ?? '',
        'm1_estimate' => $estimate['m1_estimate'] ?? null,
        'ai_estimate' => $estimate['ai_estimate'] ?? null,
    ]);
    exit;
}

// ─────────────────────────────────────────────
// SEND: Generate PDF + deliver to customer
// ─────────────────────────────────────────────
if ($action === 'send') {
    $estimateId = (int)($_POST['estimate_id'] ?? 0);
    if (!$estimateId) {
        echo json_encode(['error' => 'estimate_id required']);
        exit;
    }

    // Fetch estimate + customer + vehicle from DB
    $sql = "SELECT e.id, e.field_515 as title, e.field_516 as customer_id, e.field_517 as vehicle_id,
                   e.field_519 as status, e.field_520 as problem,
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
            WHERE e.id = " . $estimateId;

    $result = $conn->query($sql);
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row) {
        echo json_encode(['error' => 'Estimate not found']);
        exit;
    }

    if (empty($row['cust_email']) && empty($row['cust_phone'])) {
        echo json_encode(['error' => 'No email or phone for customer']);
        exit;
    }

    $vehicleStr = trim("{$row['year']} {$row['make']} {$row['model']}");
    $sent_via = [];

    // Generate PDF
    require_once '/var/www/ezlead-platform/lib/PDFGenerator.php';
    $pdfPath = PDFGenerator::estimate([
        'id'       => $row['id'],
        'name'     => $row['cust_name'],
        'phone'    => $row['cust_phone'],
        'email'    => $row['cust_email'],
        'year'     => $row['year'],
        'make'     => $row['make'],
        'model'    => $row['model'],
        'problem'  => $row['problem'],
        'estimate' => $row['estimate_details'],
    ]);
    $pdfUrl = $pdfPath ? PDFGenerator::getPublicUrl($pdfPath) : '';

    // Email customer
    if (!empty($row['cust_email'])) {
        $pdfLink = $pdfUrl
            ? "<p><a href='{$pdfUrl}' style='background:#1e40af; color:white; padding:10px 20px; text-decoration:none; border-radius:6px; display:inline-block;'>View PDF Estimate</a></p>"
            : '';

        // Accept link
        require_once '/var/www/ezlead-platform/accept/token.php';
        $acceptToken = estimate_token($estimateId);
        $acceptUrl = "https://mechanicstaugustine.com/accept/?type=estimate&id={$estimateId}&token={$acceptToken}";
        $acceptBtn = "<p style='text-align:center;'><a href='{$acceptUrl}' style='background:#22c55e; color:white; padding:14px 28px; text-decoration:none; border-radius:6px; display:inline-block; font-size:18px; font-weight:bold;'>Approve Estimate</a></p>";

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
        {$pdfLink}
        {$acceptBtn}
        <p>To schedule your repair, simply reply to this email or call us at (904) 706-6669.</p>
        <p>Thanks,<br>Kyle<br>Ez Mobile Mechanic</p>";

        $headers = "From: Ez Mobile Mechanic <kyle@mechanicstaugustine.com>\r\n";
        $headers .= "Reply-To: kyle@mechanicstaugustine.com\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

        if (mail($row['cust_email'], $subject, $body, $headers)) {
            $sent_via[] = 'email';
        }
    }

    // SMS customer
    if (!empty($row['cust_phone'])) {
        $totalLow  = number_format(floatval($row['total_low']), 0);
        $totalHigh = number_format(floatval($row['total_high']), 0);
        $priceStr  = ($totalLow && $totalHigh) ? "\${$totalLow}-\${$totalHigh}" : "see details";

        $cn = (empty($row['cust_name']) || strtolower(trim($row['cust_name'])) === 'unknown') ? 'there' : $row['cust_name'];
        $sms = "Hi {$cn}! Ez Mobile Mechanic here. "
             . "Estimate for your {$vehicleStr}: {$priceStr}. "
             . "Reply YES to approve or call (904) 217-5152. Thanks!";

        // Use SignalWire SMS
        require_once __DIR__ . '/config.php';
        $project_id = SIGNALWIRE_PROJECT_ID;
        $api_token  = SIGNALWIRE_API_TOKEN;
        $space      = SIGNALWIRE_SPACE_URL;
        $from       = SIGNALWIRE_FROM_NUMBER;

        $digits = preg_replace('/\D/', '', $row['cust_phone']);
        if (strlen($digits) === 10) $digits = '1' . $digits;
        $to = '+' . $digits;

        $url = "https://{$space}/api/laml/2010-04-01/Accounts/{$project_id}/Messages.json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => "{$project_id}:{$api_token}",
            CURLOPT_POSTFIELDS     => http_build_query(['From' => $from, 'To' => $to, 'Body' => $sms]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 201) $sent_via[] = 'sms';
    }

    // Update status to Sent (206)
    $conn->query("UPDATE app_entity_53 SET field_519 = 206 WHERE id = " . $estimateId);

    // Notify Kyle
    mail('kyle@ezlead4u.com',
        "Estimate Sent: {$row['cust_name']}",
        "Estimate #{$estimateId} sent to " . ($row['cust_email'] ?: $row['cust_phone']) . " for {$vehicleStr}",
        "From: Ez Mobile Mechanic <kyle@mechanicstaugustine.com>\r\nContent-Type: text/html; charset=UTF-8\r\n"
    );

    echo json_encode([
        'ok'       => true,
        'sent_via' => $sent_via,
        'pdf_url'  => $pdfUrl,
    ]);
    exit;
}

echo json_encode(['error' => 'Invalid action. Use: generate, send']);
