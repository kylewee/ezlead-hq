<?php
/**
 * All-in-One Dashboard - Ez Mobile Mechanic CRM
 * Shows: Mechanic Jobs, Websites, Issues, Sessions, Analytics
 */

require_once(__DIR__ . '/../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die("Connection failed");
}

// Get stage names for Mechanic Jobs
$stages = [];
$result = $conn->query("SELECT id, name, bg_color FROM app_fields_choices WHERE fields_id = 362 ORDER BY sort_order");
while ($row = $result->fetch_assoc()) {
    $stages[$row['id']] = ['name' => $row['name'], 'color' => $row['bg_color'] ?? '#666'];
}

// Mechanic Jobs by Stage
$jobs_by_stage = [];
$result = $conn->query("SELECT field_362 as stage, COUNT(*) as count FROM app_entity_42 GROUP BY field_362");
while ($row = $result->fetch_assoc()) {
    $jobs_by_stage[$row['stage']] = $row['count'];
}

// Recent Mechanic Jobs
$recent_jobs = [];
$result = $conn->query("SELECT id, field_354 as name, field_355 as phone, field_359 as make, field_360 as model, field_362 as stage, field_366 as total FROM app_entity_42 ORDER BY id DESC LIMIT 5");
while ($row = $result->fetch_assoc()) {
    $recent_jobs[] = $row;
}

// Revenue Stats
$today_start = strtotime('today midnight');
$week_start = strtotime('monday this week');
$month_start = strtotime('first day of this month midnight');

$today_rev = $conn->query("SELECT COALESCE(SUM(field_366),0) as t FROM app_entity_42 WHERE field_362 = 90 AND date_updated >= $today_start")->fetch_assoc()['t'];
$week_rev = $conn->query("SELECT COALESCE(SUM(field_366),0) as t FROM app_entity_42 WHERE field_362 = 90 AND date_updated >= $week_start")->fetch_assoc()['t'];
$month_rev = $conn->query("SELECT COALESCE(SUM(field_366),0) as t FROM app_entity_42 WHERE field_362 = 90 AND date_updated >= $month_start")->fetch_assoc()['t'];

// Upcoming Appointments
$now = time();
$week_ahead = $now + (7 * 86400);
$appointments = [];
$result = $conn->query("SELECT id, field_354 as name, field_359 as make, field_360 as model, field_368 as appt_time FROM app_entity_42 WHERE field_368 > $now AND field_368 < $week_ahead ORDER BY field_368 LIMIT 5");
while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}

