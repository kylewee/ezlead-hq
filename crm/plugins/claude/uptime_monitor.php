<?php
/**
 * Website Uptime Monitor
 * Checks all websites and updates health status
 * Runs every 5 minutes via cron
 */

$conn = new mysqli('localhost', 'kylewee', 'rainonin', 'rukovoditel');
if ($conn->connect_error) {
    die("Database connection failed\n");
}

// Get health status IDs
$health_ids = [];
$result = $conn->query("SELECT id, name FROM app_fields_choices WHERE fields_id = 410");
while ($row = $result->fetch_assoc()) {
    $health_ids[strtolower($row['name'])] = $row['id'];
}

$alert_email = "kyle@ezlead4u.com";
$now = time();

/**
 * Check domain registration expiration via WHOIS
 */
function check_domain_expiry($domain) {
    // Remove subdomain - get root domain
    $parts = explode('.', $domain);
    if (count($parts) > 2) {
        // Handle .co.uk, .com.au etc
        $tld = array_pop($parts);
        $sld = array_pop($parts);
        if (strlen($sld) <= 3) {
            $domain = array_pop($parts) . '.' . $sld . '.' . $tld;
        } else {
            $domain = $sld . '.' . $tld;
        }
    }

    // WHOIS servers by TLD
    $whois_servers = [
        'com' => 'whois.verisign-grs.com',
        'net' => 'whois.verisign-grs.com',
        'org' => 'whois.pir.org',
        'us' => 'whois.nic.us',
        'co' => 'whois.nic.co',
        'io' => 'whois.nic.io',
        'company' => 'whois.nic.company',
        'contractors' => 'whois.nic.contractors',
    ];

    $tld = strtolower(pathinfo($domain, PATHINFO_EXTENSION));
    $server = $whois_servers[$tld] ?? "whois.nic.{$tld}";

    $fp = @fsockopen($server, 43, $errno, $errstr, 10);
    if (!$fp) {
        return ['valid' => false, 'error' => "Could not connect to WHOIS server: $errstr"];
    }

    fputs($fp, $domain . "\r\n");
    $response = '';
    while (!feof($fp)) {
        $response .= fgets($fp, 128);
    }
    fclose($fp);

    // Parse expiration date from various formats
    $patterns = [
        '/Registry Expiry Date:\s*(.+)/i',
        '/Registrar Registration Expiration Date:\s*(.+)/i',
        '/Expiration Date:\s*(.+)/i',
        '/Expiry Date:\s*(.+)/i',
        '/paid-till:\s*(.+)/i',
        '/expires:\s*(.+)/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $response, $matches)) {
            $expiry_str = trim($matches[1]);
            $expiry_time = strtotime($expiry_str);
            if ($expiry_time) {
                $days_left = floor(($expiry_time - time()) / 86400);
                return [
                    'valid' => true,
                    'expires' => $expiry_time,
                    'expires_date' => date('Y-m-d', $expiry_time),
                    'days_left' => $days_left
                ];
            }
        }
    }

    return ['valid' => false, 'error' => 'Could not parse expiration date'];
}

/**
 * Check SSL certificate expiration
 */
function check_ssl($domain) {
    $context = stream_context_create([
        'ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
        ]
    ]);

    $domain = preg_replace('/^https?:\/\//', '', $domain);
    $domain = preg_replace('/\/.*$/', '', $domain);

    $client = @stream_socket_client(
        "ssl://{$domain}:443",
        $errno,
        $errstr,
        10,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$client) {
        return ['valid' => false, 'error' => $errstr, 'expires' => null, 'days_left' => null];
    }

    $params = stream_context_get_params($client);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    fclose($client);

    if (!$cert) {
        return ['valid' => false, 'error' => 'Could not parse certificate', 'expires' => null, 'days_left' => null];
    }

    $expires = $cert['validTo_time_t'];
    $days_left = floor(($expires - time()) / 86400);

    return [
        'valid' => true,
        'expires' => $expires,
        'expires_date' => date('Y-m-d', $expires),
        'days_left' => $days_left,
        'issuer' => $cert['issuer']['O'] ?? 'Unknown'
    ];
}

/**
 * Check if a URL is accessible
 */
