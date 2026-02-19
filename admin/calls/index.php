<?php
/**
 * Outgoing Calls Admin
 * Make recorded calls and view call history
 */

session_start();

// Auth
if (empty($_SESSION['admin_hub_auth']) && ($_POST['password'] ?? '') !== 'Rain0nin') {
    if (isset($_POST['password'])) $error = 'Invalid password';
    ?>
    <!DOCTYPE html>
    <html><head><title>Calls Admin - Login</title>
    <style>
        body { font-family: system-ui; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #0f172a; }
        form { background: #1e293b; padding: 2rem; border-radius: 8px; color: #fff; text-align: center; }
        h2 { margin-bottom: 1rem; }
        input { display: block; width: 200px; padding: .75rem; margin: .5rem auto 1rem; border: 1px solid #334155; border-radius: 4px; background: #0f172a; color: #fff; }
        button { background: #3b82f6; color: #fff; border: none; padding: .75rem 2rem; border-radius: 4px; cursor: pointer; font-weight: 600; }
        .error { color: #ef4444; margin-bottom: 1rem; }
    </style>
    </head><body><form method="post">
    <h2>📞 Calls Admin</h2>
    <?php if (isset($error)): ?><p class="error"><?= $error ?></p><?php endif; ?>
    <input type="password" name="password" placeholder="Password" autofocus>
    <button type="submit">Login</button>
    </form><!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body></html>
    <?php
    exit;
}
$_SESSION['admin_hub_auth'] = true;

// Load SignalWire config
require_once __DIR__ . '/../../config/bootstrap.php';
$defaultFrom = '+19047066669'; // SignalWire number

// Handle make call with PRG pattern to prevent refresh re-submit
$callResult = null;
if (isset($_POST['make_call'])) {
    $to = $_POST['to'] ?? '';
    $agent = $_POST['agent'] ?? '+19042175152';
    $notes = $_POST['notes'] ?? '';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://' . $_SERVER['HTTP_HOST'] . '/api/make-call.php',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'to' => $to,
            'agent' => $agent,
            'from' => $defaultFrom,
            'notes' => $notes,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $_SESSION['call_result'] = json_decode($response, true);

    // PRG: Redirect to prevent refresh re-submit
    header('Location: /admin/calls/');
    exit;
}

// Get result from session if available
if (isset($_SESSION['call_result'])) {
    $callResult = $_SESSION['call_result'];
    unset($_SESSION['call_result']);
}

// Load calls from database
$calls = [];
$callsDb = __DIR__ . '/../../data/calls.db';
if (file_exists($callsDb)) {
    try {
        $db = new SQLite3($callsDb);
        $result = $db->query("SELECT * FROM outgoing_calls ORDER BY created_at DESC LIMIT 50");
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $calls[] = $row;
        }
    } catch (Exception $e) {}
}

// Load recordings from voice folder
$recordings = [];
$recordingsDir = __DIR__ . '/../../voice/recordings/';
if (is_dir($recordingsDir)) {
    $files = glob($recordingsDir . '*.mp3');
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    foreach (array_slice($files, 0, 20) as $file) {
        $recordings[] = [
            'filename' => basename($file),
            'path' => '/voice/recordings/' . basename($file),
            'size' => filesize($file),
            'date' => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calls Admin - EzLead4U</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%); padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 22px; }
        .header a { color: rgba(255,255,255,0.8); text-decoration: none; font-size: 14px; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }
        .grid { display: grid; grid-template-columns: 1fr 2fr; gap: 30px; }
        @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } }
        .card { background: #1e293b; border-radius: 12px; padding: 24px; border: 1px solid rgba(148, 163, 184, 0.1); margin-bottom: 20px; }
        .card h2 { font-size: 16px; color: #94a3b8; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; color: #94a3b8; margin-bottom: 6px; }
        input, textarea, select { width: 100%; padding: 12px; border: 1px solid #334155; border-radius: 8px; background: #0f172a; color: #f1f5f9; font-size: 14px; }
        input:focus, textarea:focus { outline: none; border-color: #3b82f6; }
        button { padding: 12px 24px; background: #22c55e; color: white; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; width: 100%; }
        button:hover { background: #16a34a; }
        .result { padding: 16px; border-radius: 8px; margin-top: 16px; font-size: 14px; }
        .result.success { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .result.error { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #334155; }
        th { color: #94a3b8; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: rgba(59, 130, 246, 0.05); }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; }
        .badge.completed { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .badge.initiated { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .badge.failed { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        audio { width: 100%; height: 40px; margin-top: 8px; }
        .recording-item { padding: 12px; background: #0f172a; border-radius: 8px; margin-bottom: 10px; }
        .recording-meta { font-size: 12px; color: #64748b; margin-bottom: 8px; }
        .empty { text-align: center; color: #64748b; padding: 40px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📞 Outgoing Calls</h1>
        <a href="/admin/">← Back to Admin Hub</a>
    </div>

    <div class="container">
        <div class="grid">
            <div>
                <!-- Make Call Form -->
                <div class="card">
                    <h2>Make a Call</h2>
                    <form method="POST">
                        <div class="form-group">
                            <label>Call This Number *</label>
                            <input type="tel" name="to" placeholder="+1 904 555 1234" required>
                        </div>
                        <div class="form-group">
                            <label>Ring Your Phone First</label>
                            <input type="tel" name="agent" value="+19042175152" placeholder="Your cell number">
                            <small style="color:#64748b;font-size:12px;">System calls you, then connects to target</small>
                        </div>
                        <div class="form-group">
                            <label>Caller ID (SignalWire)</label>
                            <input type="tel" name="from" value="<?= htmlspecialchars($defaultFrom) ?>" readonly>
                        </div>
                        <div class="form-group">
                            <label>Notes (optional)</label>
                            <textarea name="notes" rows="2" placeholder="Lead follow-up, etc."></textarea>
                        </div>
                        <button type="submit" name="make_call" id="callBtn" onclick="this.disabled=true; this.innerText='Calling...'; this.form.submit();">📞 Call Now (Recorded)</button>
                    </form>

                    <?php if ($callResult): ?>
                        <div class="result <?= isset($callResult['success']) ? 'success' : 'error' ?>">
                            <?php if (isset($callResult['success'])): ?>
                                ✓ Call initiated! SID: <?= htmlspecialchars($callResult['call_sid'] ?? '') ?>
                            <?php else: ?>
                                ✗ Error: <?= htmlspecialchars($callResult['error'] ?? 'Unknown error') ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Recordings -->
                <div class="card">
                    <h2>Recent Recordings</h2>
                    <?php if (empty($recordings)): ?>
                        <div class="empty">No recordings yet</div>
                    <?php else: ?>
                        <?php foreach ($recordings as $rec): ?>
                            <div class="recording-item">
                                <div class="recording-meta">
                                    <?= htmlspecialchars($rec['date']) ?> • <?= round($rec['size'] / 1024) ?>KB
                                </div>
                                <audio controls preload="none">
                                    <source src="<?= htmlspecialchars($rec['path']) ?>" type="audio/mpeg">
                                </audio>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div>
                <!-- Call History -->
                <div class="card">
                    <h2>Call History</h2>
                    <?php if (empty($calls)): ?>
                        <div class="empty">No outgoing calls yet. Make your first call!</div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>To</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Notes</th>
                                    <th>Date</th>
                                    <th>Recording</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($calls as $call): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($call['to_number']) ?></td>
                                        <td><span class="badge <?= $call['status'] ?>"><?= ucfirst($call['status']) ?></span></td>
                                        <td><?= $call['duration'] ? gmdate('i:s', $call['duration']) : '-' ?></td>
                                        <td><?= htmlspecialchars($call['notes'] ?: '-') ?></td>
                                        <td><?= date('M j, g:i a', strtotime($call['created_at'])) ?></td>
                                        <td>
                                            <?php if ($call['recording_url']): ?>
                                                <audio controls preload="none" style="width:150px;height:30px;">
                                                    <source src="<?= htmlspecialchars($call['recording_url']) ?>" type="audio/mpeg">
                                                </audio>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
