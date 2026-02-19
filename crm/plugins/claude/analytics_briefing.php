<?php
/**
 * Analytics Briefing - Dashboard Widget & JSON API
 */
require_once(__DIR__ . '/../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    if (isset($_GET['json'])) {
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database error']));
    }
    die("Connection failed");
}

// Get metrics
$websites = $conn->query("SELECT COUNT(*) as c FROM app_entity_37")->fetch_assoc()['c'];
$total_views = $conn->query("SELECT COUNT(*) as c FROM app_entity_44")->fetch_assoc()['c'];
$today_views = $conn->query("SELECT COUNT(*) as c FROM app_entity_44 WHERE date_added >= UNIX_TIMESTAMP(CURDATE())")->fetch_assoc()['c'];
$unique_visitors = $conn->query("SELECT COUNT(DISTINCT field_389) as c FROM app_entity_44")->fetch_assoc()['c'];

// JSON API response
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'websites' => (int)$websites,
        'today' => (int)$today_views,
        'total' => (int)$total_views,
        'visitors' => (int)$unique_visitors
    ]);
    $conn->close();
    exit;
}

// Get top pages today
$top_pages = [];
$result = $conn->query("SELECT field_385 as url, COUNT(*) as hits FROM app_entity_44 WHERE date_added >= UNIX_TIMESTAMP(CURDATE()) GROUP BY field_385 ORDER BY hits DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $top_pages[] = $row;

// Get website stats
$site_stats = [];
$result = $conn->query("SELECT w.field_333 as domain, COUNT(a.id) as views, COUNT(DISTINCT a.field_389) as visitors
    FROM app_entity_37 w LEFT JOIN app_entity_44 a ON a.parent_item_id = w.id
    GROUP BY w.id ORDER BY views DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $site_stats[] = $row;

// Recent activity
$recent = [];
$result = $conn->query("SELECT field_385 as url, field_387 as browser, field_388 as device, FROM_UNIXTIME(date_added) as time 
    FROM app_entity_44 ORDER BY id DESC LIMIT 5");
while ($row = $result->fetch_assoc()) $recent[] = $row;

$conn->close();
?>
<style>
.briefing { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; padding: 20px; }
.briefing h2 { margin: 0 0 20px; color: #333; font-size: 24px; }
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px; }
.stat-box { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 12px; text-align: center; }
.stat-box.green { background: linear-gradient(135deg, #11998e, #38ef7d); }
.stat-box.blue { background: linear-gradient(135deg, #4facfe, #00f2fe); }
.stat-box.orange { background: linear-gradient(135deg, #f093fb, #f5576c); }
.stat-box h3 { margin: 0; font-size: 28px; }
.stat-box p { margin: 5px 0 0; opacity: 0.9; font-size: 12px; text-transform: uppercase; }
.section { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
.section h4 { margin: 0 0 15px; color: #333; font-size: 16px; border-bottom: 2px solid #eee; padding-bottom: 10px; }
.table { width: 100%; border-collapse: collapse; }
.table th, .table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; font-size: 13px; }
.table th { color: #666; font-weight: 600; }
.badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; background: #e8e8e8; }
</style>

<div class="briefing">
    <h2>📊 Analytics Briefing</h2>
    
    <div class="stats-grid">
        <div class="stat-box"><h3><?= $websites ?></h3><p>Websites</p></div>
        <div class="stat-box green"><h3><?= $today_views ?></h3><p>Today</p></div>
        <div class="stat-box blue"><h3><?= $total_views ?></h3><p>Total Views</p></div>
        <div class="stat-box orange"><h3><?= $unique_visitors ?></h3><p>Visitors</p></div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
        <div class="section">
            <h4>🌐 By Website</h4>
            <table class="table">
                <tr><th>Site</th><th>Views</th><th>Visitors</th></tr>
                <?php foreach ($site_stats as $s): ?>
                <tr><td><?= htmlspecialchars($s['domain']) ?></td><td><?= $s['views'] ?></td><td><?= $s['visitors'] ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <div class="section">
            <h4>🕐 Recent</h4>
            <table class="table">
                <tr><th>Page</th><th>Browser</th></tr>
                <?php foreach ($recent as $r): ?>
                <tr><td><?= htmlspecialchars(substr($r['url'], -30)) ?></td><td><span class="badge"><?= $r['browser'] ?></span></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>
</div>
