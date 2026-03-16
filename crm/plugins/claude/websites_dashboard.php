<?php
// Websites Dashboard API endpoint
session_start();

require_once(__DIR__ . '/../../config/database.php');

$conn = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
if ($conn->connect_error) {
    die(json_encode(['error' => 'Database connection failed']));
}

header('Content-Type: application/json');

// Entity IDs
define('WEBSITES_ENTITY', 37);
define('ISSUES_ENTITY', 43);
define('ANALYTICS_ENTITY', 44);
define('SITE_NOTES_ENTITY', 45);
define('UPTIME_ENTITY', 46);

// Field IDs for Websites
define('WEBSITES_DOMAIN', 333);
define('WEBSITES_HEALTH', 410);
define('WEBSITES_LAST_CHECK', 412);
define('WEBSITES_OPEN_ISSUES', 415);

// Field IDs for Issues
define('ISSUES_TITLE', 380);
define('ISSUES_PRIORITY', 382);
define('ISSUES_STATUS', 383);

// Field IDs for Uptime
define('UPTIME_CHECK_TIME', 420);
define('UPTIME_STATUS', 421);
define('UPTIME_RESPONSE', 422);

// Field IDs for Analytics
define('ANALYTICS_PAGE_URL', 385);

$response = [];

// Dropdown choice ID -> display label maps
$healthLabels = ['116' => 'Healthy', '117' => 'Warning', '118' => 'Down', '119' => 'Unknown'];
$issueStatusLabels = ['107' => 'Open', '108' => 'In Progress', '109' => 'Resolved', '110' => 'Closed'];
$issuePriorityLabels = ['103' => 'Critical', '104' => 'High', '105' => 'Medium', '106' => 'Low'];
$uptimeStatusLabels = ['120' => 'Up', '121' => 'Slow', '122' => 'Down'];

// Get stats
$total = $conn->query("SELECT COUNT(*) as cnt FROM app_entity_" . WEBSITES_ENTITY)->fetch_assoc()['cnt'];
$healthy = $conn->query("SELECT COUNT(*) as cnt FROM app_entity_" . WEBSITES_ENTITY . " WHERE field_" . WEBSITES_HEALTH . " = '116'")->fetch_assoc()['cnt'];
$openIssues = $conn->query("SELECT COUNT(*) as cnt FROM app_entity_" . ISSUES_ENTITY . " WHERE field_" . ISSUES_STATUS . " IN ('107', '108')")->fetch_assoc()['cnt'];
$totalPageviews = $conn->query("SELECT COUNT(*) as cnt FROM app_entity_" . ANALYTICS_ENTITY)->fetch_assoc()['cnt'];
$todayPageviews = $conn->query("SELECT COUNT(*) as cnt FROM app_entity_" . ANALYTICS_ENTITY . " WHERE date_added >= UNIX_TIMESTAMP(CURDATE())")->fetch_assoc()['cnt'];

$response['stats'] = [
    'total_sites' => (int)$total,
    'healthy' => (int)$healthy,
    'unhealthy' => (int)$total - (int)$healthy,
    'open_issues' => (int)$openIssues,
    'total_pageviews' => (int)$totalPageviews,
    'today_pageviews' => (int)$todayPageviews
];

