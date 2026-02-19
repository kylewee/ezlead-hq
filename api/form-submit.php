<?php
/**
 * EzLead HQ - External Lead Submission Handler
 * Receives leads from nearby.contractors and other external sources
 * Creates lead in Rukovoditel CRM for distribution
 */

// Spam protection - honeypot
if (!empty($_POST['website_url'])) {
    header('Location: https://nearby.contractors/');
    exit;
}

// Only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: https://nearby.contractors/');
    exit;
}

// Get form data
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
$description = trim($_POST['description'] ?? '');
$city = trim($_POST['city'] ?? '');
$state = trim($_POST['state'] ?? '');
$contractor_type = trim($_POST['contractor_type'] ?? '');
$problem = trim($_POST['problem'] ?? '');
$source = trim($_POST['source'] ?? 'nearby.contractors');
$page_url = trim($_POST['page_url'] ?? '');

// Validate required fields
if (empty($name) || empty($phone)) {
    http_response_code(400);
    echo "Name and phone required";
    exit;
}

// Extract zip from address if possible
$zip = '';
if (preg_match('/\b(\d{5})\b/', $address, $matches)) {
    $zip = $matches[1];
}

// Build notes
$notes = "Source: {$source}\n";
$notes .= "Page: {$page_url}\n";
$notes .= "Contractor Type: {$contractor_type}\n";
$notes .= "Problem: {$problem}\n";
$notes .= "City: {$city}, {$state}\n";
if ($description) {
    $notes .= "\nDescription:\n{$description}\n";
}
$notes .= "\nSubmitted: " . date('Y-m-d H:i:s');

// Map vertical from contractor_type
$verticalMap = [
    'Plumber' => 'plumbing',
    'Electrician' => 'electrical',
    'HVAC Technician' => 'hvac',
    'Roofer' => 'roofing',
    'Septic Tank Service' => 'septic',
    'Foundation Repair' => 'foundation',
    'Garage Door Repair' => 'garage-door',
    'Pool Contractor' => 'pool',
    'Welder' => 'welding',
    'Sod Installation' => 'sod',
    'Sprinkler System' => 'irrigation',
    'Mobile Mechanic' => 'mechanic',
];
$vertical = $verticalMap[$contractor_type] ?? strtolower(str_replace(' ', '-', $contractor_type));

// CRM connection (Rukovoditel)
$db_host = 'localhost';
$db_name = 'rukovoditel';
$db_user = 'kylewee';
$db_pass = 'rainonin';

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Insert lead into app_entity_25 (Leads entity)
    // Fields: Name(210), Phone(211), Email(212), Address(213), Zip(214), Source(215), Vertical(216), Notes(217), Stage(218)
    $stmt = $pdo->prepare("
        INSERT INTO app_entity_25 (
            field_210, field_211, field_212, field_213, field_214, field_443,
            field_215, field_216, field_217, field_218,
            date_added, created_by
        ) VALUES (
            :name, :phone, :email, :address, :zip, 2,
            :source, :vertical, :notes, 'New',
            NOW(), 1
        )
    ");

    $stmt->execute([
        ':name' => $name,
        ':phone' => $phone,
        ':email' => '',
        ':address' => $address,
        ':zip' => $zip,
        ':source' => $source,
        ':vertical' => $vertical,
        ':notes' => $notes,
    ]);

    $leadId = $pdo->lastInsertId();

    // Log submission
    $logEntry = json_encode([
        'ts' => date('c'),
        'lead_id' => $leadId,
        'name' => $name,
        'phone' => $phone,
        'city' => $city,
        'state' => $state,
        'vertical' => $vertical,
        'source' => $source,
    ]) . "\n";
    @file_put_contents(__DIR__ . '/../data/nearby_leads.log', $logEntry, FILE_APPEND);

} catch (PDOException $e) {
    // Log error but don't expose to user
    error_log("Lead insert error: " . $e->getMessage());
    @file_put_contents(__DIR__ . '/../data/lead_errors.log', date('c') . " - " . $e->getMessage() . "\n", FILE_APPEND);
}

// Show thank you page
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You | nearby.contractors</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #1a1a2e; color: white; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .container { max-width: 500px; text-align: center; }
        .success-icon { font-size: 4em; margin-bottom: 20px; }
        h1 { font-size: 2em; margin-bottom: 15px; }
        p { font-size: 1.1em; opacity: 0.9; margin-bottom: 20px; line-height: 1.6; }
        .highlight { color: #e94560; font-weight: bold; }
        .back-btn { display: inline-block; background: #e94560; color: white; padding: 15px 30px; border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 20px; }
        .back-btn:hover { background: #ff5a75; }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✓</div>
        <h1>Thank You, <?= htmlspecialchars(explode(' ', $name)[0]) ?>!</h1>
        <p>We've received your request for a <span class="highlight"><?= htmlspecialchars($contractor_type) ?></span> in <span class="highlight"><?= htmlspecialchars($city) ?>, <?= htmlspecialchars($state) ?></span>.</p>
        <p>A local professional will contact you shortly to schedule your free inspection.</p>
        <a href="https://nearby.contractors/" class="back-btn">Back to Home</a>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
