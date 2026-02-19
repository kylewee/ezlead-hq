<?php
/**
 * EzLead4U Admin Hub
 * Central dashboard linking all admin tools
 */

session_start();

// Simple auth
$validPassword = 'Rain0nin';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}

if (isset($_POST['password'])) {
    if ($_POST['password'] === $validPassword) {
        $_SESSION['admin_hub_auth'] = true;
    } else {
        $error = 'Invalid password';
    }
}

if (empty($_SESSION['admin_hub_auth'])) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Hub - EzLead4U</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-box {
            background: rgba(30, 41, 59, 0.95);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 16px;
            padding: 48px;
            max-width: 400px;
            width: 90%;
            text-align: center;
        }
        .logo { font-size: 48px; margin-bottom: 16px; }
        h1 { color: #f1f5f9; font-size: 24px; margin-bottom: 8px; }
        p { color: #94a3b8; margin-bottom: 32px; }
        input {
            width: 100%;
            padding: 14px 16px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.6);
            color: #f1f5f9;
            font-size: 16px;
            margin-bottom: 16px;
        }
        input:focus { outline: none; border-color: #3b82f6; }
        button {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { opacity: 0.9; }
        .error { color: #ef4444; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="logo">🚀</div>
        <h1>EzLead4U Admin</h1>
        <p>Lead Distribution Command Center</p>
        <?php if (isset($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <input type="password" name="password" placeholder="Enter password" required autofocus>
            <button type="submit">Sign In</button>
        </form>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
<?php
    exit;
}

// Get some quick stats
$statsDb = __DIR__ . '/../data/buyers.db';
$stats = ['buyers' => 0, 'leads' => 0, 'revenue' => 0];
if (file_exists($statsDb)) {
    try {
        $db = new SQLite3($statsDb);
        $stats['buyers'] = $db->querySingle("SELECT COUNT(*) FROM buyers WHERE status = 'active'") ?? 0;
        $stats['leads'] = $db->querySingle("SELECT COUNT(*) FROM buyer_leads") ?? 0;
        $stats['revenue'] = ($db->querySingle("SELECT SUM(amount) FROM buyer_transactions WHERE type = 'deposit'") ?? 0) / 100;
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Hub - EzLead4U</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            padding: 24px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 24px; display: flex; align-items: center; gap: 12px; }
        .header-right { display: flex; align-items: center; gap: 20px; }
        .header a { color: rgba(255,255,255,0.8); text-decoration: none; font-size: 14px; }
        .header a:hover { color: white; }

        .container { max-width: 1400px; margin: 0 auto; padding: 32px; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .stat-card {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            padding: 24px;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.1);
        }
        .stat-card h3 { font-size: 12px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
        .stat-card .value { font-size: 36px; font-weight: 700; color: #3b82f6; }
        .stat-card.green .value { color: #22c55e; }

        .section-title {
            font-size: 14px;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .tools-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }
        .tool-card {
            background: #1e293b;
            border-radius: 12px;
            padding: 24px;
            border: 1px solid rgba(148, 163, 184, 0.1);
            text-decoration: none;
            color: inherit;
            transition: all 0.2s;
            display: block;
        }
        .tool-card:hover {
            border-color: #3b82f6;
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.15);
        }
        .tool-card .icon {
            font-size: 32px;
            margin-bottom: 16px;
        }
        .tool-card h3 {
            font-size: 18px;
            color: #f1f5f9;
            margin-bottom: 8px;
        }
        .tool-card p {
            font-size: 14px;
            color: #94a3b8;
            line-height: 1.5;
        }
        .tool-card .badge {
            display: inline-block;
            padding: 4px 8px;
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 12px;
        }
        .tool-card .badge.green { background: rgba(34, 197, 94, 0.2); color: #4ade80; }
        .tool-card .badge.yellow { background: rgba(234, 179, 8, 0.2); color: #facc15; }
        .tool-card .badge.purple { background: rgba(168, 85, 247, 0.2); color: #c084fc; }

        .quick-links {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 20px;
        }
        .quick-link {
            padding: 10px 16px;
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.3);
            border-radius: 8px;
            color: #60a5fa;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        .quick-link:hover {
            background: rgba(59, 130, 246, 0.2);
            border-color: #3b82f6;
        }

        .footer {
            text-align: center;
            padding: 20px;
            color: #64748b;
            font-size: 13px;
        }
        .footer a { color: #3b82f6; text-decoration: none; }
    </style>
</head>
<body>
    <div class="header">
        <h1>🚀 EzLead4U Admin Hub</h1>
        <div class="header-right">
            <a href="/">← Back to Dashboard</a>
            <a href="/admin/?logout=1">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Stats Row -->
        <div class="stats-row">
            <div class="stat-card">
                <h3>Active Buyers</h3>
                <div class="value"><?= $stats['buyers'] ?></div>
            </div>
            <div class="stat-card">
                <h3>Total Leads</h3>
                <div class="value"><?= $stats['leads'] ?></div>
            </div>
            <div class="stat-card green">
                <h3>Revenue</h3>
                <div class="value">$<?= number_format($stats['revenue'], 0) ?></div>
            </div>
            <div class="stat-card">
                <h3>Status</h3>
                <div class="value" style="color: #22c55e; font-size: 24px;">● Online</div>
            </div>
        </div>

        <!-- Core Tools -->
        <div class="section-title">Core Tools</div>
        <div class="tools-grid">
            <a href="/admin/buyers/" class="tool-card">
                <div class="icon">👥</div>
                <h3>Buyer Management</h3>
                <p>Create buyers, add credits, manage campaigns, view transactions and lead delivery.</p>
                <span class="badge green">Primary</span>
            </a>

            <a href="/buyer/" class="tool-card">
                <div class="icon">🔐</div>
                <h3>Buyer Portal</h3>
                <p>Contractor login portal where buyers view their leads, balance, and export data.</p>
                <span class="badge">Portal</span>
            </a>

            <a href="/docs" class="tool-card">
                <div class="icon">📡</div>
                <h3>API Documentation</h3>
                <p>Swagger docs for the lead distribution API. Ping/post endpoints, webhooks, campaigns.</p>
                <span class="badge purple">API</span>
            </a>
        </div>

        <!-- Analytics & Testing -->
        <div class="section-title">Analytics & Testing</div>
        <div class="tools-grid">
            <a href="/admin/ab-testing/" class="tool-card">
                <div class="icon">🧪</div>
                <h3>A/B Testing</h3>
                <p>Create experiments, test landing page variants, track conversion rates, declare winners.</p>
                <span class="badge yellow">Testing</span>
            </a>

            <a href="/admin/analytics/" class="tool-card">
                <div class="icon">📊</div>
                <h3>Analytics</h3>
                <p>Unified analytics dashboard combining web conversions with phone call attribution.</p>
                <span class="badge">Insights</span>
            </a>

            <a href="/admin/flow/" class="tool-card">
                <div class="icon">🔄</div>
                <h3>Customer Flow</h3>
                <p>1-2-3-4-5 system for managing customer journey from lead to review request.</p>
                <span class="badge">Workflow</span>
            </a>
        </div>

        <!-- Operations -->
        <div class="section-title">Operations</div>
        <div class="tools-grid">
            <a href="/admin/calls/" class="tool-card">
                <div class="icon">📞</div>
                <h3>Outgoing Calls</h3>
                <p>Make recorded outbound calls to leads. View call history and listen to recordings.</p>
                <span class="badge green">Calls</span>
            </a>

            <a href="/admin/quotes/" class="tool-card">
                <div class="icon">💬</div>
                <h3>Quote System</h3>
                <p>Send SMS quotes with mobile-friendly quote pages. Test and monitor delivery.</p>
                <span class="badge">SMS</span>
            </a>

            <a href="/admin/leads_approval.php" class="tool-card">
                <div class="icon">✅</div>
                <h3>Lead Approval</h3>
                <p>Review and approve leads before distribution. Quality control workflow.</p>
                <span class="badge yellow">QA</span>
            </a>

            <a href="/admin/dashboard.php" class="tool-card">
                <div class="icon">🎛️</div>
                <h3>Dashboard</h3>
                <p>Original admin dashboard with notes and quick actions.</p>
                <span class="badge">Legacy</span>
            </a>
        </div>

        <!-- Quick Links -->
        <div class="section-title">Quick Links</div>
        <div class="quick-links">
            <a href="https://sodjacksonvillefl.com" target="_blank" class="quick-link">🌱 sodjacksonvillefl.com</a>
            <a href="https://sodjax.com" target="_blank" class="quick-link">🌱 sodjax.com</a>
            <a href="https://jacksonvillesod.com" target="_blank" class="quick-link">🌱 jacksonvillesod.com</a>
            <a href="https://drainagejax.com" target="_blank" class="quick-link">💧 drainagejax.com</a>
            <a href="/admin/buyers/?tab=system" class="quick-link">⚙️ System Status</a>
            <a href="/admin/buyers/?tab=notes" class="quick-link">📝 Notes</a>
        </div>
    </div>

    <div class="footer">
        EzLead4U Lead Distribution Platform | <a href="/docs">API Docs</a>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
