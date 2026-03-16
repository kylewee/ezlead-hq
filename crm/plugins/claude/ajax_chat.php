<?php
// Simple AJAX endpoint - bypasses Rukovoditel auth for API operations
session_start();

// Database connection
require_once(__DIR__ . '/../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

header('Content-Type: application/json');

// Entity IDs
define('SESSIONS_ENTITY', 30);
define('INSIGHTS_ENTITY', 35);
define('ACTIONS_ENTITY', 36);
define('PROJECTS_ENTITY', 21);

// Field IDs
define('SESSIONS_TITLE', 290);
define('SESSIONS_PROJECT', 291);
define('SESSIONS_STATUS', 292);
define('SESSIONS_STARTED', 293);
define('SESSIONS_ENDED', 294);
define('SESSIONS_TRANSCRIPT', 295);
define('SESSIONS_SUMMARY', 296);
define('INSIGHTS_INSIGHT', 319);
define('INSIGHTS_CATEGORY', 320);
define('ACTIONS_TASK', 328);
define('ACTIONS_PRIORITY', 329);
define('PROJECTS_NAME', 158);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'get_projects':
        $result = $conn->query("SELECT id, field_" . PROJECTS_NAME . " as name FROM app_entity_" . PROJECTS_ENTITY . " ORDER BY field_" . PROJECTS_NAME);
        $projects = [];
        while ($row = $result->fetch_assoc()) {
            $projects[] = ['id' => $row['id'], 'name' => $row['name']];
        }
        echo json_encode(['projects' => $projects]);
        break;

    case 'get_sessions':
        $limit = intval($_GET['limit'] ?? 10);
        $result = $conn->query("SELECT id, field_" . SESSIONS_TITLE . " as title FROM app_entity_" . SESSIONS_ENTITY . " ORDER BY id DESC LIMIT $limit");
        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $sessions[] = ['id' => $row['id'], 'title' => $row['title']];
        }
        echo json_encode(['sessions' => $sessions]);
        break;

    case 'create_session':
        $project_id = intval($_POST['project_id'] ?? 0);
        $title = $conn->real_escape_string($_POST['title'] ?? 'New Session');
        $now = time();
        // field_293, field_294 are bigint (unix timestamps)
        // field_292 (status) is dropdown (141=Active, 142=Archived)
        $sql = "INSERT INTO app_entity_" . SESSIONS_ENTITY . "
                (field_290, field_291, field_292, field_293, field_294, field_295, field_296, date_added, created_by)
                VALUES ('$title', $project_id, 141, $now, $now, '', '', $now, 1)";
        $conn->query($sql);
        echo json_encode(['success' => true, 'session_id' => $conn->insert_id]);
        break;

    case 'save_user_message':
    case 'send_message':
        $session_id = intval($_POST['session_id'] ?? 0);
        $messages = json_decode($_POST['messages'] ?? '[]', true);

        if ($action == 'send_message' && !empty($messages)) {
            require_once(__DIR__ . '/config.php');
            require_once(__DIR__ . '/claude_api.php');
            $api = new ClaudeAPI();
            $response = $api->sendConversation($messages);

            // Save transcript - handle both string and array content
            $transcript = '';
            foreach ($messages as $m) {
                $content = $m['content'];
                // Handle array content (multi-modal messages with images)
                if (is_array($content)) {
                    $textParts = [];
                    $imageCount = 0;
                    foreach ($content as $block) {
                        if (isset($block['type'])) {
                            if ($block['type'] === 'text') {
                                $textParts[] = $block['text'];
                            } elseif ($block['type'] === 'image') {
                                $imageCount++;
                            }
                        }
                    }
                    $contentStr = implode("\n", $textParts);
                    if ($imageCount > 0) {
                        $contentStr = "[{$imageCount} image(s) attached]\n" . $contentStr;
                    }
                } else {
                    $contentStr = $content;
                }
                $transcript .= strtoupper($m['role']) . ": " . $contentStr . "\n\n";
            }
            if (!empty($response['content'])) {
                $transcript .= "ASSISTANT: " . $response['content'] . "\n\n";
            }
            $transcript = $conn->real_escape_string($transcript);
            $conn->query("UPDATE app_entity_" . SESSIONS_ENTITY . " SET field_" . SESSIONS_TRANSCRIPT . " = '$transcript' WHERE id = $session_id");

            echo json_encode($response);
        } else {
            echo json_encode(['success' => true]);
        }
        break;

    case 'save_insight':
        $session_id = intval($_POST['session_id'] ?? 0);
        $text = $conn->real_escape_string($_POST['text'] ?? '');
        $category = $conn->real_escape_string($_POST['category'] ?? 'General');
        $project_id = intval($_POST['project_id'] ?? 0);
        $now = time();
        // Auto-generate label from first few words of insight text
        $raw_text = strip_tags($_POST['text'] ?? '');
        $words = preg_split('/\s+/', trim($raw_text), 6);
        $label = $conn->real_escape_string(implode(' ', array_slice($words, 0, 5)));
        $conn->query("INSERT INTO app_entity_" . INSIGHTS_ENTITY . " (field_319, field_320, field_321, field_426, parent_item_id, date_added, created_by) VALUES ('$text', '$category', $project_id, '$label', $session_id, $now, 1)");
        echo json_encode(['success' => true, 'insight_id' => $conn->insert_id]);
        break;

    case 'save_action':
        $session_id = intval($_POST['session_id'] ?? 0);
        $text = $conn->real_escape_string($_POST['text'] ?? '');
        $priorityRaw = $_POST['priority'] ?? 'Medium';
        // Map priority names to dropdown choice IDs (178=High, 179=Medium, 180=Low)
        $priorityMap = ['High' => 178, 'Medium' => 179, 'Low' => 180];
        $priority = $priorityMap[$priorityRaw] ?? 179;
        $now = time();
        $conn->query("INSERT INTO app_entity_" . ACTIONS_ENTITY . " (field_328, field_329, field_330, field_332, parent_item_id, date_added, created_by) VALUES ('$text', $priority, 0, 0, $session_id, $now, 1)");
        echo json_encode(['success' => true, 'action_id' => $conn->insert_id]);
        break;

    case 'ollama_chat':
        $messages = json_decode($_POST['messages'] ?? '[]', true);
        if (empty($messages)) {
            echo json_encode(['error' => 'No messages']);
            break;
        }

        // Convert to Ollama format (same as OpenAI format)
        $ollamaMessages = [];
        foreach ($messages as $m) {
            $content = $m['content'];
            if (is_array($content)) {
                // Extract text from multi-modal content
                $text = '';
                foreach ($content as $block) {
                    if (isset($block['type']) && $block['type'] === 'text') {
                        $text .= $block['text'] . "\n";
                    }
                }
                $content = trim($text);
            }
            $ollamaMessages[] = ['role' => $m['role'], 'content' => $content];
        }

        $payload = json_encode([
            'model' => 'mistral',
            'messages' => $ollamaMessages,
            'stream' => false,
        ]);

        $ch = curl_init('http://localhost:11434/api/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            echo json_encode(['error' => 'Ollama connection failed: ' . $err]);
        } elseif ($httpCode !== 200) {
            echo json_encode(['error' => 'Ollama error (HTTP ' . $httpCode . ')']);
        } else {
            $data = json_decode($resp, true);
            $reply = $data['message']['content'] ?? '';
            echo json_encode(['content' => $reply]);
        }
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}

$conn->close();
