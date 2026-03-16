<?php
$entity_id = (int)($_GET['entity_id'] ?? 0);
$field_id = (int)($_GET['field_id'] ?? 0);
$value = $_GET['value'] ?? '';
$label = $_GET['label'] ?? 'Update';
$reports_id = $_GET['reports_id'] ?? '';

if(!isset($app_selected_items[$reports_id]))
    $app_selected_items[$reports_id] = array();

$count = count($app_selected_items[$reports_id]);

echo ajax_modal_template_header($label);

if($count == 0)
{
    echo '
    <div class="modal-body">
      <div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> Please select items first using the checkboxes in the listing.</div>
    </div>
    ' . ajax_modal_template_footer('hide-save-button');
}
else
{
    echo '<form action="' . url_for('claude/with_selected/quick_action', 'action=apply&entity_id=' . $entity_id . '&field_id=' . $field_id . '&value=' . urlencode($value) . '&reports_id=' . $reports_id . '&path=' . ($_GET['path'] ?? '')) . '" method="post" id="form-quick-action">';
    echo '<div class="modal-body">';
    echo '<p style="font-size:14px;"><i class="fa fa-question-circle"></i> <strong>' . htmlspecialchars($label) . '</strong> ' . $count . ' selected record' . ($count != 1 ? 's' : '') . '?</p>';
    echo '</div>';
    echo ajax_modal_template_footer($label);
    echo '</form>';
    ?>
    <script>
    $(function(){
        $('#form-quick-action').submit(function(){
            $('button[type=submit]', this).prop('disabled', true).text('Processing...');
            $('.modal-body', this).html('<div class="ajax-loading"></div>');
            $('.modal-body', this).load($(this).attr('action'), $(this).serializeArray(), function(){
                $('.ajax-loading').css('display','none');
            });
            return false;
        });
    });
    </script>
    <?php
}
