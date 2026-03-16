<?php
// Archive idle sessions and generate summaries - run hourly via cron
require_once(__DIR__ . '/../../config/database.php');
require_once(__DIR__ . '/config.php');
require_once(__DIR__ . '/claude_api.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die("Database connection failed\n");
}

$api = new ClaudeAPI();
$archived = 0;
$summarized = 0;

// 1. Generate summaries for sessions with transcripts but no summary
$sql = "SELECT id, field_295 as transcript FROM app_entity_30 
        WHERE field_295 IS NOT NULL AND field_295 != '' 
        AND (field_296 IS NULL OR field_296 = '')";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $transcript = trim($row['transcript']);
    if (strlen($transcript) > 50) { // Only summarize if there's meaningful content
        $summary_result = $api->sendMessage($transcript, 'Summarize this session transcript in 2-3 sentences. Focus on what was accomplished and any decisions made.');
        if ($summary_result['success'] && !empty($summary_result['content'])) {
            $summary = $conn->real_escape_string($summary_result['content']);
            $conn->query("UPDATE app_entity_30 SET field_296 = '$summary' WHERE id = " . intval($row['id']));
            $summarized++;
            echo "Summarized session #" . $row['id'] . "\n";
        }
    }
}

// 2. Archive sessions older than 2 hours that are still Active
$two_hours_ago = time() - 7200;
$sql = "SELECT id FROM app_entity_30 
        WHERE field_292 = 141 AND date_added < $two_hours_ago";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $now = time();
    // Status 142 = Archived (dropdown: 141=Active, 142=Archived)
    $conn->query("UPDATE app_entity_30 SET field_292 = 142, field_294 = $now WHERE id = " . intval($row['id']));
    $archived++;
}

echo "Summarized: $summarized, Archived: $archived\n";
$conn->close();
