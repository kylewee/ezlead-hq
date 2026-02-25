<?php
/**
 * AJAX endpoint for quick actions from the Today dashboard.
 * Handles: mark_done (tasks), advance_stage (jobs)
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'error' => 'DB connection failed']);
    exit;
}

$action = $_GET['action'] ?? '';
$entity = (int)($_GET['entity'] ?? 0);
$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Missing record ID']);
    exit;
}

$now = time();

switch ($action) {
    case 'mark_done':
        if ($entity !== 36) {
            echo json_encode(['success' => false, 'error' => 'Invalid entity for mark_done']);
            break;
        }
        $stmt = $conn->prepare("UPDATE app_entity_36 SET field_330 = '1', date_updated = ? WHERE id = ?");
        $stmt->bind_param('ii', $now, $id);
        $result = $stmt->execute();
        echo json_encode(['success' => $result]);
        $stmt->close();
        break;

    case 'undo_done':
        if ($entity !== 36) {
            echo json_encode(['success' => false, 'error' => 'Invalid entity']);
            break;
        }
        $stmt = $conn->prepare("UPDATE app_entity_36 SET field_330 = '', date_updated = ? WHERE id = ?");
        $stmt->bind_param('ii', $now, $id);
        $result = $stmt->execute();
        echo json_encode(['success' => $result]);
        $stmt->close();
        break;

    case 'advance_stage':
        if ($entity !== 42) {
            echo json_encode(['success' => false, 'error' => 'Invalid entity']);
            break;
        }
        $stage_order = [82, 83, 84, 85, 86, 87, 88, 89, 90, 95, 96];
        $current = $conn->query("SELECT field_362 FROM app_entity_42 WHERE id = $id")->fetch_assoc();
        if (!$current) {
            echo json_encode(['success' => false, 'error' => 'Record not found']);
            break;
        }
        $current_stage = (int)$current['field_362'];
        $current_idx = array_search($current_stage, $stage_order);
        if ($current_idx === false || $current_idx >= count($stage_order) - 1) {
            echo json_encode(['success' => false, 'error' => 'Cannot advance']);
            break;
        }
        $next_stage = $stage_order[$current_idx + 1];
        $stmt = $conn->prepare("UPDATE app_entity_42 SET field_362 = ?, date_updated = ? WHERE id = ?");
        $stmt->bind_param('iii', $next_stage, $now, $id);
        $result = $stmt->execute();
        echo json_encode(['success' => $result, 'new_stage' => $next_stage]);
        $stmt->close();
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}

$conn->close();