// Get websites with analytics counts
$sitesResult = $conn->query("
    SELECT 
        w.id,
        w.field_" . WEBSITES_DOMAIN . " as domain,
        w.field_" . WEBSITES_HEALTH . " as health,
        w.field_" . WEBSITES_LAST_CHECK . " as last_check,
        w.field_" . WEBSITES_OPEN_ISSUES . " as open_issues,
        (SELECT COUNT(*) FROM app_entity_" . SITE_NOTES_ENTITY . " n WHERE n.parent_item_id = w.id) as notes_count,
        (SELECT COUNT(*) FROM app_entity_" . ANALYTICS_ENTITY . " a WHERE a.parent_item_id = w.id) as pageviews,
        (SELECT COUNT(*) FROM app_entity_" . ANALYTICS_ENTITY . " a WHERE a.parent_item_id = w.id AND a.date_added >= UNIX_TIMESTAMP(CURDATE())) as today_views
    FROM app_entity_" . WEBSITES_ENTITY . " w
    ORDER BY COALESCE(w.field_" . WEBSITES_OPEN_ISSUES . ", 0) DESC, w.field_" . WEBSITES_DOMAIN . " ASC
    LIMIT 20
");

$sites = [];
while ($row = $sitesResult->fetch_assoc()) {
    $lastCheck = $row['last_check'];
    if ($lastCheck && is_numeric($lastCheck)) {
        $lastCheck = date('M j, g:ia', $lastCheck);
    } elseif ($lastCheck) {
        $lastCheck = date('M j, g:ia', strtotime($lastCheck));
    }
    $sites[] = [
        'id' => $row['id'],
        'domain' => $row['domain'],
        'health' => $healthLabels[$row['health']] ?? 'Unknown',
        'last_check' => $lastCheck ?: 'Never',
        'open_issues' => (int)$row['open_issues'],
        'notes_count' => (int)$row['notes_count'],
        'pageviews' => (int)$row['pageviews'],
        'today_views' => (int)$row['today_views']
    ];
}
$response['sites'] = $sites;

// Get recent issues
$issuesResult = $conn->query("
    SELECT 
        i.id,
        i.field_" . ISSUES_TITLE . " as title,
        i.field_" . ISSUES_PRIORITY . " as priority,
        i.field_" . ISSUES_STATUS . " as status,
        w.field_" . WEBSITES_DOMAIN . " as site
    FROM app_entity_" . ISSUES_ENTITY . " i
    LEFT JOIN app_entity_" . WEBSITES_ENTITY . " w ON i.parent_item_id = w.id
    ORDER BY i.date_added DESC
    LIMIT 10
");

$issues = [];
while ($row = $issuesResult->fetch_assoc()) {
    $issues[] = [
        'id' => $row['id'],
        'title' => $row['title'],
        'priority' => $issuePriorityLabels[$row['priority']] ?? 'Medium',
        'status' => $issueStatusLabels[$row['status']] ?? 'Open',
        'site' => $row['site']
    ];
}
$response['issues'] = $issues;

// Get recent uptime checks
$uptimeResult = $conn->query("
    SELECT 
        u.id,
        u.field_" . UPTIME_CHECK_TIME . " as check_time,
        u.field_" . UPTIME_STATUS . " as status,
        u.field_" . UPTIME_RESPONSE . " as response_time,
        w.field_" . WEBSITES_DOMAIN . " as domain
    FROM app_entity_" . UPTIME_ENTITY . " u
    LEFT JOIN app_entity_" . WEBSITES_ENTITY . " w ON u.parent_item_id = w.id
    ORDER BY u.date_added DESC
    LIMIT 10
");

$uptime = [];
while ($row = $uptimeResult->fetch_assoc()) {
    $checkTime = $row['check_time'];
    if ($checkTime && is_numeric($checkTime)) {
        $checkTime = date('M j, g:ia', $checkTime);
    } elseif ($checkTime) {
        $checkTime = date('M j, g:ia', strtotime($checkTime));
    }
    $uptime[] = [
        'id' => $row['id'],
        'domain' => $row['domain'],
        'status' => $uptimeStatusLabels[$row['status']] ?? 'Unknown',
        'response_time' => $row['response_time'],
        'check_time' => $checkTime ?: 'Unknown'
    ];
}
$response['uptime'] = $uptime;

// Get recent analytics
$analyticsResult = $conn->query("
    SELECT 
        a.id,
        a.field_" . ANALYTICS_PAGE_URL . " as page_url,
        a.date_added,
        w.field_" . WEBSITES_DOMAIN . " as domain
    FROM app_entity_" . ANALYTICS_ENTITY . " a
    LEFT JOIN app_entity_" . WEBSITES_ENTITY . " w ON a.parent_item_id = w.id
    ORDER BY a.date_added DESC
    LIMIT 10
");

$analytics = [];
while ($row = $analyticsResult->fetch_assoc()) {
    $analytics[] = [
        'id' => $row['id'],
        'domain' => $row['domain'],
        'page_url' => $row['page_url'],
        'time' => date('M j, g:ia', strtotime($row['date_added']))
    ];
}
$response['analytics'] = $analytics;

echo json_encode($response);
$conn->close();
