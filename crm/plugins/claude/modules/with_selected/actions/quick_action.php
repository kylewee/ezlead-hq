<?php
if($app_module_action != 'apply') return;

$entity_id = (int)($_GET['entity_id'] ?? 0);
$field_id = (int)($_GET['field_id'] ?? 0);
$value = $_GET['value'] ?? '';
$reports_id = $_GET['reports_id'] ?? '';
$label = $_GET['label'] ?? 'Update';

// Validate
if(!$entity_id || !$field_id)
{
    echo '<div class="alert alert-danger">Invalid parameters.</div>';
    exit();
}

// Check report exists
$reports_info_query = db_query("select * from app_reports where id='" . db_input($reports_id) . "'");
$reports_info = db_fetch_array($reports_info_query);
if(!$reports_info)
{
    echo '<div class="alert alert-danger">Report not found.</div>';
    exit();
}

// Check access
$access_schema = users::get_entities_access_schema($reports_info['entities_id'], $app_user['group_id']);
if(!users::has_access('update', $access_schema))
{
    echo '<div class="alert alert-danger">Access denied.</div>';
    exit();
}

// Check selected items
if(!isset($app_selected_items[$reports_id]) || count($app_selected_items[$reports_id]) == 0)
{
    echo '<div class="alert alert-warning">No items selected.</div>';
    exit();
}

// Verify the field belongs to this entity
$field_info = db_find('app_fields', $field_id);
if(!$field_info || $field_info['entities_id'] != $entity_id)
{
    echo '<div class="alert alert-danger">Invalid field for this entity.</div>';
    exit();
}

$updated = 0;
$now = time();

foreach($app_selected_items[$reports_id] as $item_id)
{
    $item_id = (int)$item_id;

    // Update field value
    db_query("update app_entity_" . (int)$entity_id . " set field_" . (int)$field_id . " = '" . db_input($value) . "', date_updated = '" . $now . "' where id='" . db_input($item_id) . "'");

    // Update choices values index
    $choices_values = new choices_values($entity_id);
    $choices_values->process_by_field_id($item_id, $field_id, $field_info['type'], $value);

    // Auto-update computed fields
    fields_types::update_items_fields($entity_id, $item_id);

    // Run automation processes
    $processes = new processes($entity_id);
    $processes->run_after_update($item_id);

    $updated++;
}

// Build redirect URL
if(isset($_GET['path']) && strlen($_GET['path']))
{
    $redirect_to = url_for('items/items', 'path=' . $_GET['path']);
}
else
{
    $redirect_to = url_for('reports/view', 'reports_id=' . $reports_id);
}

echo '
<div class="alert alert-success"><i class="fa fa-check"></i> ' . $updated . ' record' . ($updated != 1 ? 's' : '') . ' updated.</div>
<script>location.href="' . $redirect_to . '";</script>
';

exit();