// ============ WEBSITES ============
$websites = [];
$result = $conn->query("SELECT id, field_333 as domain, field_334 as url, field_335 as status, field_336 as type, field_410 as health, field_412 as last_check, field_413 as ssl_expires, field_415 as open_issues, field_416 as domain_expires FROM app_entity_37 ORDER BY field_333");
while ($row = $result->fetch_assoc()) {
    // Get latest response time from uptime logs
    $log = $conn->query("SELECT field_422 as response_time FROM app_entity_46 WHERE parent_item_id = {$row['id']} ORDER BY id DESC LIMIT 1")->fetch_assoc();
    $row['response_time'] = $log['response_time'] ?? 0;

    // Calculate uptime percentage (last 24 hours)
    $day_ago = time() - 86400;
    $uptime_stats = $conn->query("SELECT
        COUNT(*) as total,
        SUM(CASE WHEN field_421 = 120 THEN 1 ELSE 0 END) as up_count
        FROM app_entity_46
        WHERE parent_item_id = {$row['id']} AND field_420 >= $day_ago")->fetch_assoc();
    $row['uptime_pct'] = $uptime_stats['total'] > 0 ? round(($uptime_stats['up_count'] / $uptime_stats['total']) * 100, 1) : 100;

    // Calculate SSL days left
    if (!empty($row['ssl_expires'])) {
        $row['ssl_days'] = floor((strtotime($row['ssl_expires']) - time()) / 86400);
    } else {
        $row['ssl_days'] = null;
    }

    // Calculate domain expiry days left
    if (!empty($row['domain_expires'])) {
        $row['domain_days'] = floor((strtotime($row['domain_expires']) - time()) / 86400);
    } else {
        $row['domain_days'] = null;
    }

    $websites[] = $row;
}

// Health status mapping
$health_map = [];
$result = $conn->query("SELECT id, name, bg_color FROM app_fields_choices WHERE fields_id = 410");
while ($row = $result->fetch_assoc()) {
    $health_map[$row['id']] = ['name' => $row['name'], 'color' => $row['bg_color']];
}

// Website type mapping
$type_map = [];
$result = $conn->query("SELECT id, name FROM app_fields_choices WHERE fields_id = 336");
while ($row = $result->fetch_assoc()) {
    $type_map[$row['id']] = $row['name'];
}

// ============ ISSUES ============
$open_issues = [];
$result = $conn->query("SELECT i.id, i.field_380 as title, i.field_381 as type, i.field_382 as priority, i.field_383 as status, i.parent_item_id, w.field_333 as site
    FROM app_entity_43 i
    LEFT JOIN app_entity_37 w ON i.parent_item_id = w.id
    WHERE i.field_383 IN (SELECT id FROM app_fields_choices WHERE fields_id = 383 AND name IN ('Open', 'In Progress'))
    ORDER BY i.field_382, i.id DESC LIMIT 10");
while ($row = $result->fetch_assoc()) {
    $open_issues[] = $row;
}

// Issue counts
$issue_counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0];
$result = $conn->query("SELECT c.name, COUNT(*) as cnt FROM app_entity_43 i JOIN app_fields_choices c ON i.field_383 = c.id GROUP BY i.field_383");
while ($row = $result->fetch_assoc()) {
    $key = strtolower(str_replace(' ', '_', $row['name']));
    $issue_counts[$key] = $row['cnt'];
}

// Priority mapping
$priority_map = [];
$result = $conn->query("SELECT id, name, bg_color FROM app_fields_choices WHERE fields_id = 382");
while ($row = $result->fetch_assoc()) {
    $priority_map[$row['id']] = ['name' => $row['name'], 'color' => $row['bg_color']];
}

// Issue type mapping
$issue_type_map = [];
$result = $conn->query("SELECT id, name, bg_color FROM app_fields_choices WHERE fields_id = 381");
while ($row = $result->fetch_assoc()) {
    $issue_type_map[$row['id']] = ['name' => $row['name'], 'color' => $row['bg_color']];
}

// ============ CLAUDE SESSIONS ============
$sessions = [];
$result = $conn->query("SELECT id, field_290 as title, field_296 as summary, date_added FROM app_entity_30 ORDER BY id DESC LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sessions[] = $row;
    }
}

// Insights
$insights = [];
$result = $conn->query("SELECT id, field_319 as insight, field_320 as category FROM app_entity_35 ORDER BY id DESC LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $insights[] = $row;
    }
}

// Actions
$actions = [];
$result = $conn->query("SELECT id, field_328 as task, field_329 as priority FROM app_entity_36 ORDER BY id DESC LIMIT 5");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $actions[] = $row;
    }
}

$conn->close();

$stage_colors = [
    82 => '#3498db', 83 => '#9b59b6', 84 => '#27ae60', 85 => '#f39c12',
    86 => '#e67e22', 87 => '#1abc9c', 88 => '#e74c3c', 89 => '#2ecc71',
    90 => '#16a085', 95 => '#607d8b', 96 => '#ff9800'
];
?>

