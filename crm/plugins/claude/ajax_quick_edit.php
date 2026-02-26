<?php
/**
 * Quick Edit AJAX Endpoint
 * Handles inline field editing on record detail pages.
 *
 * GET  ?action=config&entity_id=XX   -> field config + choices
 * POST entity_id, record_id, fields  -> save changed values
 */

header('Content-Type: application/json');

require_once(__DIR__ . '/../../config/database.php');

$mysqli = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($mysqli->connect_error) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}
$mysqli->set_charset('utf8');

// ---------------------------------------------------------------------------
// GET: Return field configuration for an entity
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'config') {
    $entity_id = (int)($_GET['entity_id'] ?? 0);
    if ($entity_id <= 0) {
        echo json_encode(['error' => 'Invalid entity_id']);
        exit;
    }

    // Editable field types
    $editable_types = [
        'fieldtype_input',
        'fieldtype_input_numeric',
        'fieldtype_textarea',
        'fieldtype_textarea_wysiwyg',
        'fieldtype_dropdown',
        'fieldtype_dropdown_multilevel',
        'fieldtype_input_date',
        'fieldtype_input_datetime',
        'fieldtype_boolean_checkbox',
        'fieldtype_checkboxes',
        'fieldtype_tags',
    ];
    $placeholders = implode(',', array_fill(0, count($editable_types), '?'));

    $stmt = $mysqli->prepare(
        "SELECT id, name, type, configuration
         FROM app_fields
         WHERE entities_id = ? AND type IN ($placeholders)
         ORDER BY sort_order"
    );

    $types_str = str_repeat('s', count($editable_types));
    $params = array_merge([$entity_id], $editable_types);
    $stmt->bind_param('i' . $types_str, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $fields = [];
    $dropdown_field_ids = [];

    while ($row = $result->fetch_assoc()) {
        $fields[$row['id']] = [
            'id'            => (int)$row['id'],
            'name'          => $row['name'],
            'type'          => $row['type'],
            'configuration' => $row['configuration'],
        ];
        if (in_array($row['type'], ['fieldtype_dropdown', 'fieldtype_dropdown_multilevel', 'fieldtype_checkboxes'])) {
            $dropdown_field_ids[] = (int)$row['id'];
        }
    }
    $stmt->close();

    // Fetch choices for dropdown / checkbox fields
    $choices = [];
    if (!empty($dropdown_field_ids)) {
        $ph = implode(',', array_fill(0, count($dropdown_field_ids), '?'));
        $stmt2 = $mysqli->prepare(
            "SELECT id, fields_id, name, bg_color, parent_id
             FROM app_fields_choices
             WHERE fields_id IN ($ph) AND is_active = 1
             ORDER BY sort_order, id"
        );
        $t2 = str_repeat('i', count($dropdown_field_ids));
        $stmt2->bind_param($t2, ...$dropdown_field_ids);
        $stmt2->execute();
        $result2 = $stmt2->get_result();
        while ($ch = $result2->fetch_assoc()) {
            $fid = (int)$ch['fields_id'];
            if (!isset($choices[$fid])) {
                $choices[$fid] = [];
            }
            $choices[$fid][] = [
                'id'        => (int)$ch['id'],
                'name'      => $ch['name'],
                'bg_color'  => $ch['bg_color'],
                'parent_id' => (int)$ch['parent_id'],
            ];
        }
        $stmt2->close();
    }

    echo json_encode([
        'success' => true,
        'fields'  => $fields,
        'choices' => $choices,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// POST: Save field changes
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $entity_id = (int)($_POST['entity_id'] ?? 0);
    $record_id = (int)($_POST['record_id'] ?? 0);
    $fields_json = $_POST['fields'] ?? '{}';

    if ($entity_id <= 0 || $record_id <= 0) {
        echo json_encode(['error' => 'Invalid entity_id or record_id']);
        exit;
    }

    $fields = json_decode($fields_json, true);
    if (!is_array($fields) || empty($fields)) {
        echo json_encode(['error' => 'No fields to update']);
        exit;
    }

    $table = 'app_entity_' . $entity_id;
    $values_table = $table . '_values';

    // Verify the entity table exists
    $check = $mysqli->query("SHOW TABLES LIKE '$table'");
    if ($check->num_rows === 0) {
        echo json_encode(['error' => 'Entity table not found']);
        exit;
    }

    // Verify the record exists
    $stmt = $mysqli->prepare("SELECT id FROM $table WHERE id = ?");
    $stmt->bind_param('i', $record_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(['error' => 'Record not found']);
        exit;
    }
    $stmt->close();

    // Look up field types so we know which are dropdowns
    $field_ids = [];
    foreach (array_keys($fields) as $key) {
        if (preg_match('/^field_(\d+)$/', $key, $m)) {
            $field_ids[] = (int)$m[1];
        }
    }
    if (empty($field_ids)) {
        echo json_encode(['error' => 'No valid field keys']);
        exit;
    }

    $ph = implode(',', array_fill(0, count($field_ids), '?'));
    $stmt = $mysqli->prepare("SELECT id, type FROM app_fields WHERE id IN ($ph)");
    $t = str_repeat('i', count($field_ids));
    $stmt->bind_param($t, ...$field_ids);
    $stmt->execute();
    $res = $stmt->get_result();
    $field_types = [];
    while ($r = $res->fetch_assoc()) {
        $field_types[(int)$r['id']] = $r['type'];
    }
    $stmt->close();

    // Build the UPDATE SET clause
    $set_parts = [];
    $set_values = [];
    $set_types = '';

    foreach ($fields as $col => $val) {
        if (!preg_match('/^field_(\d+)$/', $col)) {
            continue;
        }
        $set_parts[] = "`$col` = ?";
        $set_values[] = $val;
        $set_types .= 's';
    }

    // Add date_updated
    $set_parts[] = '`date_updated` = UNIX_TIMESTAMP()';

    $sql = "UPDATE `$table` SET " . implode(', ', $set_parts) . " WHERE id = ?";
    $set_values[] = $record_id;
    $set_types .= 'i';

    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($set_types, ...$set_values);

    if (!$stmt->execute()) {
        echo json_encode(['error' => 'Update failed: ' . $stmt->error]);
        exit;
    }
    $stmt->close();

    // Update the _values table for dropdown/checkbox fields
    $dropdown_types = [
        'fieldtype_dropdown',
        'fieldtype_dropdown_multilevel',
        'fieldtype_checkboxes',
    ];

    // Check if the values table exists
    $vcheck = $mysqli->query("SHOW TABLES LIKE '$values_table'");
    $has_values_table = ($vcheck->num_rows > 0);

    if ($has_values_table) {
        foreach ($fields as $col => $val) {
            if (!preg_match('/^field_(\d+)$/', $col, $m)) {
                continue;
            }
            $fid = (int)$m[1];
            $ftype = $field_types[$fid] ?? '';

            if (!in_array($ftype, $dropdown_types)) {
                continue;
            }

            // Delete existing values for this field + record
            $del = $mysqli->prepare("DELETE FROM `$values_table` WHERE items_id = ? AND fields_id = ?");
            $del->bind_param('ii', $record_id, $fid);
            $del->execute();
            $del->close();

            // Insert new value(s) -- checkboxes can be comma-separated
            $choice_ids = array_filter(explode(',', (string)$val), function($v) {
                return $v !== '' && $v !== '0';
            });

            foreach ($choice_ids as $cid) {
                $ins = $mysqli->prepare("INSERT INTO `$values_table` (items_id, fields_id, value) VALUES (?, ?, ?)");
                $cid_int = (int)$cid;
                $ins->bind_param('iii', $record_id, $fid, $cid_int);
                $ins->execute();
                $ins->close();
            }
        }
    }

    echo json_encode(['success' => true, 'updated' => count($set_parts) - 1]);
    exit;
}

echo json_encode(['error' => 'Invalid request']);
