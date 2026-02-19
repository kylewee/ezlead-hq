<?php
/**
 * CRM-Native Analytics Tracker
 * Receives pageview data and inserts directly into CRM database
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';

// Get tracking data
$data = $_POST ?: json_decode(file_get_contents('php://input'), true) ?: $_GET;

if (empty($data['url'])) {
    echo json_encode(['error' => 'Missing URL']);
    exit;
}

// Connect to database
$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database error']);
    exit;
}

// Parse domain from URL
$parsed = parse_url($data['url']);
$domain = $parsed['host'] ?? '';

// Find website ID by domain
$domain_escaped = $conn->real_escape_string($domain);
$result = $conn->query("SELECT id FROM app_entity_37 WHERE field_333 LIKE '%{$domain_escaped}%' LIMIT 1");
$website = $result ? $result->fetch_assoc() : null;

if (!$website) {
    echo json_encode(['error' => 'Unknown website: ' . $domain]);
    $conn->close();
    exit;
}

$website_id = $website['id'];

// Detect browser and device
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$browser = 'Other';
if (strpos($ua, 'Firefox') !== false) $browser = 'Firefox';
elseif (strpos($ua, 'Chrome') !== false) $browser = 'Chrome';
elseif (strpos($ua, 'Safari') !== false) $browser = 'Safari';
elseif (strpos($ua, 'Edge') !== false) $browser = 'Edge';

$device = 'Desktop';
if (preg_match('/Mobile|Android|iPhone/', $ua)) $device = 'Mobile';
elseif (preg_match('/Tablet|iPad/', $ua)) $device = 'Tablet';

// Generate visitor ID
$visitor_id = $data['vid'] ?? md5(($_SERVER['REMOTE_ADDR'] ?? '') . $ua);

// Insert pageview record
$page_url = $conn->real_escape_string(substr($data['url'], 0, 500));
$referrer = $conn->real_escape_string(substr($data['ref'] ?? '', 0, 500));
$browser = $conn->real_escape_string($browser);
$device = $conn->real_escape_string($device);
$visitor_id = $conn->real_escape_string($visitor_id);

$sql = "INSERT INTO app_entity_44 (parent_item_id, field_385, field_386, field_387, field_388, field_389, date_added, created_by) 
        VALUES ({$website_id}, '{$page_url}', '{$referrer}', '{$browser}', '{$device}', '{$visitor_id}', UNIX_TIMESTAMP(), 1)";

$success = $conn->query($sql);

// Return 1x1 transparent GIF for image tracking
if (isset($data['img'])) {
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    $conn->close();
    exit;
}

echo json_encode([
    'status' => $success ? 'ok' : 'error',
    'website_id' => $website_id,
    'error' => $success ? null : $conn->error
]);

$conn->close();
