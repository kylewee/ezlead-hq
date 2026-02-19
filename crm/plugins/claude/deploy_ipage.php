<?php
/**
 * Deploy Claude Chat iPage to Rukovoditel
 * Run once: php deploy_ipage.php
 */

require_once __DIR__ . '/../../config/database.php';

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error . "\n");
}

// Read HTML source
$html_file = '/home/kylewee/claude-chat-ipage.html';
if (!file_exists($html_file)) {
    die("HTML file not found: $html_file\n");
}
$html_content = file_get_contents($html_file);
$html_escaped = $conn->real_escape_string($html_content);

// Check if iPage exists
$result = $conn->query("SELECT id FROM app_ext_ipages WHERE short_name = 'claude-chat'");
if ($result->num_rows > 0) {
    // Update existing
    $row = $result->fetch_assoc();
    $id = $row['id'];
    $sql = "UPDATE app_ext_ipages SET html_code = '$html_escaped', name = 'Claude Chat' WHERE id = $id";
    echo "Updating existing iPage (ID: $id)...\n";
} else {
    // Insert new
    $sql = "INSERT INTO app_ext_ipages (
        parent_id, name, short_name, menu_icon, icon_color, bg_color,
        description, html_code, users_groups, assigned_to, sort_order, is_menu, attachments
    ) VALUES (
        0, 'Claude Chat', 'claude-chat', 'fa-comments', '#5a67d8', '',
        'AI Assistant with terminal capabilities',
        '$html_escaped', '', '', 1, 1, ''
    )";
    echo "Creating new iPage...\n";
}

if ($conn->query($sql)) {
    $final_id = isset($id) ? $id : $conn->insert_id;
    echo "Success! iPage ID: $final_id\n";
    echo "URL: https://ezlead4u.com/crm/index.php?module=ext/ipages/view&id=$final_id\n";
    echo "HTML size: " . strlen($html_content) . " bytes\n";
} else {
    echo "Error: " . $conn->error . "\n";
}

$conn->close();
