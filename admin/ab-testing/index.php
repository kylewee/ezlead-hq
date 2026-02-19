<?php
/**
 * A/B Testing Admin Dashboard
 *
 * Features:
 * - View all experiments and their status
 * - See real-time stats for each variant
 * - View statistical significance
 * - Manually declare winners
 * - Reset experiments for new testing cycles
 */

session_start();

// Authentication - standardized with admin hub
$validPassword = 'Rain0nin';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/ab-testing/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === $validPassword) {
        $_SESSION['admin_hub_auth'] = true;
        header('Location: /admin/ab-testing/');
        exit;
    } else {
        $loginError = 'Invalid password';
    }
}

$isAuthenticated = !empty($_SESSION['admin_hub_auth']);

if (!$isAuthenticated) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>A/B Testing Admin - Login</title>
        <style>
            body {
                font-family: system-ui, -apple-system, sans-serif;
                background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .login-box {
                background: rgba(15, 23, 42, 0.95);
                border: 1px solid rgba(148, 163, 184, 0.25);
                border-radius: 16px;
                padding: 48px;
                max-width: 400px;
                width: 100%;
                color: #e2e8f0;
            }
            h1 { margin: 0 0 8px; font-size: 1.8rem; }
            p { color: #94a3b8; margin: 0 0 32px; }
            label { display: block; margin-bottom: 8px; font-weight: 500; }
            input {
                width: 100%;
                padding: 12px;
                border: 1px solid rgba(148, 163, 184, 0.25);
                border-radius: 8px;
                background: rgba(15, 23, 42, 0.6);
                color: #e2e8f0;
                font-size: 1rem;
                margin-bottom: 20px;
                box-sizing: border-box;
            }
            input:focus { outline: none; border-color: #2563eb; }
            button {
                width: 100%;
                padding: 14px;
                background: #2563eb;
                color: white;
                border: none;
                border-radius: 8px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
            }
            button:hover { background: #1d4ed8; }
            .error {
                background: rgba(239, 68, 68, 0.15);
                border: 1px solid rgba(239, 68, 68, 0.3);
                color: #fca5a5;
                padding: 12px;
                border-radius: 8px;
                margin-bottom: 20px;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>A/B Testing Admin</h1>
            <p>EzLead4U</p>
            <?php if (isset($loginError)): ?>
                <div class="error"><?= htmlspecialchars($loginError) ?></div>
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

// Load A/B Testing library
require_once __DIR__ . '/../../lib/ABTesting.php';

$ab = new ABTesting();

// Handle actions
$message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'reset':
                $expName = $_POST['experiment'] ?? '';
                if ($ab->resetExperiment($expName)) {
                    $message = ['type' => 'success', 'text' => "Experiment '$expName' has been reset."];
                } else {
                    $message = ['type' => 'error', 'text' => "Failed to reset experiment."];
                }
                break;

            case 'declare_winner':
                $expName = $_POST['experiment'] ?? '';
                $winner = $_POST['winner'] ?? '';
                // Manual winner declaration would require adding a method
                $message = ['type' => 'info', 'text' => "Winner declaration feature coming soon."];
                break;
        }
    }
}

// Get all experiments
$experiments = $ab->getAllExperiments();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>A/B Testing Dashboard - EzLead4U</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: rgba(15, 23, 42, 0.75);
            --accent: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --border: rgba(148, 163, 184, 0.25);
        }

        * { box-sizing: border-box; }

        body {
            font-family: "Inter", system-ui, -apple-system, sans-serif;
            margin: 0;
            background: radial-gradient(circle at top, rgba(37, 99, 235, 0.25), transparent 55%),
                        radial-gradient(circle at bottom, rgba(14, 116, 144, 0.2), transparent 60%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
            padding: 24px 16px 80px;
        }

        header {
            max-width: 1400px;
            margin: 0 auto 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            margin: 0;
            font-size: 2rem;
        }

        .nav-links {
            display: flex;
            gap: 1rem;
        }

        .nav-links a {
            color: var(--muted);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: var(--text);
        }

        .logout-btn {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        main {
            max-width: 1400px;
            margin: 0 auto;
        }

        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .message.success {
            background: rgba(16, 185, 129, 0.15);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #6ee7b7;
        }

        .message.error {
            background: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .message.info {
            background: rgba(37, 99, 235, 0.15);
            border: 1px solid rgba(37, 99, 235, 0.3);
            color: #93c5fd;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--accent);
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .experiment-card {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .experiment-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .experiment-header h2 {
            margin: 0;
            font-size: 1.3rem;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: #6ee7b7;
        }

        .status-completed {
            background: rgba(37, 99, 235, 0.2);
            color: #93c5fd;
        }

        .variants-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }

        .variants-table th {
            text-align: left;
            padding: 12px 8px;
            border-bottom: 2px solid var(--border);
            color: var(--muted);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .variants-table td {
            padding: 12px 8px;
            border-bottom: 1px solid rgba(148, 163, 184, 0.1);
        }

        .variants-table tr:hover {
            background: rgba(37, 99, 235, 0.05);
        }

        .conversion-rate {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .conversion-rate.winner {
            color: var(--success);
        }

        .confidence-bar {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            height: 8px;
            width: 100px;
            overflow: hidden;
        }

        .confidence-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }

        .confidence-low { background: var(--danger); }
        .confidence-medium { background: var(--warning); }
        .confidence-high { background: var(--success); }

        .actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--accent);
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .btn-danger {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-danger:hover {
            background: rgba(239, 68, 68, 0.3);
        }

        .no-experiments {
            text-align: center;
            padding: 4rem;
            color: var(--muted);
        }

        .no-experiments h2 {
            margin-bottom: 1rem;
        }

        .chart-placeholder {
            background: rgba(15, 23, 42, 0.5);
            border: 1px dashed var(--border);
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            color: var(--muted);
            margin-top: 1rem;
        }

        footer {
            max-width: 1400px;
            margin: 48px auto 0;
            text-align: center;
            color: var(--muted);
            font-size: 0.85rem;
        }

        footer a {
            color: var(--accent);
            text-decoration: none;
        }
    </style>
</head>
<body>
    <header>
        <h1>📊 A/B Testing Dashboard</h1>
        <div class="nav-links">
            <a href="/admin/">← Admin Home</a>
            <a href="?logout" class="logout-btn">Logout</a>
        </div>
    </header>

    <main>
        <?php if ($message): ?>
        <div class="message <?= $message['type'] ?>">
            <?= htmlspecialchars($message['text']) ?>
        </div>
        <?php endif; ?>

        <!-- Stats Overview -->
        <div class="stats-overview">
            <div class="stat-card">
                <div class="stat-value"><?= count($experiments) ?></div>
                <div class="stat-label">Total Experiments</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count(array_filter($experiments, fn($e) => $e['status'] === 'active')) ?></div>
                <div class="stat-label">Active</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($experiments, 'total_visitors')) ?></div>
                <div class="stat-label">Total Visitors</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= array_sum(array_column($experiments, 'total_conversions')) ?></div>
                <div class="stat-label">Total Conversions</div>
            </div>
        </div>

        <?php if (empty($experiments)): ?>
        <div class="no-experiments">
            <h2>No Experiments Yet</h2>
            <p>Experiments are created automatically when visitors access A/B tested pages.</p>
            <p>Try visiting: <a href="/services/diagnostics/" style="color: var(--accent);">/services/diagnostics/</a></p>
        </div>
        <?php else: ?>

        <?php foreach ($experiments as $exp): ?>
        <?php $stats = $ab->getStats($exp['name']); ?>
        <div class="experiment-card">
            <div class="experiment-header">
                <div>
                    <h2><?= htmlspecialchars($exp['name']) ?></h2>
                    <small style="color: var(--muted);">Created: <?= $exp['created_at'] ?></small>
                </div>
                <span class="status-badge status-<?= $exp['status'] ?>">
                    <?= $exp['status'] ?>
                    <?php if ($exp['winner_variant']): ?>
                    - Winner: <?= htmlspecialchars($exp['winner_variant']) ?>
                    <?php endif; ?>
                </span>
            </div>

            <table class="variants-table">
                <thead>
                    <tr>
                        <th>Variant</th>
                        <th>Views</th>
                        <th>Conversions</th>
                        <th>Rate</th>
                        <th>Confidence</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maxRate = max(array_column($stats, 'conversion_rate'));
                    foreach ($stats as $stat):
                        $isWinner = $stat['conversion_rate'] == $maxRate && $maxRate > 0;
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($stat['variant']) ?></strong>
                            <?php if ($stat['is_control']): ?>
                            <span style="color: var(--muted); font-size: 0.8rem;">(Control)</span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($stat['views']) ?></td>
                        <td><?= number_format($stat['conversions']) ?></td>
                        <td>
                            <span class="conversion-rate <?= $isWinner ? 'winner' : '' ?>">
                                <?= $stat['conversion_rate'] ?>%
                            </span>
                        </td>
                        <td>
                            <?php if (!$stat['is_control']): ?>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <div class="confidence-bar">
                                    <div class="confidence-fill <?= $stat['confidence'] >= 95 ? 'confidence-high' : ($stat['confidence'] >= 80 ? 'confidence-medium' : 'confidence-low') ?>"
                                         style="width: <?= min(100, $stat['confidence']) ?>%;"></div>
                                </div>
                                <span style="font-size: 0.9rem;"><?= $stat['confidence'] ?>%</span>
                            </div>
                            <?php else: ?>
                            <span style="color: var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="chart-placeholder">
                📈 Conversion trend chart coming soon
            </div>

            <div class="actions">
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to reset this experiment? All data will be lost.');">
                    <input type="hidden" name="action" value="reset">
                    <input type="hidden" name="experiment" value="<?= htmlspecialchars($exp['name']) ?>">
                    <button type="submit" class="btn btn-danger">Reset Experiment</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </main>

    <footer>
        <p>EzLead4U A/B Testing System | <a href="/admin/">Back to Admin</a></p>
    </footer>

    <script>
        // Auto-refresh every 30 seconds
        setTimeout(() => location.reload(), 30000);
    </script>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