<style>
.dashboard { padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f6fa; min-height: 100vh; }
.dashboard h1 { margin: 0 0 5px; color: #333; font-size: 28px; }
.dashboard .subtitle { color: #666; margin-bottom: 20px; }
.stats-row { display: flex; gap: 15px; margin-bottom: 20px; flex-wrap: wrap; }
.stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 12px; min-width: 140px; flex: 1; }
.stat-card.green { background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%); }
.stat-card.orange { background: linear-gradient(135deg, #f5576c 0%, #f093fb 100%); }
.stat-card.blue { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
.stat-card.red { background: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%); }
.stat-card h3 { margin: 0; font-size: 12px; opacity: 0.9; text-transform: uppercase; letter-spacing: 1px; }
.stat-card .number { font-size: 28px; font-weight: bold; margin: 5px 0; }
.stat-card small { opacity: 0.8; font-size: 11px; }

.section-title { font-size: 18px; font-weight: 600; margin: 25px 0 15px; color: #333; display: flex; align-items: center; gap: 10px; }
.section-title a { font-size: 12px; color: #667eea; text-decoration: none; margin-left: auto; }

.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
.grid-3 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
.card { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
.card-header { background: #f8f9fa; padding: 12px 16px; border-bottom: 1px solid #eee; font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
.card-header a { font-size: 11px; color: #667eea; text-decoration: none; }
.card-body { padding: 12px 16px; }

.pipeline { display: flex; gap: 6px; flex-wrap: wrap; }
.pipeline-stage { padding: 6px 10px; border-radius: 16px; font-size: 11px; color: white; display: flex; align-items: center; gap: 4px; }
.pipeline-stage .count { background: rgba(255,255,255,0.3); padding: 2px 6px; border-radius: 8px; font-weight: bold; }

.list { list-style: none; padding: 0; margin: 0; }
.list li { padding: 10px 0; border-bottom: 1px solid #f0f0f0; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.list li:last-child { border-bottom: none; }
.list-title { font-weight: 500; color: #333; font-size: 13px; }
.list-sub { font-size: 11px; color: #888; }
.badge { font-size: 10px; padding: 3px 8px; border-radius: 10px; color: white; white-space: nowrap; }

.website-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.website-card { background: #f8f9fa; border-radius: 8px; padding: 12px; border-left: 4px solid #27ae60; }
.website-card.warning { border-left-color: #f39c12; }
.website-card.down { border-left-color: #e74c3c; }
.website-domain { font-weight: 600; font-size: 13px; color: #333; }
.website-type { font-size: 11px; color: #666; }
.website-stats { display: flex; gap: 10px; margin-top: 8px; font-size: 11px; }
.website-stats span { display: flex; align-items: center; gap: 3px; }

.empty { color: #999; font-style: italic; padding: 20px; text-align: center; font-size: 13px; }
.health-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
</style>

<div class="dashboard">
    <h1>📊 Dashboard</h1>
    <p class="subtitle">Ez Mobile Mechanic - St. Augustine | <?= date('l, F j, Y') ?></p>

    <!-- Revenue Stats -->
    <div class="stats-row">
        <div class="stat-card green">
            <h3>Today</h3>
            <div class="number">$<?= number_format($today_rev, 0) ?></div>
            <small>Revenue</small>
        </div>
        <div class="stat-card blue">
            <h3>This Week</h3>
            <div class="number">$<?= number_format($week_rev, 0) ?></div>
            <small>Revenue</small>
        </div>
        <div class="stat-card">
            <h3>This Month</h3>
            <div class="number">$<?= number_format($month_rev, 0) ?></div>
            <small>Revenue</small>
        </div>
        <div class="stat-card orange">
            <h3>Active Jobs</h3>
            <div class="number"><?= array_sum($jobs_by_stage) - ($jobs_by_stage[96] ?? 0) ?></div>
            <small>In pipeline</small>
        </div>
        <div class="stat-card red">
            <h3>Open Issues</h3>
            <div class="number"><?= ($issue_counts['open'] ?? 0) + ($issue_counts['in_progress'] ?? 0) ?></div>
            <small>Websites</small>
        </div>
    </div>

    <!-- Mechanic Jobs Pipeline -->
    <div class="section-title">🔧 Mechanic Jobs Pipeline <a href="index.php?module=items/items&path=42">View All →</a></div>
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-body">
            <div class="pipeline">
                <?php foreach ($stages as $id => $stage):
                    $count = $jobs_by_stage[$id] ?? 0;
                    $color = $stage_colors[$id] ?? '#666';
                ?>
                <div class="pipeline-stage" style="background: <?= $color ?>">
                    <?= htmlspecialchars($stage['name']) ?>
                    <span class="count"><?= $count ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="grid">
        <!-- Recent Jobs -->
        <div class="card">
            <div class="card-header">🚗 Recent Jobs</div>
            <div class="card-body">
                <?php if (empty($recent_jobs)): ?>
                    <div class="empty">No jobs yet</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($recent_jobs as $job):
                        $stage_name = $stages[$job['stage']]['name'] ?? 'Unknown';
                        $stage_color = $stage_colors[$job['stage']] ?? '#666';
                    ?>
                    <li>
                        <div>
                            <div class="list-title"><?= htmlspecialchars($job['name']) ?></div>
                            <div class="list-sub"><?= htmlspecialchars($job['make'] . ' ' . $job['model']) ?></div>
                        </div>
                        <span class="badge" style="background: <?= $stage_color ?>"><?= $stage_name ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming Appointments -->
        <div class="card">
            <div class="card-header">📅 Upcoming Appointments</div>
            <div class="card-body">
                <?php if (empty($appointments)): ?>
                    <div class="empty">No upcoming appointments</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($appointments as $appt): ?>
                    <li>
                        <div>
                            <div class="list-title"><?= htmlspecialchars($appt['name']) ?></div>
                            <div class="list-sub"><?= htmlspecialchars($appt['make'] . ' ' . $appt['model']) ?></div>
                        </div>
                        <span class="badge" style="background: #667eea"><?= date('M j, g:i A', $appt['appt_time']) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SSL Expiring Soon Alert -->
    <?php
    $ssl_expiring = array_filter($websites, fn($s) => $s['ssl_days'] !== null && $s['ssl_days'] <= 30);
    if (!empty($ssl_expiring)):
        usort($ssl_expiring, fn($a, $b) => $a['ssl_days'] - $b['ssl_days']);
    ?>
    <div class="card" style="margin-bottom: 20px; border-left: 4px solid #f39c12;">
        <div class="card-header" style="background: #fff3e0;">🔒 SSL Certificates Expiring Soon</div>
        <div class="card-body">
            <ul class="list">
                <?php foreach ($ssl_expiring as $site): ?>
                <li>
                    <div>
                        <div class="list-title"><?= htmlspecialchars($site['domain']) ?></div>
                        <div class="list-sub">Expires: <?= $site['ssl_expires'] ?></div>
                    </div>
                    <span class="badge" style="background: <?= $site['ssl_days'] <= 7 ? '#e74c3c' : '#f39c12' ?>"><?= $site['ssl_days'] ?> days</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Domain Expiring Soon Alert -->
    <?php
    $domain_expiring = array_filter($websites, fn($s) => $s['domain_days'] !== null && $s['domain_days'] <= 60);
    if (!empty($domain_expiring)):
        usort($domain_expiring, fn($a, $b) => $a['domain_days'] - $b['domain_days']);
    ?>
    <div class="card" style="margin-bottom: 20px; border-left: 4px solid <?= $domain_expiring[0]['domain_days'] <= 14 ? '#e74c3c' : '#f39c12' ?>;">
        <div class="card-header" style="background: <?= $domain_expiring[0]['domain_days'] <= 14 ? '#ffebee' : '#fff3e0' ?>;">🌐 Domain Registrations Expiring Soon</div>
        <div class="card-body">
            <ul class="list">
                <?php foreach ($domain_expiring as $site): ?>
                <li>
                    <div>
                        <div class="list-title"><?= htmlspecialchars($site['domain']) ?></div>
                        <div class="list-sub">Expires: <?= $site['domain_expires'] ?></div>
                    </div>
                    <span class="badge" style="background: <?= $site['domain_days'] <= 14 ? '#e74c3c' : ($site['domain_days'] <= 30 ? '#f39c12' : '#3498db') ?>"><?= $site['domain_days'] ?> days</span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- Websites Section -->
    <div class="section-title">🌐 Websites <a href="index.php?module=items/items&path=37">Manage Sites →</a></div>
    <div class="website-grid">
        <?php foreach ($websites as $site):
            $health = $health_map[$site['health']] ?? ['name' => 'Unknown', 'color' => '#95a5a6'];
            $health_class = strtolower($health['name']);
            $type = $type_map[$site['type']] ?? 'Other';
        ?>
        <div class="website-card <?= $health_class ?>">
            <div class="website-domain">
                <span class="health-dot" style="background: <?= $health['color'] ?>"></span>
                <?= htmlspecialchars($site['domain']) ?>
            </div>
            <div class="website-type"><?= $type ?></div>
            <div class="website-stats">
                <span title="Response time"><?= $site['response_time'] ?>ms</span>
                <span title="24h uptime" style="color: <?= $site['uptime_pct'] >= 99 ? '#27ae60' : ($site['uptime_pct'] >= 95 ? '#f39c12' : '#e74c3c') ?>"><?= $site['uptime_pct'] ?>%</span>
                <?php if ($site['ssl_days'] !== null): ?>
                <span title="SSL expires <?= $site['ssl_expires'] ?>" style="color: <?= $site['ssl_days'] <= 7 ? '#e74c3c' : ($site['ssl_days'] <= 30 ? '#f39c12' : '#27ae60') ?>">🔒<?= $site['ssl_days'] ?>d</span>
                <?php endif; ?>
                <?php if ($site['domain_days'] !== null): ?>
                <span title="Domain expires <?= $site['domain_expires'] ?>" style="color: <?= $site['domain_days'] <= 14 ? '#e74c3c' : ($site['domain_days'] <= 30 ? '#f39c12' : '#27ae60') ?>">📅<?= $site['domain_days'] ?>d</span>
                <?php endif; ?>
                <?php if ($site['open_issues'] > 0): ?>
                <span style="color: #e74c3c;" title="Open issues">⚠<?= $site['open_issues'] ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Issues Section -->
    <div class="section-title">🐛 Open Issues <a href="index.php?module=items/items&path=43">View All →</a></div>
    <div class="card">
        <div class="card-body">
            <?php if (empty($open_issues)): ?>
                <div class="empty">No open issues - great job! 🎉</div>
            <?php else: ?>
            <ul class="list">
                <?php foreach ($open_issues as $issue):
                    $priority = $priority_map[$issue['priority']] ?? ['name' => 'Medium', 'color' => '#f39c12'];
                    $type = $issue_type_map[$issue['type']] ?? ['name' => 'Bug', 'color' => '#e74c3c'];
                ?>
                <li>
                    <div style="flex:1;">
                        <div class="list-title"><?= htmlspecialchars($issue['title']) ?></div>
                        <div class="list-sub"><?= htmlspecialchars($issue['site'] ?? 'No site') ?></div>
                    </div>
                    <span class="badge" style="background: <?= $type['color'] ?>"><?= $type['name'] ?></span>
                    <span class="badge" style="background: <?= $priority['color'] ?>"><?= $priority['name'] ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- AI & Insights Section -->
    <div class="section-title">🤖 AI Sessions & Insights</div>
    <div class="grid grid-3">
        <!-- Claude Sessions -->
        <div class="card">
            <div class="card-header">💬 Recent Sessions <a href="index.php?module=items/items&path=30">View →</a></div>
            <div class="card-body">
                <?php if (empty($sessions)): ?>
                    <div class="empty">No sessions yet</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($sessions as $session): ?>
                    <li>
                        <div>
                            <div class="list-title"><?= htmlspecialchars($session['title'] ?: 'Session #' . $session['id']) ?></div>
                            <div class="list-sub"><?= date('M j, Y', $session['date_added']) ?></div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Insights -->
        <div class="card">
            <div class="card-header">💡 Insights <a href="index.php?module=items/items&path=35">View →</a></div>
            <div class="card-body">
                <?php if (empty($insights)): ?>
                    <div class="empty">No insights yet</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($insights as $insight): ?>
                    <li>
                        <span class="list-title" style="flex:1;"><?= htmlspecialchars(substr($insight['insight'], 0, 50)) ?>...</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Items -->
        <div class="card">
            <div class="card-header">✅ Actions <a href="index.php?module=items/items&path=36">View →</a></div>
            <div class="card-body">
                <?php if (empty($actions)): ?>
                    <div class="empty">No actions yet</div>
                <?php else: ?>
                <ul class="list">
                    <?php foreach ($actions as $action): ?>
                    <li>
                        <span class="list-title" style="flex:1;"><?= htmlspecialchars(substr($action['task'], 0, 40)) ?>...</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="section-title">⚡ Quick Actions</div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="index.php?module=items/items&path=42&action=new" style="padding: 10px 20px; background: #667eea; color: white; border-radius: 8px; text-decoration: none; font-size: 13px;">+ New Job</a>
        <a href="index.php?module=items/items&path=43&action=new" style="padding: 10px 20px; background: #e74c3c; color: white; border-radius: 8px; text-decoration: none; font-size: 13px;">+ Report Issue</a>
        <a href="index.php?module=items/items&path=37&action=new" style="padding: 10px 20px; background: #27ae60; color: white; border-radius: 8px; text-decoration: none; font-size: 13px;">+ Add Website</a>
        <a href="index.php?module=ext/ipages/view&id=1" style="padding: 10px 20px; background: #9b59b6; color: white; border-radius: 8px; text-decoration: none; font-size: 13px;">💬 Claude Chat</a>
    </div>
</div>
