<?php
// Analytics Stats Module - Action Handler

$app_title = app_set_title('Analytics Dashboard');

// Get stats from database
$websites_count = db_count('app_entity_37');
$total_views = db_count('app_entity_44');
$today_views = db_count('app_entity_44', "date_added >= " . strtotime('today'));
$unique_visitors = db_fetch_one("SELECT COUNT(DISTINCT field_389) as c FROM app_entity_44")['c'] ?? 0;

// Get website stats
$site_stats = db_query("SELECT w.field_333 as domain, COUNT(a.id) as views, COUNT(DISTINCT a.field_389) as visitors
    FROM app_entity_37 w LEFT JOIN app_entity_44 a ON a.parent_item_id = w.id
    GROUP BY w.id ORDER BY views DESC LIMIT 10");

// Get recent activity
$recent_activity = db_query("SELECT field_385 as url, field_387 as browser, field_388 as device, FROM_UNIXTIME(date_added) as time 
    FROM app_entity_44 ORDER BY id DESC LIMIT 10");

// Pass to view
$stats = [
    'websites' => $websites_count,
    'total_views' => $total_views,
    'today_views' => $today_views,
    'unique_visitors' => $unique_visitors,
    'site_stats' => $site_stats,
    'recent' => $recent_activity
];
