<?php
/**
 * Create Lead in CRM
 *
 * Called from voice system or web forms
 *
 * Usage:
 *   POST /engine/create_lead.php
 *   {"name": "John Doe", "phone": "904-555-1234", "email": "john@example.com", ...}
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Get input
$input = json_decode(file_get_contents('php://input'), true);

// Also accept form-encoded
if (!$input) {
    $input = $_POST;
}

if (empty($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'No data provided']);
    exit;
}

$name = trim($input['name'] ?? '');
$phone = trim($input['phone'] ?? '');
$email = trim($input['email'] ?? '');
$address = trim($input['address'] ?? '');
$zip = trim($input['zip'] ?? '');
$source = trim($input['source'] ?? '');
$vertical = trim($input['vertical'] ?? '');
$notes = trim($input['notes'] ?? '');

if (empty($name) && empty($phone)) {
    http_response_code(400);
    echo json_encode(['error' => 'Name or phone required']);
    exit;
}

try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Create lead record
    $stmt = $db->prepare("INSERT INTO app_entity_25 (date_added, created_by, parent_item_id) VALUES (NOW(), 1, 0)");
    $stmt->execute();
    $leadId = $db->lastInsertId();

    // Add field values
    $fields = [
        FIELD_LEAD_NAME => $name,
        FIELD_LEAD_PHONE => $phone,
        FIELD_LEAD_EMAIL => $email,
        FIELD_LEAD_ADDRESS => $address,
        FIELD_LEAD_ZIP => $zip,
        FIELD_LEAD_SOURCE => $source,
        FIELD_LEAD_VERTICAL => $vertical,
        FIELD_LEAD_NOTES => $notes,
        FIELD_LEAD_STAGE => 'New',
        FIELD_LEAD_ASSIGNED_BUYER => '',
        FIELD_LEAD_BUSINESS => '2'
    ];

    foreach ($fields as $fieldId => $value) {
        if ($value !== '') {
            $stmt = $db->prepare("INSERT INTO app_entity_25_values (items_id, fields_id, value) VALUES (?, ?, ?)");
            $stmt->execute([$leadId, $fieldId, $value]);
        }
    }

    // Log
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/distribution.log',
        "[$timestamp] Lead created: #$leadId - $name ($phone) - $source/$vertical\n",
        FILE_APPEND);

    echo json_encode([
        'success' => true,
        'lead_id' => $leadId,
        'name' => $name,
        'phone' => $phone,
        'stage' => 'New'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
