<?php
/**
 * Mission Control - Health Check Module
 * Probes real system status for dynamic workflow pieces.
 * Results cached to mc_health_cache.json (5 min TTL).
 */

function get_health($db): array {
    $cache_file = __DIR__ . '/mc_health_cache.json';
    $ttl = 300;

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $ttl) {
        $cached = json_decode(file_get_contents($cache_file), true);
        if ($cached) return $cached;
    }

    $health = [
        'mitchell1'            => check_mitchell1(),
        'signalwire_sms'       => check_signalwire(),
        'voice_pipeline'       => check_voice_pipeline(),
        'ollama'               => check_ollama(),
        'estimates'            => check_estimates(),
        'crm_api'              => check_crm_api($db),
        'mechanic_automation'  => check_mechanic_automation(),
        'session_tracking'     => check_session_tracking(),
        'uptime_sites'         => check_uptime_sites($db),
        'server'               => check_server(),
    ];

    file_put_contents($cache_file, json_encode($health, JSON_PRETTY_PRINT));
    return $health;
}

// --- Individual probes ---

function check_mitchell1(): array {
    $status_file = '/var/lib/mitchell1/status.json';
    if (!file_exists($status_file)) {
        return ['status' => 'red', 'note' => 'Not working - no status file'];
    }
    $data = json_decode(file_get_contents($status_file), true);
    if (!$data) {
        return ['status' => 'red', 'note' => 'Not working - corrupt status file'];
    }

    $alive = $data['alive'] ?? false;
    $last_check = $data['last_check'] ?? 0;
    $ago = time() - $last_check;
    $ago_str = $ago < 3600 ? round($ago / 60) . 'm ago' : round($ago / 3600) . 'h ago';

    if ($alive) {
        $cookie_age = isset($data['cookie_updated']) ? time() - $data['cookie_updated'] : null;
        $cookie_str = '';
        if ($cookie_age !== null) {
            $cookie_str = $cookie_age < 86400 ? ', cookie fresh' : ', cookie ' . round($cookie_age / 86400) . 'd old';
        }
        return ['status' => 'green', 'note' => "Working - session alive, checked $ago_str$cookie_str"];
    }
    return ['status' => 'red', 'note' => "Not working - session dead, checked $ago_str"];
}

function check_signalwire(): array {
    require_once __DIR__ . '/config.php';
    $project_id = SIGNALWIRE_PROJECT_ID;
    $api_token  = SIGNALWIRE_API_TOKEN;
    $space      = SIGNALWIRE_SPACE_URL;

    $ch = curl_init("https://{$space}/api/laml/2010-04-01/Accounts/{$project_id}.json");
    curl_setopt_array($ch, [
        CURLOPT_USERPWD        => "{$project_id}:{$api_token}",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200) {
        return ['status' => 'green', 'note' => 'Working - SignalWire API responding'];
    }
    if ($http === 401) {
        return ['status' => 'red', 'note' => 'Not working - SignalWire auth failed'];
    }
    return ['status' => 'yellow', 'note' => "Built, SignalWire API returned HTTP $http"];
}

function check_voice_pipeline(): array {
    $ch = curl_init('http://127.0.0.1:8378/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $whisper_ok = ($http === 200);
    $rp_exists = file_exists('/var/www/ezlead-platform/voice/recording_processor.php');
    $inc_exists = file_exists('/var/www/ezlead-platform/voice/incoming.php');

    if ($whisper_ok && $rp_exists && $inc_exists) {
        return ['status' => 'green', 'note' => 'Working - whisper server + pipeline ready'];
    }
    if ($rp_exists && $inc_exists) {
        return ['status' => 'yellow', 'note' => 'Built, whisper server not running'];
    }
    return ['status' => 'red', 'note' => 'Not working - pipeline files missing'];
}

function check_ollama(): array {
    $ch = curl_init('http://127.0.0.1:11434/api/tags');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 2]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200) {
        $data = json_decode($resp, true);
        $models = array_column($data['models'] ?? [], 'name');
        $model_list = implode(', ', array_slice($models, 0, 3));
        return ['status' => 'green', 'note' => "Working - $model_list"];
    }
    return ['status' => 'red', 'note' => 'Not working - Ollama not responding'];
}

