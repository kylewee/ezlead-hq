<?php
/**
 * Lead Triage AJAX Endpoint
 * Handles quick-reply button actions from the AI Chat iPage.
 *
 * GET  ?action=get_queue        -> untriaged leads with estimate info
 * POST action=set_stage         -> change lead stage (Hold, Spam, etc)
 * POST action=add_tag           -> add tag to lead
 * POST action=send_estimate     -> queue linked estimate for delivery
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$conn->set_charset('utf8');

// Cache choice lookups
function get_choices_by_field($conn, $fields_id) {
    $map = [];
    $stmt = $conn->prepare("SELECT id, name, bg_color FROM app_fields_choices WHERE fields_id = ? AND is_active = 1 ORDER BY sort_order, id");
    $stmt->bind_param('i', $fields_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $map[(int)$row['id']] = ['name' => $row['name'], 'bg_color' => $row['bg_color']];
    }
    $stmt->close();
    return $map;
}

function get_choice_id_by_name($conn, $fields_id, $name) {
    $stmt = $conn->prepare("SELECT id FROM app_fields_choices WHERE fields_id = ? AND name = ? AND is_active = 1 LIMIT 1");
    $stmt->bind_param('is', $fields_id, $name);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function sync_values_table($conn, $entity_id, $record_id, $field_id, $choice_ids) {
    $table = 'app_entity_' . $entity_id . '_values';

    // Delete existing values for this field + record
    $stmt = $conn->prepare("DELETE FROM `$table` WHERE items_id = ? AND fields_id = ?");
    $stmt->bind_param('ii', $record_id, $field_id);
    $stmt->execute();
    $stmt->close();

    // Insert new values
    foreach ($choice_ids as $cid) {
        $cid = (int)$cid;
        if ($cid <= 0) continue;
        $stmt = $conn->prepare("INSERT INTO `$table` (items_id, fields_id, value) VALUES (?, ?, ?)");
        $stmt->bind_param('iii', $record_id, $field_id, $cid);
        $stmt->execute();
        $stmt->close();
    }
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ---------------------------------------------------------------
    // GET QUEUE: Untriaged leads (New + Hold stages)
    // ---------------------------------------------------------------
    case 'get_queue':
        $limit = max(1, min(50, intval($_GET['limit'] ?? 20)));

        $stage_choices = get_choices_by_field($conn, 268);
        $tag_choices = get_choices_by_field($conn, 263);

        // Find New and Hold stage IDs
        $queue_stage_ids = [];
        foreach ($stage_choices as $cid => $info) {
            if (in_array($info['name'], ['New', 'Hold'])) {
                $queue_stage_ids[] = $cid;
            }
        }

        if (empty($queue_stage_ids)) {
            echo json_encode(['success' => true, 'leads' => [], 'tag_choices' => $tag_choices, 'stage_choices' => $stage_choices]);
            exit;
        }

        $placeholders = implode(',', $queue_stage_ids);
        $sql = "SELECT l.id, l.field_210 as name, l.field_211 as phone, l.field_212 as email,
                       l.field_215 as source, l.field_268 as stage, l.field_263 as tags,
                       l.date_added,
                       e.id as estimate_id, e.field_519 as estimate_status
                FROM app_entity_25 l
                LEFT JOIN app_entity_53 e ON e.field_518 = l.id
                WHERE l.field_268 IN ($placeholders)
                ORDER BY l.id DESC
                LIMIT $limit";

        $result = $conn->query($sql);
        $leads = [];
        while ($row = $result->fetch_assoc()) {
            $leads[] = [
                'id' => (int)$row['id'],
                'name' => $row['name'] ?: 'Unknown',
                'phone' => $row['phone'] ?: '',
                'email' => $row['email'] ?: '',
                'source' => $row['source'] ?: '',
                'stage' => (int)$row['stage'],
                'tags' => $row['tags'] ?: '',
                'date_added' => $row['date_added'],
                'estimate_id' => $row['estimate_id'] ? (int)$row['estimate_id'] : null,
                'estimate_status' => $row['estimate_status'] ? (int)$row['estimate_status'] : null,
            ];
        }

        echo json_encode([
            'success' => true,
            'leads' => $leads,
            'tag_choices' => $tag_choices,
            'stage_choices' => $stage_choices,
        ]);
        break;

    // ---------------------------------------------------------------
    // SET STAGE: Change lead stage by name
    // ---------------------------------------------------------------
    case 'set_stage':
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $stage_name = trim($_POST['stage_name'] ?? '');

        if ($lead_id <= 0 || $stage_name === '') {
            echo json_encode(['error' => 'Missing lead_id or stage_name']);
            exit;
        }

        $choice_id = get_choice_id_by_name($conn, 268, $stage_name);
        if (!$choice_id) {
            echo json_encode(['error' => "Unknown stage: $stage_name"]);
            exit;
        }

        // Update field
        $stmt = $conn->prepare("UPDATE app_entity_25 SET field_268 = ? WHERE id = ?");
        $stmt->bind_param('ii', $choice_id, $lead_id);
        if (!$stmt->execute() || $stmt->affected_rows === 0) {
            echo json_encode(['error' => 'Lead not found or update failed']);
            $stmt->close();
            exit;
        }
        $stmt->close();

        // Sync values table
        sync_values_table($conn, 25, $lead_id, 268, [$choice_id]);

        echo json_encode(['success' => true, 'stage_id' => $choice_id, 'stage_name' => $stage_name]);
        break;

    // ---------------------------------------------------------------
    // ADD TAG: Append a tag to lead (no duplicates)
    // ---------------------------------------------------------------
    case 'add_tag':
        $lead_id = (int)($_POST['lead_id'] ?? 0);
        $tag_name = trim($_POST['tag_name'] ?? '');

        if ($lead_id <= 0 || $tag_name === '') {
            echo json_encode(['error' => 'Missing lead_id or tag_name']);
            exit;
        }

        $tag_id = get_choice_id_by_name($conn, 263, $tag_name);
        if (!$tag_id) {
            echo json_encode(['error' => "Unknown tag: $tag_name"]);
            exit;
        }

        // Read current tags
        $stmt = $conn->prepare("SELECT field_263 FROM app_entity_25 WHERE id = ?");
        $stmt->bind_param('i', $lead_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if (!$row) {
            echo json_encode(['error' => 'Lead not found']);
            exit;
        }

        // Parse existing tag IDs
        $current = $row['field_263'] ?: '';
        $tag_ids = array_filter(array_map('intval', explode(',', $current)));

        // Skip if already tagged
        if (in_array($tag_id, $tag_ids)) {
            echo json_encode(['success' => true, 'already_tagged' => true]);
            exit;
        }

        // Append
        $tag_ids[] = $tag_id;
        $new_tags = implode(',', $tag_ids);

        $stmt = $conn->prepare("UPDATE app_entity_25 SET field_263 = ? WHERE id = ?");
        $stmt->bind_param('si', $new_tags, $lead_id);
        $stmt->execute();
        $stmt->close();

        // Sync values table (re-insert all tags)
        sync_values_table($conn, 25, $lead_id, 263, $tag_ids);

        echo json_encode(['success' => true, 'tags' => $new_tags]);
        break;

    // ---------------------------------------------------------------
    // SEND ESTIMATE: Queue linked estimate for cron delivery
    // ---------------------------------------------------------------
    case 'send_estimate':
        $lead_id = (int)($_POST['lead_id'] ?? 0);

        if ($lead_id <= 0) {
            echo json_encode(['error' => 'Missing lead_id']);
            exit;
        }

        // Find linked estimate
        $stmt = $conn->prepare("SELECT id, field_519 as status FROM app_entity_53 WHERE field_518 = ? ORDER BY id DESC LIMIT 1");
        $stmt->bind_param('i', $lead_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $est = $result->fetch_assoc();
        $stmt->close();

        if (!$est) {
            echo json_encode(['success' => false, 'error' => 'No estimate linked to this lead']);
            exit;
        }

        $est_status = (int)$est['status'];

        // Already sent or accepted
        if ($est_status === 206) {
            echo json_encode(['success' => false, 'error' => 'Estimate already sent']);
            exit;
        }
        if ($est_status === 207) {
            echo json_encode(['success' => false, 'error' => 'Estimate already accepted']);
            exit;
        }

        // Set to Pending (205) so cron picks it up
        $est_id = (int)$est['id'];
        $stmt = $conn->prepare("UPDATE app_entity_53 SET field_519 = 205, date_updated = UNIX_TIMESTAMP() WHERE id = ?");
        $stmt->bind_param('i', $est_id);
        $stmt->execute();
        $stmt->close();

        // Sync values table for estimate status
        sync_values_table($conn, 53, $est_id, 519, [205]);

        // Also advance lead stage to Contacted
        $contacted_id = get_choice_id_by_name($conn, 268, 'Contacted');
        if ($contacted_id) {
            $stmt = $conn->prepare("UPDATE app_entity_25 SET field_268 = ? WHERE id = ?");
            $stmt->bind_param('ii', $contacted_id, $lead_id);
            $stmt->execute();
            $stmt->close();
            sync_values_table($conn, 25, $lead_id, 268, [$contacted_id]);
        }

        echo json_encode(['success' => true, 'estimate_id' => $est_id, 'message' => 'Estimate queued for delivery']);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}

$conn->close();