function check_url($url) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_NOBODY => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'EzLead Uptime Monitor/1.0'
    ]);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $response_time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $http_code,
        'response_time' => round($response_time * 1000), // ms
        'error' => $error,
        'is_up' => ($http_code >= 200 && $http_code < 400)
    ];
}

/**
 * Send alert email
 */
function send_alert($to, $subject, $body) {
    $headers = "From: Uptime Monitor <noreply@ezlead4u.com>\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    return mail($to, $subject, $body, $headers);
}

// Get all websites
$websites = [];
$result = $conn->query("SELECT id, field_333 as domain, field_334 as url, field_410 as health, field_413 as ssl_expires, field_416 as domain_expires FROM app_entity_37");
while ($row = $result->fetch_assoc()) {
    $websites[] = $row;
}

echo "=== Uptime Check: " . date('Y-m-d H:i:s') . " ===\n";

$down_sites = [];
$warning_sites = [];
$ssl_alerts = [];
$domain_alerts = [];

foreach ($websites as $site) {
    $url = $site['url'];
    if (empty($url)) {
        $url = 'https://' . $site['domain'];
    }

    // Ensure URL has protocol
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'https://' . $url;
    }

    $check = check_url($url);
    $old_health = $site['health'];

    // Determine new health status
    if ($check['is_up']) {
        if ($check['response_time'] > 3000) {
            // Slow response = warning
            $new_health = $health_ids['warning'] ?? 117;
            $status = "WARNING (slow: {$check['response_time']}ms)";
            $warning_sites[] = $site['domain'];
        } else {
            $new_health = $health_ids['healthy'] ?? 116;
            $status = "UP ({$check['response_time']}ms)";
        }
    } else {
        $new_health = $health_ids['down'] ?? 118;
        $status = "DOWN (HTTP {$check['http_code']})";
        if ($check['error']) {
            $status .= " - {$check['error']}";
        }
        $down_sites[] = $site['domain'];
    }

    // Update database
    $conn->query("UPDATE app_entity_37 SET
                  field_410 = $new_health,
                  field_412 = $now
                  WHERE id = " . intval($site['id']));

    // Log to uptime history
    $log_status = $check['is_up'] ? ($check['response_time'] > 3000 ? 121 : 120) : 122; // 120=Up, 121=Slow, 122=Down
    $error_escaped = $conn->real_escape_string($check['error'] ?? '');
    $conn->query("INSERT INTO app_entity_46 (parent_item_id, field_420, field_421, field_422, field_423, field_424, date_added, created_by)
                  VALUES (" . intval($site['id']) . ", $now, $log_status, {$check['response_time']}, {$check['http_code']}, '$error_escaped', $now, 1)");

    // Check SSL certificate (only once per day per site, or if not set)
    $ssl_check_needed = empty($site['ssl_expires']) || (date('Y-m-d') != date('Y-m-d', strtotime($site['ssl_expires'] ?? '')));

    // Check SSL for HTTPS sites
    if (strpos($url, 'https') === 0) {
        $ssl = check_ssl($site['domain']);
        if ($ssl['valid']) {
            $ssl_expires = $ssl['expires_date'];
            $days_left = $ssl['days_left'];

            // Update SSL expiry in database
            $conn->query("UPDATE app_entity_37 SET field_413 = '$ssl_expires' WHERE id = " . intval($site['id']));

            // Alert if expiring soon
            if ($days_left <= 7) {
                $ssl_alerts[] = ['domain' => $site['domain'], 'days' => $days_left, 'expires' => $ssl_expires, 'level' => 'critical'];
                $status .= " | SSL: {$days_left}d ⚠️";
            } elseif ($days_left <= 14) {
                $ssl_alerts[] = ['domain' => $site['domain'], 'days' => $days_left, 'expires' => $ssl_expires, 'level' => 'warning'];
                $status .= " | SSL: {$days_left}d";
            } elseif ($days_left <= 30) {
                $ssl_alerts[] = ['domain' => $site['domain'], 'days' => $days_left, 'expires' => $ssl_expires, 'level' => 'notice'];
            }
        }
    }

    // Check domain registration expiry (once per day at midnight hour)
    if (date('H') == '00' || empty($site['domain_expires'])) {
        $domain_info = check_domain_expiry($site['domain']);
        if ($domain_info['valid']) {
            $domain_expires = $domain_info['expires_date'];
            $domain_days = $domain_info['days_left'];

            // Update domain expiry in database
            $conn->query("UPDATE app_entity_37 SET field_416 = '$domain_expires' WHERE id = " . intval($site['id']));

            // Alert if expiring soon
            if ($domain_days <= 14) {
                $domain_alerts[] = ['domain' => $site['domain'], 'days' => $domain_days, 'expires' => $domain_expires, 'level' => 'critical'];
            } elseif ($domain_days <= 30) {
                $domain_alerts[] = ['domain' => $site['domain'], 'days' => $domain_days, 'expires' => $domain_expires, 'level' => 'warning'];
            } elseif ($domain_days <= 60) {
                $domain_alerts[] = ['domain' => $site['domain'], 'days' => $domain_days, 'expires' => $domain_expires, 'level' => 'notice'];
            }
        }
    }

    // Log status change
    if ($old_health != $new_health) {
        echo "[CHANGE] ";
    }
    echo "{$site['domain']}: $status\n";

    // Alert if site went down
    if ($new_health == ($health_ids['down'] ?? 118) && $old_health != $new_health) {
        $subject = "🔴 SITE DOWN: {$site['domain']}";
        $body = "
        <h2 style='color: #e74c3c;'>⚠️ Website Down Alert</h2>
        <p><strong>Site:</strong> {$site['domain']}</p>
        <p><strong>URL:</strong> $url</p>
        <p><strong>Status:</strong> $status</p>
        <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p>Please check the site immediately.</p>
        ";
        send_alert($alert_email, $subject, $body);
        echo "  → Alert sent to $alert_email\n";
    }

    // Alert if site came back up
    if ($new_health == ($health_ids['healthy'] ?? 116) && $old_health == ($health_ids['down'] ?? 118)) {
        $subject = "🟢 SITE RECOVERED: {$site['domain']}";
        $body = "
        <h2 style='color: #27ae60;'>✅ Website Recovered</h2>
        <p><strong>Site:</strong> {$site['domain']}</p>
        <p><strong>URL:</strong> $url</p>
        <p><strong>Response Time:</strong> {$check['response_time']}ms</p>
        <p><strong>Time:</strong> " . date('Y-m-d H:i:s') . "</p>
        ";
        send_alert($alert_email, $subject, $body);
        echo "  → Recovery alert sent\n";
    }
}

// Summary
echo "\n=== Summary ===\n";
echo "Total sites: " . count($websites) . "\n";
echo "Down: " . count($down_sites) . "\n";
echo "Warning: " . count($warning_sites) . "\n";
echo "Healthy: " . (count($websites) - count($down_sites) - count($warning_sites)) . "\n";

if (!empty($down_sites)) {
    echo "\nDown sites: " . implode(', ', $down_sites) . "\n";
}
if (!empty($warning_sites)) {
    echo "Slow sites: " . implode(', ', $warning_sites) . "\n";
}

// SSL Expiry Alerts
if (!empty($ssl_alerts)) {
    echo "\n=== SSL Certificates ===\n";

    $critical = array_filter($ssl_alerts, fn($a) => $a['level'] == 'critical');
    $warning = array_filter($ssl_alerts, fn($a) => $a['level'] == 'warning');
    $notice = array_filter($ssl_alerts, fn($a) => $a['level'] == 'notice');

    foreach ($ssl_alerts as $alert) {
        $icon = $alert['level'] == 'critical' ? '🔴' : ($alert['level'] == 'warning' ? '🟡' : '🟢');
        echo "$icon {$alert['domain']}: {$alert['days']} days (expires {$alert['expires']})\n";
    }

    // Send critical SSL alerts (7 days or less) - once per day
    if (!empty($critical)) {
        $alert_file = '/tmp/ssl_alert_' . date('Y-m-d') . '.sent';
        if (!file_exists($alert_file)) {
            $subject = "🔴 SSL EXPIRING: " . count($critical) . " certificate(s) expiring within 7 days!";
            $body = "<h2 style='color: #e74c3c;'>⚠️ SSL Certificate Expiry Alert</h2>";
            $body .= "<p>The following SSL certificates are expiring soon:</p><ul>";
            foreach ($critical as $alert) {
                $body .= "<li><strong>{$alert['domain']}</strong> - {$alert['days']} days left (expires {$alert['expires']})</li>";
            }
            $body .= "</ul><p>Please renew these certificates immediately to avoid site outages.</p>";
            send_alert($alert_email, $subject, $body);
            file_put_contents($alert_file, date('Y-m-d H:i:s'));
            echo "→ Critical SSL alert sent\n";
        }
    }

    // Send warning SSL alerts (14 days or less) - once per day
    if (!empty($warning)) {
        $alert_file = '/tmp/ssl_warning_' . date('Y-m-d') . '.sent';
        if (!file_exists($alert_file)) {
            $subject = "🟡 SSL WARNING: " . count($warning) . " certificate(s) expiring within 14 days";
            $body = "<h2 style='color: #f39c12;'>SSL Certificate Warning</h2>";
            $body .= "<p>The following SSL certificates will expire soon:</p><ul>";
            foreach ($warning as $alert) {
                $body .= "<li><strong>{$alert['domain']}</strong> - {$alert['days']} days left (expires {$alert['expires']})</li>";
            }
            $body .= "</ul><p>Plan to renew these certificates soon.</p>";
            send_alert($alert_email, $subject, $body);
            file_put_contents($alert_file, date('Y-m-d H:i:s'));
            echo "→ SSL warning alert sent\n";
        }
    }
}

// Domain Registration Expiry Alerts
if (!empty($domain_alerts)) {
    echo "\n=== Domain Registration ===\n";

    $critical = array_filter($domain_alerts, fn($a) => $a['level'] == 'critical');
    $warning = array_filter($domain_alerts, fn($a) => $a['level'] == 'warning');

    foreach ($domain_alerts as $alert) {
        $icon = $alert['level'] == 'critical' ? '🔴' : ($alert['level'] == 'warning' ? '🟡' : '🟢');
        echo "$icon {$alert['domain']}: {$alert['days']} days (expires {$alert['expires']})\n";
    }

    // Send critical domain alerts (14 days or less) - once per day
    if (!empty($critical)) {
        $alert_file = '/tmp/domain_alert_' . date('Y-m-d') . '.sent';
        if (!file_exists($alert_file)) {
            $subject = "🔴 DOMAIN EXPIRING: " . count($critical) . " domain(s) expiring within 14 days!";
            $body = "<h2 style='color: #e74c3c;'>⚠️ Domain Registration Expiry Alert</h2>";
            $body .= "<p>The following domains are expiring soon:</p><ul>";
            foreach ($critical as $alert) {
                $body .= "<li><strong>{$alert['domain']}</strong> - {$alert['days']} days left (expires {$alert['expires']})</li>";
            }
            $body .= "</ul><p><strong>RENEW IMMEDIATELY</strong> to avoid losing your domain!</p>";
            send_alert($alert_email, $subject, $body);
            file_put_contents($alert_file, date('Y-m-d H:i:s'));
            echo "→ Critical domain alert sent\n";
        }
    }

    // Send warning domain alerts (30 days or less) - once per day
    if (!empty($warning)) {
        $alert_file = '/tmp/domain_warning_' . date('Y-m-d') . '.sent';
        if (!file_exists($alert_file)) {
            $subject = "🟡 DOMAIN WARNING: " . count($warning) . " domain(s) expiring within 30 days";
            $body = "<h2 style='color: #f39c12;'>Domain Registration Warning</h2>";
            $body .= "<p>The following domains will expire soon:</p><ul>";
            foreach ($warning as $alert) {
                $body .= "<li><strong>{$alert['domain']}</strong> - {$alert['days']} days left (expires {$alert['expires']})</li>";
            }
            $body .= "</ul><p>Plan to renew these domains soon.</p>";
            send_alert($alert_email, $subject, $body);
            file_put_contents($alert_file, date('Y-m-d H:i:s'));
            echo "→ Domain warning alert sent\n";
        }
    }
}

// Cleanup old logs (keep 7 days)
$week_ago = $now - (7 * 86400);
$conn->query("DELETE FROM app_entity_46 WHERE field_420 < $week_ago");
$deleted = $conn->affected_rows;
if ($deleted > 0) {
    echo "Cleaned up $deleted old log entries\n";
}

$conn->close();
