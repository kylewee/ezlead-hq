<?php
/**
 * CRM sidebar with business dropdown filter.
 * One dropdown to switch business context, one set of menu items.
 */

$badge_conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);

// Load businesses for dropdown
$businesses = [];
if (!$badge_conn->connect_error) {
    $r = $badge_conn->query("SELECT id, field_468 as name FROM app_entity_50 ORDER BY field_468");
    if ($r) while ($row = $r->fetch_assoc()) $businesses[$row['id']] = $row['name'];
    $badge_conn->close();
}

// Get selected business from cookie or URL
$selected_biz = 0;
if (isset($_GET['biz'])) {
    $selected_biz = (int)$_GET['biz'];
    setcookie('crm_biz', $selected_biz, time() + 86400 * 365, '/crm/');
} elseif (isset($_COOKIE['crm_biz'])) {
    $selected_biz = (int)$_COOKIE['crm_biz'];
}

$current_module = $_GET['module'] ?? '';
$current_path = $_GET['path'] ?? '';

function is_active($check_module, $check_path = '') {
    global $current_module, $current_path;
    if ($check_path && $current_path == $check_path) return ' active';
    if (!$check_path && $current_module == $check_module) return ' active';
    if ($check_module == 'dashboard' && ($current_module == '' || $current_module == 'dashboard')) return ' active';
    return '';
}
?>

