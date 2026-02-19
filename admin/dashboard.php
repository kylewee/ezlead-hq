<?php
session_start();

// Simple password protection
$password = 'ezlead2024'; // Change this to your preferred password

if (isset($_POST['logout'])) {
    unset($_SESSION['admin_logged_in']);
}

if (isset($_POST['password'])) {
    if ($_POST['password'] === $password) {
        $_SESSION['admin_logged_in'] = true;
    } else {
        $error = 'Incorrect password';
    }
}

// Handle notes saving
$notes_file = __DIR__ . '/customer_experience_notes.json';
if (isset($_POST['save_notes']) && isset($_SESSION['admin_logged_in'])) {
    $notes = $_POST['notes'] ?? '';
    file_put_contents($notes_file, json_encode(['notes' => $notes, 'updated' => date('Y-m-d H:i:s')]));
    $saved = true;
}

// Load existing notes
$notes_data = file_exists($notes_file) ? json_decode(file_get_contents($notes_file), true) : ['notes' => '', 'updated' => ''];

if (!isset($_SESSION['admin_logged_in'])) {
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Login - EzLead4U</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .login-box { background: #16213e; padding: 40px; border-radius: 10px; text-align: center; }
        input[type="password"] { padding: 12px; font-size: 16px; border: none; border-radius: 5px; margin: 10px 0; width: 200px; }
        button { padding: 12px 30px; font-size: 16px; background: #e94560; color: white; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #ff6b6b; }
        .error { color: #ff6b6b; margin-top: 10px; }
        h1 { margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>🔧 Admin Dashboard</h1>
        <form method="POST">
            <input type="password" name="password" placeholder="Enter password" required><br>
            <button type="submit">Login</button>
        </form>
        <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard - EzLead4U</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a2e; color: #eee; margin: 0; padding: 20px; }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; }
        h1 { margin: 0; }
        .logout-btn { padding: 10px 20px; background: #e94560; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .logout-btn:hover { background: #ff6b6b; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: #16213e; padding: 20px; border-radius: 10px; }
        .card h2 { margin-top: 0; color: #e94560; }
        .card a { display: block; color: #4fc3f7; text-decoration: none; padding: 8px 0; border-bottom: 1px solid #0f3460; }
        .card a:hover { color: #81d4fa; }
        .card a:last-child { border-bottom: none; }
        .notes-section { background: #16213e; padding: 20px; border-radius: 10px; }
        .notes-section h2 { margin-top: 0; color: #e94560; }
        textarea { width: 100%; height: 300px; padding: 15px; font-size: 14px; border: none; border-radius: 5px; background: #0f3460; color: #eee; resize: vertical; }
        .save-btn { margin-top: 10px; padding: 12px 30px; font-size: 16px; background: #4caf50; color: white; border: none; border-radius: 5px; cursor: pointer; }
        .save-btn:hover { background: #66bb6a; }
        .saved-msg { color: #4caf50; margin-left: 15px; }
        .updated { color: #888; font-size: 12px; margin-top: 10px; }
        .status { padding: 5px 10px; border-radius: 3px; font-size: 12px; }
        .status.online { background: #4caf50; }
        .status.offline { background: #f44336; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔧 EzLead4U - Admin Dashboard</h1>
        <form method="POST" style="display:inline;">
            <button type="submit" name="logout" value="1" class="logout-btn">Logout</button>
        </form>
    </div>

    <div class="grid">
        <div class="card">
            <h2>📞 Phone System</h2>
            <a href="/voice/incoming.php" target="_blank">Incoming Call Handler</a>
            <a href="/voice/ivr_intake.php" target="_blank">IVR Intake</a>
            <a href="/voice/recording_callback.php" target="_blank">Recording Callback</a>
            <a href="/voice/dial_result.php" target="_blank">Dial Result Handler</a>
            <a href="/voice/call_status.php" target="_blank">Call Status</a>
        </div>

        <div class="card">
            <h2>📋 CRM & Leads</h2>
            <a href="/crm/" target="_blank">CRM Dashboard</a>
            <a href="/admin/leads_approval.php" target="_blank">Leads Approval</a>
            <a href="/admin/parts_orders.php" target="_blank">Parts Orders</a>
        </div>

        <div class="card">
            <h2>💬 Quote & Intake</h2>
            <a href="/quote/" target="_blank">Quote Form (Customer View)</a>
            <a href="/api/quote_intake.php" target="_blank">Quote Intake API</a>
            <a href="/api/sms/incoming.php" target="_blank">SMS Incoming Handler</a>
        </div>

        <div class="card">
            <h2>🌐 Website</h2>
            <a href="/" target="_blank">Homepage</a>
            <a href="/Mobile-mechanic/" target="_blank">EzLead4U Portal</a>
            <a href="/health.php" target="_blank">Health Check</a>
        </div>

        <div class="card">
            <h2>📁 Logs & Debug</h2>
            <a href="#" onclick="alert('Check terminal: tail -f /home/kylewee/code/inbred/voice/voice.log')">Voice Log (terminal)</a>
            <a href="/voice/test_webhook.php" target="_blank">Test Webhook</a>
            <a href="/test.php" target="_blank">Test Page</a>
        </div>

        <div class="card">
            <h2>📚 Documentation</h2>
            <a href="/docs/api_outline.md" target="_blank">API Outline</a>
            <a href="/docs/requirements.md" target="_blank">Requirements</a>
            <a href="/docs/runbook.md" target="_blank">Runbook</a>
            <a href="/DEPLOYMENT.md" target="_blank">Deployment Guide</a>
        </div>
    </div>

    <div class="notes-section">
        <h2>📝 Customer Experience Notes</h2>
        <p>Use this area to capture observations, pain points, and improvement ideas during role-play exercises.</p>
        <form method="POST">
            <textarea name="notes" placeholder="Add your notes here..."><?php echo htmlspecialchars($notes_data['notes']); ?></textarea>
            <button type="submit" name="save_notes" value="1" class="save-btn">Save Notes</button>
            <?php if (isset($saved)) echo "<span class='saved-msg'>✓ Saved!</span>"; ?>
        </form>
        <?php if ($notes_data['updated']) echo "<p class='updated'>Last updated: " . $notes_data['updated'] . "</p>"; ?>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
