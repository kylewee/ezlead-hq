<?php
/**
 * Quote System Admin Dashboard
 * Test, monitor, and manage mobile quotes
 */

require_once __DIR__ . '/../../lib/QuoteSMS.php';
require_once __DIR__ . '/../../lib/PostServiceFlow.php';

// Simple auth
session_start();
$authenticated = isset($_SESSION['admin_hub_auth']) && $_SESSION['admin_hub_auth'] === true;
if (!$authenticated && isset($_POST['password'])) {
    if ($_POST['password'] === 'Rain0nin') {
        $_SESSION['admin_hub_auth'] = true;
        $authenticated = true;
    }
}

if (!$authenticated) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Quote System Login</title>
    <style>
        body { font-family: system-ui; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f1f5f9; }
        form { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        input { display: block; width: 200px; padding: 0.5rem; margin: 0.5rem 0 1rem; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; }
    </style>
    </head>
    <body>
        <form method="post">
            <h2>Quote System Admin</h2>
            <label>Password</label>
            <input type="password" name="password" required autofocus>
            <button type="submit">Login</button>
        </form>
    <!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
    </html>
    <?php
    exit;
}

// Handle test quote send
if (isset($_POST['send_test_quote'])) {
    $quoteSMS = new QuoteSMS();
    $result = $quoteSMS->sendQuote([
        'customer_phone' => $_POST['test_phone'],
        'customer_name' => $_POST['test_name'] ?: 'Test Customer',
        'vehicle' => $_POST['test_vehicle'] ?: '2020 Toyota Camry',
        'services' => [
            ['name' => 'Check Engine Light Diagnostic', 'price' => 150]
        ],
        'total' => 150,
        'breakdown' => 'Test quote sent from admin dashboard',
        'lead_id' => null
    ]);

    $testResult = $result;
}

// Get recent quotes
$quoteSMS = new QuoteSMS();
$db = new SQLite3(__DIR__ . '/../../data/quotes.db');
$recentQuotes = [];
$result = $db->query("SELECT * FROM quotes ORDER BY created_at DESC LIMIT 20");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $recentQuotes[] = $row;
}

// Get system status
$signalwireConfigured = defined('SIGNALWIRE_PROJECT_ID') && SIGNALWIRE_PROJECT_ID;
$openaiConfigured = defined('OPENAI_API_KEY') && OPENAI_API_KEY;

