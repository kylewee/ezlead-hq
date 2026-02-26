<?php
session_start();
$config = require __DIR__ . '/../config.php';

// Simple auth gate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $config['auth']['password']) {
        $_SESSION['dispatch_auth'] = true;
        header('Location: /');
        exit;
    }
    $error = 'Wrong password';
}

if (empty($_SESSION['dispatch_auth'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>EZ Dispatch - Login</title>
        <style>
            body { font-family: system-ui, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #1a1a2e; color: #eee; }
            .login { background: #16213e; padding: 2rem; border-radius: 8px; width: 300px; }
            .login h1 { margin: 0 0 1rem; font-size: 1.25rem; }
            .login input { width: 100%; padding: 0.5rem; box-sizing: border-box; margin-bottom: 0.5rem; border: 1px solid #333; border-radius: 4px; background: #0f3460; color: #eee; }
            .login button { width: 100%; padding: 0.5rem; background: #e94560; color: white; border: none; border-radius: 4px; cursor: pointer; }
            .error { color: #e94560; font-size: 0.875rem; }
        </style>
    </head>
    <body>
        <form class="login" method="POST">
            <h1>EZ Dispatch</h1>
            <?php if (!empty($error)): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
            <input type="password" name="password" placeholder="Password" autofocus>
            <button type="submit">Login</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Pass config to JS (without secrets)
$jsConfig = json_encode([
    'wsUrl' => 'wss://dispatch.ezlead4u.com/ws',
    'callsProxyUrl' => '/api/calls.php',
    'smsApiUrl' => '/api/sms.php',
    'crmApiUrl' => '/api/crm.php',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EZ Dispatch</title>
    <link rel="stylesheet" href="/css/dispatch.css">
</head>
<body>
    <!-- Top Bar -->
    <header id="topbar">
        <div class="topbar-left">
            <h1>EZ DISPATCH</h1>
        </div>
        <div class="topbar-center">
            <button class="filter-btn active" data-filter="all">All</button>
            <button class="filter-btn" data-filter="mechanic">Mechanic</button>
            <button class="filter-btn" data-filter="sod">Sod</button>
        </div>
        <div class="topbar-right">
            <span id="ws-status" class="status-dot offline" title="Disconnected"></span>
            <span id="user-name">Kyle W.</span>
        </div>
    </header>

    <div id="main">
        <!-- Left Sidebar -->
        <aside id="sidebar">
            <div class="sidebar-section">
                <h3>Calls</h3>
                <div id="calls-live" class="convo-list"></div>
                <div id="calls-missed" class="convo-list"></div>
            </div>
            <div class="sidebar-section">
                <h3>SMS <span id="sms-badge" class="badge hidden">0</span></h3>
                <div id="sms-list" class="convo-list"></div>
            </div>
            <div class="sidebar-section">
                <h3>Video</h3>
                <div id="video-list" class="convo-list"></div>
            </div>
            <hr>
            <div class="sidebar-section">
                <h3>Recent</h3>
                <div id="recent-list" class="convo-list"></div>
            </div>
        </aside>

        <!-- Center Content -->
        <main id="content">
            <div id="convo-header" class="hidden">
                <strong id="convo-name"></strong>
                <span id="convo-phone"></span>
                <span id="convo-vehicle"></span>
            </div>

            <!-- Video/Call Area -->
            <div id="media-area" class="hidden">
                <video id="remote-video" autoplay playsinline></video>
                <video id="local-video" autoplay muted playsinline></video>
                <div id="call-controls" class="hidden">
                    <button id="btn-mute" title="Mute">Mute</button>
                    <button id="btn-video-toggle" title="Camera">Camera</button>
                    <button id="btn-screenshot" title="Screenshot">Screenshot</button>
                    <button id="btn-hangup" title="Hang Up">Hang Up</button>
                </div>
            </div>

            <!-- SMS Thread -->
            <div id="sms-thread"></div>

            <!-- Message Input -->
            <div id="message-bar" class="hidden">
                <input type="text" id="msg-input" placeholder="Type message...">
                <button id="btn-send">Send</button>
            </div>

            <!-- Quick Actions -->
            <div id="quick-actions" class="hidden">
                <button id="btn-create-job">Create Job</button>
                <button id="btn-send-estimate">Send Estimate</button>
                <button id="btn-send-video-link">Send Video Link</button>
            </div>

            <!-- Empty State -->
            <div id="empty-state">
                <p>No active conversation. Waiting for calls...</p>
            </div>
        </main>
    </div>

    <!-- Incoming Alert Bar -->
    <div id="incoming-bar" class="hidden">
        <span id="incoming-info"></span>
        <button id="btn-answer" class="btn-green">Answer</button>
        <button id="btn-decline" class="btn-red">Decline</button>
    </div>

    <!-- Audio for ringtone -->
    <audio id="ringtone" loop preload="auto">
        <source src="/audio/ring.mp3" type="audio/mpeg">
    </audio>

    <script>window.DISPATCH_CONFIG = <?= $jsConfig ?>;</script>
    <script src="/js/dispatch.js"></script>
</body>
</html>