<ul class="page-sidebar-menu" data-keep-expanded="false" data-auto-scroll="true" data-slide-speed="200">

    <!-- BUSINESS SWITCHER -->
    <li class="sidebar-search-wrapper" style="padding:10px 15px;">
        <select id="biz-switcher" onchange="switchBiz(this.value)" style="width:100%; padding:8px 10px; border-radius:4px; border:1px solid #555; background:#2b3643; color:#fff; font-size:13px; font-weight:600; cursor:pointer;">
            <option value="0" <?= $selected_biz === 0 ? 'selected' : '' ?>>All Businesses</option>
            <?php foreach ($businesses as $bid => $bname): ?>
            <option value="<?= $bid ?>" <?= $selected_biz === $bid ? 'selected' : '' ?>><?= htmlspecialchars($bname) ?></option>
            <?php endforeach; ?>
        </select>
    </li>

    <!-- MAIN NAV -->
    <li class="<?= is_active('dashboard') ?>">
        <a href="index.php?module=dashboard">
            <i class="fa fa-home"></i>
            <span class="title">Dashboard</span>
        </a>
    </li>

    <li class="<?= ($current_module === 'ext/ipages/view' && ($_GET['id'] ?? '') == '8') ? ' active' : '' ?>">
        <a href="index.php?module=ext/ipages/view&id=8">
            <i class="fa fa-road" style="color:#e74c3c"></i>
            <span class="title">Pipeline</span>
        </a>
    </li>

    <li class="<?= is_active('items/items', '42') . is_active('items/item', '42') ?>">
        <a href="index.php?module=items/items&path=42">
            <i class="fa fa-wrench"></i>
            <span class="title">Jobs</span>
        </a>
    </li>

    <li class="<?= is_active('items/items', '53') . is_active('items/item', '53') ?>">
        <a href="index.php?module=items/items&path=53">
            <i class="fa fa-file-text-o"></i>
            <span class="title">Estimates</span>
        </a>
    </li>

    <li class="<?= is_active('items/items', '25') . is_active('items/item', '25') ?>">
        <a href="index.php?module=items/items&path=25">
            <i class="fa fa-users"></i>
            <span class="title">Leads</span>
        </a>
    </li>

    <li class="<?= is_active('items/items', '36') . is_active('items/item', '36') ?>">
        <a href="index.php?module=items/items&path=36">
            <i class="fa fa-check-square-o"></i>
            <span class="title">Tasks</span>
        </a>
    </li>

    <li class="<?= is_active('ext/pivot_calendars') . is_active('items/items', '29') . is_active('items/item', '29') ?>">
        <a href="index.php?module=ext/pivot_calendars/view&id=1">
            <i class="fa fa-calendar"></i>
            <span class="title">Calendar</span>
        </a>
    </li>

    <li class="<?= is_active('items/items', '47') . is_active('items/item', '47') ?>">
        <a href="index.php?module=items/items&path=47">
            <i class="fa fa-address-book"></i>
            <span class="title">Customers</span>
        </a>
    </li>

    <!-- MORE -->
    <li>
        <a href="javascript:;">
            <i class="fa fa-ellipsis-h"></i>
            <span class="title">More</span>
            <span class="arrow"></span>
        </a>
        <ul class="sub-menu">
            <!-- VIEWS -->
            <li style="padding:5px 15px;"><small style="color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px;">Views</small></li>
            <li><a href="index.php?module=ext/ipages/view&id=6"><i class="fa fa-tree"></i> Mission Control</a></li>
            <li><a href="index.php?module=ext/ipages/view&id=3"><i class="fa fa-globe"></i> Websites Dashboard</a></li>
            <li><a href="index.php?module=ext/ipages/view&id=5"><i class="fa fa-line-chart"></i> Analytics Dashboard</a></li>
            <li><a href="index.php?module=ext/kanban/view&id=4"><i class="fa fa-columns"></i> Jobs Kanban</a></li>
            <li><a href="index.php?module=items/items&path=49"><i class="fa fa-stethoscope"></i> Diagnostics</a></li>
            <li><a href="index.php?module=items/items&path=48"><i class="fa fa-truck"></i> Vehicles</a></li>
            <li><a href="index.php?module=items/items&path=29"><i class="fa fa-calendar-check-o"></i> Appointments</a></li>

            <!-- TOOLS -->
            <li style="padding:5px 15px;"><small style="color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px;">Tools</small></li>
            <li><a href="index.php?module=ext/ipages/view&id=1"><i class="fa fa-comments"></i> AI Chat</a></li>
            <li><a href="https://mechanicstaugustine.com/video/" target="_blank"><i class="fa fa-video-camera"></i> Video Chat</a></li>
            <li><a href="index.php?module=items/items&path=44"><i class="fa fa-line-chart"></i> Analytics</a></li>

            <!-- WEBSITES -->
            <li style="padding:5px 15px;"><small style="color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px;">Websites</small></li>
            <li><a href="index.php?module=items/items&path=37"><i class="fa fa-globe"></i> Sites</a></li>
            <li><a href="https://mechanicstaugustine.com" target="_blank"><i class="fa fa-wrench"></i> mechanicstaugustine.com</a></li>
            <li><a href="https://mobilemechanic.best" target="_blank"><i class="fa fa-wrench"></i> mobilemechanic.best (nationwide)</a></li>
            <li><a href="https://sodjax.com" target="_blank"><i class="fa fa-leaf"></i> sodjax.com</a></li>
            <li><a href="https://sodjacksonville.com" target="_blank"><i class="fa fa-leaf"></i> sodjacksonville.com</a></li>
            <li><a href="https://sodjacksonvillefl.com" target="_blank"><i class="fa fa-leaf"></i> sodjacksonvillefl.com</a></li>
            <li><a href="https://sod.company" target="_blank"><i class="fa fa-leaf"></i> sod.company</a></li>
            <li><a href="https://drainagejax.com" target="_blank"><i class="fa fa-tint"></i> drainagejax.com</a></li>
            <li><a href="https://nearby.contractors" target="_blank"><i class="fa fa-map-marker"></i> nearby.contractors</a></li>
            <li><a href="https://kyle.weerts.us" target="_blank"><i class="fa fa-user"></i> kyle.weerts.us</a></li>

            <!-- BUSINESS -->
            <li style="padding:5px 15px;"><small style="color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px;">Business</small></li>
            <li><a href="index.php?module=items/items&path=50"><i class="fa fa-building"></i> Businesses</a></li>
            <li><a href="index.php?module=items/items&path=26"><i class="fa fa-shopping-cart"></i> Buyers</a></li>
            <li><a href="index.php?module=items/items&path=27"><i class="fa fa-credit-card"></i> Transactions</a></li>

            <!-- AI & NOTES -->
            <li style="padding:5px 15px;"><small style="color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px;">AI & Notes</small></li>
            <li><a href="index.php?module=items/items&path=30"><i class="fa fa-history"></i> Sessions</a></li>
            <li><a href="index.php?module=items/items&path=35"><i class="fa fa-lightbulb-o"></i> Insights</a></li>
            <li><a href="index.php?module=items/items&path=21"><i class="fa fa-folder"></i> Projects</a></li>

            <!-- ADMIN -->
            <?php if (isset($app_user) && $app_user['group_id'] == 0): ?>
            <li style="padding:5px 15px;"><small style="color:#888;text-transform:uppercase;font-weight:700;letter-spacing:1px;">Admin</small></li>
            <li><a href="index.php?module=configuration/index"><i class="fa fa-cog"></i> Configuration</a></li>
            <li><a href="index.php?module=entities/index"><i class="fa fa-sitemap"></i> App Structure</a></li>
            <li><a href="index.php?module=reports/index"><i class="fa fa-bar-chart"></i> Reports</a></li>
            <li><a href="index.php?module=users/index"><i class="fa fa-user"></i> Users</a></li>
            <?php endif; ?>
        </ul>
    </li>