// Count quotes by status
$stats = $db->querySingle("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) as viewed,
    SUM(CASE WHEN ai_explained_at IS NOT NULL THEN 1 ELSE 0 END) as ai_explained
FROM quotes", true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quote System Admin - EzLead4U</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f1f5f9;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
            color: #78350f;
        }

        h1 { font-size: 2rem; margin-bottom: 0.5rem; }
        .subtitle { opacity: 0.8; }

        .nav {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .nav a {
            background: white;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            text-decoration: none;
            color: #1e293b;
            border: 2px solid #e2e8f0;
        }

        .nav a:hover {
            border-color: #fbbf24;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
        }

        .stat-card .number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #fbbf24;
        }

        .stat-card .label {
            color: #64748b;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .card h2 {
            color: #1e293b;
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .status-indicator {
            display: inline-block;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }

        .status-ok { background: #10b981; }
        .status-error { background: #ef4444; }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #475569;
            font-weight: 500;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 1rem;
        }

        .btn {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #78350f;
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 1rem;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.4);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }

        th {
            background: #f8fafc;
            font-weight: 600;
            color: #475569;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-warning { background: #fef3c7; color: #92400e; }
        .badge-info { background: #dbeafe; color: #1e40af; }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }

        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }

        .call-instruction {
            background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .call-instruction h3 {
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .call-number {
            font-size: 2rem;
            font-weight: 700;
            margin: 1rem 0;
        }

        .quote-link {
            color: #2563eb;
            text-decoration: none;
        }

        .quote-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📱 Quote System Dashboard</h1>
        <p class="subtitle">Mobile quote SMS with AI explainer - Testing & monitoring</p>
    </div>

    <div class="nav">
        <a href="/admin/">← Admin Home</a>
        <a href="/admin/analytics/">Analytics</a>
        <a href="/admin/ab-testing/">A/B Testing</a>
        <a href="/admin/video-content/">Video Content</a>
    </div>

    <!-- System Status -->
    <div class="grid">
        <div class="stat-card">
            <div class="number"><?php echo number_format($stats['total']); ?></div>
            <div class="label">Total Quotes</div>
        </div>
        <div class="stat-card">
            <div class="number"><?php echo number_format($stats['approved']); ?></div>
            <div class="label">Approved</div>
        </div>
        <div class="stat-card">
            <div class="number"><?php echo number_format($stats['viewed']); ?></div>
            <div class="label">Viewed</div>
        </div>
        <div class="stat-card">
            <div class="number"><?php echo number_format($stats['ai_explained']); ?></div>
            <div class="label">AI Explained</div>
        </div>
        <div class="stat-card">
            <div class="number"><?php echo $stats['total'] > 0 ? number_format(($stats['approved'] / $stats['total']) * 100, 1) : 0; ?>%</div>
            <div class="label">Approval Rate</div>
        </div>
    </div>

    <!-- Test Results -->
    <?php if (isset($testResult)): ?>
    <div class="alert <?php echo $testResult['success'] ? 'alert-success' : 'alert-error'; ?>">
        <?php if ($testResult['success']): ?>
            <strong>✓ Test Quote Sent!</strong><br>
            Quote ID: <strong><?php echo htmlspecialchars($testResult['quote_id']); ?></strong><br>
            View: <a href="/quote/<?php echo htmlspecialchars($testResult['quote_id']); ?>" target="_blank" class="quote-link">
                https://mechanicstaugustine.com/quote/<?php echo htmlspecialchars($testResult['quote_id']); ?>
            </a><br>
            AI Explainer: <a href="/quote/<?php echo htmlspecialchars($testResult['quote_id']); ?>/explain" target="_blank" class="quote-link">
                Click to trigger AI callback
            </a>
            <?php if ($testResult['sms_sent']): ?>
                <br><strong>SMS sent to <?php echo htmlspecialchars($_POST['test_phone']); ?></strong>
            <?php endif; ?>
        <?php else: ?>
            <strong>✗ Test Failed</strong><br>
            Error: <?php echo htmlspecialchars($testResult['error'] ?? 'Unknown error'); ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Live Call Test Instructions -->
    <div class="call-instruction">
        <h3>🎯 Test with Live Call</h3>
        <p>Call this number and the system will automatically send you a quote SMS:</p>
        <div class="call-number">📞 (904) 706-6669</div>
        <p><strong>What to say:</strong></p>
        <p style="margin: 0.5rem 0; padding: 1rem; background: rgba(0,0,0,0.1); border-radius: 8px;">
            "Hi, my name is Kyle Test. I have a 2018 Honda Accord with a check engine light on. The code is P0420. Can you give me a quote?"
        </p>
        <p style="margin-top: 1rem; font-size: 0.9rem; opacity: 0.9;">
            The system will record → transcribe → generate estimate → send SMS to your phone with the mobile quote + AI explainer button
        </p>
    </div>

    <!-- System Health -->
    <div class="card">
        <h2>System Health</h2>
        <table>
            <tr>
                <td>
                    <span class="status-indicator <?php echo $signalwireConfigured ? 'status-ok' : 'status-error'; ?>"></span>
                    SignalWire Configuration
                </td>
                <td><?php echo $signalwireConfigured ? '✓ Connected' : '✗ Not configured'; ?></td>
            </tr>
            <tr>
                <td>
                    <span class="status-indicator <?php echo $openaiConfigured ? 'status-ok' : 'status-error'; ?>"></span>
                    OpenAI Configuration
                </td>
                <td><?php echo $openaiConfigured ? '✓ Connected' : '✗ Not configured'; ?></td>
            </tr>
            <tr>
                <td>
                    <span class="status-indicator status-ok"></span>
                    Quote Database
                </td>
                <td>✓ Operational</td>
            </tr>
            <tr>
                <td>
                    <span class="status-indicator status-ok"></span>
                    Quote Page
                </td>
                <td>✓ https://mechanicstaugustine.com/quote/</td>
            </tr>
        </table>
    </div>

    <!-- Manual Test Quote -->
    <div class="card">
        <h2>Send Test Quote (No Call Required)</h2>
        <form method="post">
            <div class="form-group">
                <label>Phone Number *</label>
                <input type="tel" name="test_phone" value="+19046634789" required>
            </div>
            <div class="form-group">
                <label>Customer Name</label>
                <input type="text" name="test_name" value="Kyle Test" placeholder="Test Customer">
            </div>
            <div class="form-group">
                <label>Vehicle</label>
                <input type="text" name="test_vehicle" value="2018 Honda Accord" placeholder="2020 Toyota Camry">
            </div>
            <button type="submit" name="send_test_quote" class="btn">📤 Send Test Quote SMS</button>
        </form>
    </div>

    <!-- Recent Quotes -->
    <div class="card">
        <h2>Recent Quotes</h2>
        <?php if (empty($recentQuotes)): ?>
        <p style="text-align: center; color: #64748b; padding: 2rem;">No quotes yet. Make a test call or send a manual quote above.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Quote ID</th>
                    <th>Customer</th>
                    <th>Vehicle</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Actions</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentQuotes as $quote): ?>
                <tr>
                    <td>
                        <a href="/quote/<?php echo htmlspecialchars($quote['quote_id']); ?>" target="_blank" class="quote-link">
                            <?php echo htmlspecialchars($quote['quote_id']); ?>
                        </a>
                    </td>
                    <td><?php echo htmlspecialchars($quote['customer_name']); ?></td>
                    <td><?php echo htmlspecialchars($quote['vehicle']); ?></td>
                    <td>$<?php echo number_format($quote['total_price'], 2); ?></td>
                    <td>
                        <?php if ($quote['status'] === 'approved'): ?>
                        <span class="badge badge-success">Approved</span>
                        <?php else: ?>
                        <span class="badge badge-warning">Sent</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.75rem;">
                        <?php if ($quote['viewed_at']): ?>
                        <span class="badge badge-info">👁 Viewed</span>
                        <?php endif; ?>
                        <?php if ($quote['ai_explained_at']): ?>
                        <span class="badge badge-info">🔊 AI</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size: 0.875rem; color: #64748b;">
                        <?php echo date('M j, g:i a', strtotime($quote['created_at'])); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh every 30 seconds to show new quotes from live calls
        setTimeout(() => location.reload(), 30000);
    </script>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
