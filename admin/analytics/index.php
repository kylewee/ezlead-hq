<?php
/**
 * Unified Analytics Dashboard
 *
 * Shows A/B testing results with phone call attribution.
 * Combines web conversion data with phone call conversion data.
 */

require_once __DIR__ . '/../../lib/ABTesting.php';
require_once __DIR__ . '/../../lib/CallTracking.php';
require_once __DIR__ . '/../../lib/Analytics.php';

// Simple auth check
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
    <head><title>Analytics Login</title>
    <style>
        body { font-family: system-ui; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; background: #f1f5f9; }
        form { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        input { display: block; width: 200px; padding: 0.5rem; margin: 0.5rem 0 1rem; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #2563eb; color: white; border: none; padding: 0.75rem 1.5rem; border-radius: 4px; cursor: pointer; }
    </style>
    </head>
    <body>
        <form method="post">
            <h2>Analytics Dashboard</h2>
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

// Get data
$ab = new ABTesting();
$callTracker = new CallTracking();

$experiments = $ab->getAllExperiments();
$callStats = $callTracker->getABCallStats();
$recentCalls = $callTracker->getRecentCalls(10);

// Calculate combined metrics
$combinedStats = [];
foreach ($experiments as $exp) {
    $stats = $ab->getStats($exp['name']);
    $expCallStats = array_filter($callStats, fn($c) => $c['ab_experiment'] === $exp['name']);

    // Group call stats by variant
    $callsByVariant = [];
    foreach ($expCallStats as $cs) {
        $v = $cs['ab_variant'] ?? 'unknown';
        if (!isset($callsByVariant[$v])) {
            $callsByVariant[$v] = ['total_calls' => 0, 'answered' => 0, 'leads' => 0];
        }
        $callsByVariant[$v]['total_calls'] += $cs['total_calls'];
        $callsByVariant[$v]['answered'] += $cs['answered_calls'];
        $callsByVariant[$v]['leads'] += $cs['leads_created'];
    }

    $combinedStats[$exp['name']] = [
        'experiment' => $exp,
        'ab_stats' => $stats,
        'call_stats' => $callsByVariant
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics Dashboard - EzLead4U</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --dark: #0f172a;
            --light: #f8fafc;
            --border: #e2e8f0;
        }

        body {
            font-family: "Inter", system-ui, -apple-system, sans-serif;
            background: var(--light);
            color: var(--dark);
            line-height: 1.5;
        }

        header {
            background: var(--dark);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 { font-size: 1.25rem; }

        .nav-links a {
            color: white;
            text-decoration: none;
            margin-left: 1.5rem;
            opacity: 0.8;
        }

        .nav-links a:hover { opacity: 1; }

        main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
            text-align: center;
        }

        .stat-card .number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        .stat-card .label {
            font-size: 0.875rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        .section {
            background: white;
            border: 1px solid var(--border);
            border-radius: 8px;
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .section-header {
            background: var(--light);
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .section-body { padding: 1.5rem; }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }

        th {
            background: var(--light);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }

        tr:last-child td { border-bottom: none; }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success { background: rgba(16, 185, 129, 0.1); color: var(--success); }
        .badge-warning { background: rgba(245, 158, 11, 0.1); color: var(--warning); }
        .badge-info { background: rgba(37, 99, 235, 0.1); color: var(--primary); }
        .badge-danger { background: rgba(239, 68, 68, 0.1); color: var(--danger); }

        .progress-bar {
            height: 8px;
            background: var(--light);
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s;
        }

        .variant-comparison {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        .variant-card {
            border: 2px solid var(--border);
            border-radius: 8px;
            padding: 1.5rem;
        }

        .variant-card.winner {
            border-color: var(--success);
            background: rgba(16, 185, 129, 0.05);
        }

        .variant-card h3 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .metric-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .metric-row:last-child { border-bottom: none; }

        .metric-label { color: #64748b; }
        .metric-value { font-weight: 600; }

        .call-log {
            max-height: 400px;
            overflow-y: auto;
        }

        .call-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }

        .call-item:last-child { border-bottom: none; }

        .call-info h4 { font-size: 0.9rem; margin-bottom: 0.25rem; }
        .call-info p { font-size: 0.8rem; color: #64748b; }

        .call-meta { text-align: right; }
        .call-meta .time { font-size: 0.8rem; color: #64748b; }

        @media (max-width: 768px) {
            .variant-comparison { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <header>
        <h1>Analytics Dashboard</h1>
        <nav class="nav-links">
            <a href="/admin/ab-testing/">A/B Tests</a>
            <a href="/admin/">Admin Home</a>
            <a href="/">Website</a>
        </nav>
    </header>

    <main>
        <!-- Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="number"><?php echo count($experiments); ?></div>
                <div class="label">Active Experiments</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php
                    $totalViews = 0;
                    foreach ($combinedStats as $cs) {
                        foreach ($cs['ab_stats']['variants'] ?? [] as $v) {
                            $totalViews += $v['views'];
                        }
                    }
                    echo number_format($totalViews);
                ?></div>
                <div class="label">Total Page Views</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php
                    $totalCalls = 0;
                    foreach ($callStats as $cs) {
                        $totalCalls += $cs['total_calls'];
                    }
                    echo number_format($totalCalls);
                ?></div>
                <div class="label">Tracked Calls</div>
            </div>
            <div class="stat-card">
                <div class="number"><?php
                    $attributedCalls = 0;
                    foreach ($callStats as $cs) {
                        if ($cs['ab_experiment']) {
                            $attributedCalls += $cs['total_calls'];
                        }
                    }
                    echo number_format($attributedCalls);
                ?></div>
                <div class="label">A/B Attributed Calls</div>
            </div>
        </div>

        <!-- A/B Experiments with Call Data -->
        <?php foreach ($combinedStats as $expName => $data): ?>
        <div class="section">
            <div class="section-header">
                <span><?php echo htmlspecialchars($expName); ?></span>
                <span class="badge <?php echo $data['experiment']['status'] === 'active' ? 'badge-success' : 'badge-warning'; ?>">
                    <?php echo ucfirst($data['experiment']['status']); ?>
                </span>
            </div>
            <div class="section-body">
                <?php if (empty($data['ab_stats']['variants'])): ?>
                <p>No data collected yet for this experiment.</p>
                <?php else: ?>
                <div class="variant-comparison">
                    <?php foreach ($data['ab_stats']['variants'] as $variant): ?>
                    <div class="variant-card <?php echo $data['ab_stats']['winner'] === $variant['name'] ? 'winner' : ''; ?>">
                        <h3>
                            Variant <?php echo htmlspecialchars($variant['name']); ?>
                            <?php if ($data['ab_stats']['winner'] === $variant['name']): ?>
                            <span class="badge badge-success">Winner</span>
                            <?php elseif ($variant['is_control']): ?>
                            <span class="badge badge-info">Control</span>
                            <?php endif; ?>
                        </h3>

                        <div class="metric-row">
                            <span class="metric-label">Page Views</span>
                            <span class="metric-value"><?php echo number_format($variant['views']); ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Web Conversions</span>
                            <span class="metric-value"><?php echo number_format($variant['conversions']); ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Web Conversion Rate</span>
                            <span class="metric-value"><?php echo number_format($variant['conversion_rate'] * 100, 2); ?>%</span>
                        </div>

                        <?php
                        $variantCalls = $data['call_stats'][$variant['name']] ?? ['total_calls' => 0, 'answered' => 0, 'leads' => 0];
                        ?>
                        <div class="metric-row" style="margin-top: 1rem; border-top: 2px solid var(--border); padding-top: 1rem;">
                            <span class="metric-label">Phone Calls</span>
                            <span class="metric-value"><?php echo number_format($variantCalls['total_calls']); ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Answered Calls</span>
                            <span class="metric-value"><?php echo number_format($variantCalls['answered']); ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Leads from Calls</span>
                            <span class="metric-value"><?php echo number_format($variantCalls['leads']); ?></span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Call Rate</span>
                            <span class="metric-value"><?php
                                $callRate = $variant['views'] > 0 ? ($variantCalls['total_calls'] / $variant['views']) * 100 : 0;
                                echo number_format($callRate, 2);
                            ?>%</span>
                        </div>

                        <!-- Combined Conversion Rate -->
                        <?php
                        $totalConversions = $variant['conversions'] + $variantCalls['leads'];
                        $combinedRate = $variant['views'] > 0 ? ($totalConversions / $variant['views']) * 100 : 0;
                        ?>
                        <div class="metric-row" style="margin-top: 1rem; background: var(--light); margin: 1rem -1.5rem -1.5rem; padding: 1rem 1.5rem;">
                            <span class="metric-label"><strong>Combined Conversion Rate</strong></span>
                            <span class="metric-value" style="color: var(--primary); font-size: 1.25rem;"><?php echo number_format($combinedRate, 2); ?>%</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Confidence Level -->
                <?php if ($data['ab_stats']['confidence'] > 0): ?>
                <div style="margin-top: 1.5rem;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.5rem;">
                        <span>Statistical Confidence</span>
                        <span><?php echo number_format($data['ab_stats']['confidence'], 1); ?>%</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?php echo min($data['ab_stats']['confidence'], 100); ?>%; background: <?php echo $data['ab_stats']['confidence'] >= 95 ? 'var(--success)' : 'var(--warning)'; ?>;"></div>
                    </div>
                    <p style="font-size: 0.8rem; color: #64748b; margin-top: 0.5rem;">
                        <?php if ($data['ab_stats']['confidence'] >= 95): ?>
                        Statistical significance reached. Results are reliable.
                        <?php else: ?>
                        Need more data for statistically significant results (95% threshold).
                        <?php endif; ?>
                    </p>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Recent Calls -->
        <div class="section">
            <div class="section-header">
                <span>Recent Tracked Calls</span>
                <a href="/api/call-track.php?action=recent&limit=50" style="font-size: 0.875rem; color: var(--primary);">View All</a>
            </div>
            <div class="section-body">
                <?php if (empty($recentCalls)): ?>
                <p style="text-align: center; color: #64748b; padding: 2rem;">No calls tracked yet.</p>
                <?php else: ?>
                <div class="call-log">
                    <?php foreach ($recentCalls as $call): ?>
                    <div class="call-item">
                        <div class="call-info">
                            <h4>
                                <?php echo htmlspecialchars(substr($call['caller_phone'], 0, 3) . '***' . substr($call['caller_phone'], -4)); ?>
                                <?php if ($call['was_answered']): ?>
                                <span class="badge badge-success">Answered</span>
                                <?php else: ?>
                                <span class="badge badge-warning">Missed</span>
                                <?php endif; ?>
                            </h4>
                            <p>
                                <?php if ($call['ab_experiment']): ?>
                                Experiment: <?php echo htmlspecialchars($call['ab_experiment']); ?> /
                                Variant: <?php echo htmlspecialchars($call['ab_variant']); ?>
                                <?php else: ?>
                                Not attributed to A/B test
                                <?php endif; ?>
                                <?php if ($call['attribution_method']): ?>
                                (<?php echo htmlspecialchars($call['attribution_method']); ?>)
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="call-meta">
                            <?php if ($call['call_duration'] > 0): ?>
                            <div><?php echo gmdate('i:s', $call['call_duration']); ?></div>
                            <?php endif; ?>
                            <div class="time"><?php echo date('M j, g:i a', strtotime($call['created_at'])); ?></div>
                            <?php if ($call['lead_created']): ?>
                            <span class="badge badge-success">Lead #<?php echo $call['lead_id']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attribution Methods -->
        <div class="section">
            <div class="section-header">
                <span>Call Attribution Methods</span>
            </div>
            <div class="section-body">
                <table>
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Calls</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $methodCounts = [];
                        foreach ($callStats as $cs) {
                            $method = $cs['attribution_method'] ?? 'none';
                            $methodCounts[$method] = ($methodCounts[$method] ?? 0) + $cs['total_calls'];
                        }
                        $methodDescs = [
                            'click_tracking' => 'User clicked phone link within 5 minutes of call',
                            'recent_experiment' => 'Attributed to most recent active experiment',
                            'none' => 'No web session data available for attribution'
                        ];
                        foreach ($methodCounts as $method => $count):
                        ?>
                        <tr>
                            <td><span class="badge badge-info"><?php echo htmlspecialchars($method); ?></span></td>
                            <td><?php echo number_format($count); ?></td>
                            <td><?php echo htmlspecialchars($methodDescs[$method] ?? 'Unknown method'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
