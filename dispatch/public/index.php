<?php
$config = require __DIR__ . '/../config.php';
$authToken = hash('sha256', $config['auth']['password'] . ':dispatch-auth');

// Simple auth gate
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $config['auth']['password']) {
        setcookie('dispatch_auth', $authToken, [
            'expires' => time() + 86400 * 30,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        header('Location: /');
        exit;
    }
    $error = 'Wrong password';
}

// Also allow ?token= for quick access through Cloudflare
if (!empty($_GET['token']) && $_GET['token'] === $config['auth']['password']) {
    setcookie('dispatch_auth', $authToken, [
        'expires' => time() + 86400 * 30,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('Location: /');
    exit;
}

if (($_COOKIE['dispatch_auth'] ?? '') !== $authToken) {
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
    'queueApiUrl' => '/api/queue.php',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EZ Dispatch</title>
    <link rel="stylesheet" href="/css/dispatch.css?v=<?= filemtime(__DIR__ . '/css/dispatch.css') ?>">
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
            <div class="sidebar-actions">
                <button id="btn-new-sms" class="btn-action">New SMS</button>
                <button id="btn-new-call" class="btn-action">New Call</button>
            </div>
            <div class="sidebar-section">
                <h3 id="queue-nav" class="nav-link active">Queue <span id="queue-badge" class="badge hidden">0</span></h3>
            </div>
            <hr>
            <div class="sidebar-section">
                <h3 id="calls-nav" class="nav-link">Calls</h3>
                <div id="calls-live" class="convo-list"></div>
                <div id="calls-missed" class="convo-list"></div>
            </div>
            <div class="sidebar-section">
                <h3 id="sms-nav" class="nav-link">SMS <span id="sms-badge" class="badge hidden">0</span></h3>
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
            <!-- Queue Panel -->
            <div id="queue-panel">
                <div class="queue-header">
                    <h2>Approval Queue</h2>
                    <div class="queue-tabs">
                        <button class="queue-tab active" data-status="pending">Pending</button>
                        <button class="queue-tab" data-status="held">Held</button>
                        <button class="queue-tab" data-status="approved">Done</button>
                    </div>
                </div>
                <div id="queue-list"></div>
                <div id="queue-empty">
                    <p>No pending items</p>
                    <p style="opacity:0.5; font-size:0.8rem;">New leads, estimates, and calls will appear here for approval.</p>
                </div>
            </div>

            <!-- Compose SMS Panel -->
            <div id="compose-panel" class="hidden">
                <h2>Send SMS</h2>
                <div class="compose-form">
                    <div class="compose-row">
                        <label>To:</label>
                        <input type="tel" id="compose-to" placeholder="Phone number">
                    </div>
                    <div class="compose-row">
                        <label>From:</label>
                        <select id="compose-from">
                            <option value="+19042175152">Mechanic (+1 904-217-5152)</option>
                            <option value="+19047066669">Mechanic 2 (+1 904-706-6669)</option>
                            <option value="+19049258873">Sod (+1 904-925-8873)</option>
                        </select>
                    </div>
                    <div class="compose-row">
                        <textarea id="compose-msg" rows="4" placeholder="Type your message..."></textarea>
                    </div>
                    <div class="compose-actions">
                        <button id="btn-compose-send" class="btn-green">Send SMS</button>
                        <button id="btn-compose-cancel">Cancel</button>
                    </div>
                </div>
            </div>

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
            <div id="sms-thread" class="hidden"></div>

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
            <div id="empty-state" class="hidden">
                <p>No active conversation.</p>
                <p style="opacity:0.5; font-size:0.875rem;">Click "New SMS" or "New Call" to get started, or wait for incoming.</p>
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
    <script src="/js/dispatch.js?v=<?= filemtime(__DIR__ . '/js/dispatch.js') ?>"></script>
</body>
</html>