</ul>

<script src="<?= dirname($_SERVER['SCRIPT_NAME']) ?>/plugins/claude/quick_edit.js"></script>
<?php
// Quick actions config per entity
$qa_entity_id = 0;
if (isset($_GET['path']) && preg_match('/^(\d+)$/', $_GET['path'], $m)) {
    $qa_entity_id = (int)$m[1];
}
$qa_actions = [];
switch ($qa_entity_id) {
    case 42:
        $qa_actions[] = ['label' => 'Mark as Paid', 'icon' => 'fa-usd', 'field' => 371, 'value' => 93];
        $qa_actions[] = ['label' => 'Mark Complete', 'icon' => 'fa-check-circle', 'field' => 362, 'value' => 89];
        break;
    case 30:
        $qa_actions[] = ['label' => 'Archive Selected', 'icon' => 'fa-archive', 'field' => 292, 'value' => 142];
        break;
    case 36:
        $qa_actions[] = ['label' => 'Mark Done', 'icon' => 'fa-check', 'field' => 330, 'value' => 181];
        break;
    case 25:
        $qa_actions[] = ['label' => 'Mark Won', 'icon' => 'fa-trophy', 'field' => 268, 'value' => 78];
        $qa_actions[] = ['label' => 'Mark Lost', 'icon' => 'fa-times', 'field' => 268, 'value' => 79];
        break;
}

// Quick Estimate button on Estimates listing (entity 53)
if ($qa_entity_id == 53): ?>
<script>
$(function(){
    var tries = 0;
    var inj = setInterval(function(){
        var $bar = $('.entitly-listing-buttons-left').first();
        if ($bar.length && !$bar.data('qe-btn')) {
            $bar.data('qe-btn', true);
            $bar.prepend('<a href="index.php?module=ext/ipages/view&id=7" class="btn btn-primary btn-sm" style="margin-right:8px;"><i class="fa fa-calculator"></i> Quick Estimate</a>');
            clearInterval(inj);
        }
        if (++tries > 30) clearInterval(inj);
    }, 500);
});
</script>
<?php endif;
if (!empty($qa_actions)):
?>
<script>
var claudeQuickActions = <?= json_encode($qa_actions) ?>;
var claudeEntityId = <?= $qa_entity_id ?>;
var claudePath = '<?= htmlspecialchars($_GET['path'] ?? '') ?>';

$(function(){
    // Wait for listing to load, then modify the dropdown
    var tries = 0;
    var injectInterval = setInterval(function(){
        var $dropdown = $('.entitly-listing-buttons-left .dropdown-menu').first();
        if ($dropdown.length && !$dropdown.data('claude-injected')) {
            $dropdown.data('claude-injected', true);

            // Remove Copy, Link, Move
            $dropdown.find('li a').each(function(){
                var t = $(this).text().trim();
                if (t === 'Copy' || t === 'Link' || t === 'Move') {
                    $(this).parent('li').remove();
                }
            });

            // Get reports_id from an existing dropdown link
            var reportsId = '';
            $dropdown.find('li a').each(function(){
                var href = $(this).attr('href') || '';
                var m = href.match(/reports_id=(\d+)/);
                if (m) { reportsId = m[1]; return false; }
            });

            // Add divider + quick actions
            $dropdown.append('<li class="divider"></li>');
            claudeQuickActions.forEach(function(qa){
                var url = 'index.php?module=claude/with_selected/quick_action' +
                    '&path=' + claudePath +
                    '&entity_id=' + claudeEntityId +
                    '&field_id=' + qa.field +
                    '&value=' + qa.value +
                    '&label=' + encodeURIComponent(qa.label) +
                    '&reports_id=' + reportsId;
                $dropdown.append(
                    '<li><a href="' + url + '" onClick="open_dialog(this.href); return false;">' +
                    '<i class="fa ' + qa.icon + '"></i> ' + qa.label + '</a></li>'
                );
            });

            clearInterval(injectInterval);
        }
        if (++tries > 30) clearInterval(injectInterval);
    }, 500);
});
</script>
<?php endif; ?>
<script>
function switchBiz(bizId) {
    document.cookie = 'crm_biz=' + bizId + ';path=/crm/;max-age=31536000';
    var url = window.location.href;
    if (url.indexOf('biz=') > -1) {
        url = url.replace(/biz=\d+/, 'biz=' + bizId);
    } else {
        url += (url.indexOf('?') > -1 ? '&' : '?') + 'biz=' + bizId;
    }
    window.location.href = url;
}
</script>