function check_estimates(): array {
    $ee = file_exists('/var/www/ezlead-platform/core/lib/EstimateEngine.php');
    $m1 = check_mitchell1();

    if ($ee && $m1['status'] === 'green') {
        return ['status' => 'green', 'note' => 'Working - EstimateEngine + Mitchell1'];
    }
    if ($ee) {
        return ['status' => 'yellow', 'note' => 'Built, GPT fallback only (M1 down)'];
    }
    return ['status' => 'red', 'note' => 'Not working - EstimateEngine missing'];
}

function check_crm_api($db): array {
    if (!$db || $db->connect_error) {
        return ['status' => 'red', 'note' => 'Not working - database connection failed'];
    }
    $result = $db->query("SELECT COUNT(*) as cnt FROM app_entity_50");
    if ($result && $row = $result->fetch_assoc()) {
        return ['status' => 'green', 'note' => 'Working'];
    }
    return ['status' => 'yellow', 'note' => 'Built, query failed'];
}

function check_mechanic_automation(): array {
    $log = '/home/kylewee/logs/mechanic.log';
    if (!file_exists($log)) {
        return ['status' => 'red', 'note' => 'Not working - no log file'];
    }
    $age = time() - filemtime($log);
    if ($age < 600) {
        return ['status' => 'green', 'note' => 'Working - last run ' . round($age / 60) . 'm ago'];
    }
    return ['status' => 'yellow', 'note' => 'Built, not running - last activity ' . round($age / 3600) . 'h ago'];
}

function check_session_tracking(): array {
    $hooks_ok = file_exists('/home/kylewee/.claude/hooks/session_checkin.sh')
             && file_exists('/home/kylewee/.claude/hooks/session_checkout_reminder.sh');
    $scripts_ok = file_exists('/home/kylewee/scripts/crm_checkin.php')
               && file_exists('/home/kylewee/scripts/crm_checkout.php');

    if ($hooks_ok && $scripts_ok) {
        return ['status' => 'green', 'note' => 'Working'];
    }
    if ($scripts_ok) {
        return ['status' => 'yellow', 'note' => 'Built, hooks missing'];
    }
    return ['status' => 'red', 'note' => 'Not working - scripts missing'];
}

function check_uptime_sites($db): array {
    if (!$db || $db->connect_error) {
        return ['status' => 'yellow', 'note' => 'Built, cannot check - no DB'];
    }
    $result = $db->query("SELECT field_333 as domain, field_410 as health FROM app_entity_37");
    if (!$result) {
        return ['status' => 'yellow', 'note' => 'Built, query failed'];
    }

    $total = 0;
    $down = [];
    while ($row = $result->fetch_assoc()) {
        $total++;
        if ($row['health'] != '116') { // 116=Healthy
            $down[] = $row['domain'];
        }
    }

    if (count($down) === 0) {
        return ['status' => 'green', 'note' => "Working - all $total sites up"];
    }
    $down_str = implode(', ', $down);
    return ['status' => 'red', 'note' => count($down) . " sites down: $down_str"];
}

function check_server(): array {
    $services = ['caddy', 'php8.3-fpm', 'mariadb'];
    $down = [];
    foreach ($services as $svc) {
        $status = trim(shell_exec("systemctl is-active $svc 2>/dev/null") ?? '');
        if ($status !== 'active') {
            $down[] = $svc;
        }
    }

    if (empty($down)) {
        return ['status' => 'green', 'note' => 'Done'];
    }
    return ['status' => 'red', 'note' => 'Not working - down: ' . implode(', ', $down)];
}
