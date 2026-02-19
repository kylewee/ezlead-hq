<?php
/**
 * System Monitor - Checks HQ health and logs alerts
 * Cron: Run every 5 minutes
 * Crontab entry: STAR/5 * * * * php /home/kylewee/code/master-template/cron/system_monitor.php
 */

// Email configuration
$alertEmail = 'sodjacksonville@gmail.com';
$fromEmail = 'monitor@ezlead4u.com';

$dbPath = __DIR__ . '/../data/buyers.db';
$db = new SQLite3($dbPath);

// Create alerts table if not exists
$db->exec("CREATE TABLE IF NOT EXISTS system_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    alert_type TEXT NOT NULL,
    severity TEXT NOT NULL,
    message TEXT NOT NULL,
    details TEXT,
    is_resolved INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME
)");

// Create system_stats table for metrics
$db->exec("CREATE TABLE IF NOT EXISTS system_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    stat_type TEXT NOT NULL,
    stat_value TEXT NOT NULL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

function sendAlertEmail($to, $from, $severity, $message, $details) {
    $subject = "[EzLead4U " . strtoupper($severity) . "] " . $message;
    $body = "ALERT: $message\n\n";
    $body .= "Severity: $severity\n";
    $body .= "Time: " . date('Y-m-d H:i:s') . "\n";
    if ($details) {
        $body .= "Details: $details\n";
    }
    $body .= "\n--\nEzLead4U System Monitor\nhttps://ezlead4u.com/admin/";

    $headers = "From: $from\r\n";
    $headers .= "X-Priority: " . ($severity === 'critical' ? '1' : '3') . "\r\n";

    return @mail($to, $subject, $body, $headers);
}

function logAlert($db, $type, $severity, $message, $details = null) {
    global $alertEmail, $fromEmail;

    // Check if same unresolved alert exists in last hour
    $stmt = $db->prepare("SELECT id FROM system_alerts
        WHERE alert_type = :type AND is_resolved = 0
        AND created_at > datetime('now', '-1 hour')");
    $stmt->bindValue(':type', $type);
    $existing = $stmt->execute()->fetchArray();

    if (!$existing) {
        $stmt = $db->prepare("INSERT INTO system_alerts (alert_type, severity, message, details) VALUES (:type, :sev, :msg, :det)");
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':sev', $severity);
        $stmt->bindValue(':msg', $message);
        $stmt->bindValue(':det', $details);
        $stmt->execute();

        // Send email alert for critical issues
        if ($severity === 'critical') {
            sendAlertEmail($alertEmail, $fromEmail, $severity, $message, $details);
        }

        return true;
    }
    return false;
}

function logStat($db, $type, $value) {
    $stmt = $db->prepare("INSERT INTO system_stats (stat_type, stat_value) VALUES (:type, :val)");
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':val', $value);
    $stmt->execute();
}

function checkService($name, $command) {
    exec($command, $output, $code);
    return $code === 0;
}

$alerts = [];
$stats = [];

// 1. Check ezlead4u API
$ch = curl_init('http://127.0.0.1:8000/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    logAlert($db, 'api_down', 'critical', 'ezlead4u API is not responding', "HTTP $httpCode");
} else {
    // Auto-resolve if was down
    $db->exec("UPDATE system_alerts SET is_resolved = 1, resolved_at = datetime('now')
        WHERE alert_type = 'api_down' AND is_resolved = 0");
}
$stats['api_status'] = $httpCode === 200 ? 'up' : 'down';

// 2. Check systemd services
$services = [
    'ezlead4u' => 'systemctl is-active ezlead4u',
    'ezlead4u-worker' => 'systemctl is-active ezlead4u-worker',
    'redis-server' => 'systemctl is-active redis-server',
    'postgresql' => 'systemctl is-active postgresql',
    'caddy' => 'systemctl is-active caddy',
    'php8.3-fpm' => 'systemctl is-active php8.3-fpm'
];

foreach ($services as $name => $cmd) {
    exec($cmd, $out, $code);
    if ($code !== 0) {
        logAlert($db, "service_$name", 'critical', "$name service is down");
    } else {
        $db->exec("UPDATE system_alerts SET is_resolved = 1, resolved_at = datetime('now')
            WHERE alert_type = 'service_$name' AND is_resolved = 0");
    }
    $stats["service_$name"] = $code === 0 ? 'up' : 'down';
}

// 3. Check pending deliveries via API
$ch = curl_init('http://127.0.0.1:8000/api/v1/delivery?status=PENDING&limit=100');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$pendingCount = 0;
if (isset($data['data']) && is_array($data['data'])) {
    $pendingCount = count($data['data']);
}

if ($pendingCount > 5) {
    logAlert($db, 'pending_deliveries', 'warning', "$pendingCount leads pending delivery", "Check worker and buyer webhooks");
}
$stats['pending_deliveries'] = $pendingCount;

// 4. Check failed deliveries
$ch = curl_init('http://127.0.0.1:8000/api/v1/delivery?status=FAILED&limit=100');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$failedCount = 0;
if (isset($data['data']) && is_array($data['data'])) {
    $failedCount = count($data['data']);
}

if ($failedCount > 0) {
    logAlert($db, 'failed_deliveries', 'warning', "$failedCount leads failed to deliver", "Run retry or check buyer URLs");
}
$stats['failed_deliveries'] = $failedCount;

// 5. Check lead flow (any leads in last 24h?)
$ch = curl_init('http://127.0.0.1:8000/api/v1/sources');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$totalLeads = 0;
if (isset($data['data']) && is_array($data['data'])) {
    foreach ($data['data'] as $source) {
        $totalLeads += $source['total_posts'] ?? 0;
    }
}
$stats['total_leads'] = $totalLeads;

// 6. Check disk space
$freeSpace = disk_free_space('/');
$totalSpace = disk_total_space('/');
$usedPercent = round((1 - $freeSpace / $totalSpace) * 100);

if ($usedPercent > 90) {
    logAlert($db, 'disk_space', 'critical', "Disk usage at {$usedPercent}%", "Free up space immediately");
} elseif ($usedPercent > 80) {
    logAlert($db, 'disk_space', 'warning', "Disk usage at {$usedPercent}%", "Consider cleaning up");
}
$stats['disk_usage'] = $usedPercent;

// Log stats
foreach ($stats as $type => $value) {
    logStat($db, $type, $value);
}

// Cleanup old stats (keep 7 days)
$db->exec("DELETE FROM system_stats WHERE recorded_at < datetime('now', '-7 days')");

// Output summary
echo date('Y-m-d H:i:s') . " Monitor complete\n";
echo "Stats: " . json_encode($stats) . "\n";

$db->close();
