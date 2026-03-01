<?php
/**
 * Queue API - Notification approval queue
 *
 * Actions:
 *   list    - Get pending (or held) items
 *   act     - Approve/Hold/Spam an item (requires id + action)
 *   stats   - Counts by status
 *   add     - Add a new item (for external hooks / testing)
 */
header('Content-Type: application/json');

$config = require __DIR__ . '/../../config.php';
$authToken = hash('sha256', $config['auth']['password'] . ':dispatch-auth');

// Allow cookie auth OR bearer token (for API/webhook calls)
$authed = false;
if (($_COOKIE['dispatch_auth'] ?? '') === $authToken) {
    $authed = true;
}
$bearer = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (preg_match('/^Bearer\s+(.+)$/', $bearer, $m) && $m[1] === $config['websocket']['auth_token']) {
    $authed = true;
}
if (!$authed) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../lib/QueueManager.php';
$queue = new QueueManager();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {
    case 'list':
        $status = $input['status'] ?? $_GET['status'] ?? 'pending';
        $items = $queue->list($status);
        echo json_encode(['items' => $items]);
        break;

    case 'act':
        $id = (int)($input['id'] ?? 0);
        $act = $input['act'] ?? '';
        if (!$id || !in_array($act, ['approve', 'hold', 'spam'])) {
            echo json_encode(['error' => 'Requires id and act (approve/hold/spam)']);
            exit;
        }
        $result = $queue->act($id, $act);
        echo json_encode($result);
        break;

    case 'stats':
        echo json_encode($queue->stats());
        break;

    case 'add':
        // For external hooks (pipeline, cron) to push items into the queue
        $type = $input['type'] ?? '';
        $summary = $input['summary'] ?? '';
        if (!$type || !$summary) {
            echo json_encode(['error' => 'Requires type and summary']);
            exit;
        }
        $id = $queue->add(
            $type,
            $input['phone'] ?? null,
            $input['name'] ?? null,
            $input['site'] ?? null,
            $summary,
            $input['data'] ?? []
        );
        echo json_encode(['success' => true, 'id' => $id]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
