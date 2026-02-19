<?php
/**
 * CustomerFlow Admin Dashboard
 * 1-2-3-4-5 System Management
 */

session_start();
if (empty($_SESSION['admin_hub_auth']) && ($_POST['password'] ?? '') !== 'Rain0nin') {
    if (isset($_POST['password'])) $error = 'Invalid password';
    ?>
    <!DOCTYPE html>
    <html><head><title>Login</title>
    <style>body{font-family:system-ui;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#0f172a}
    form{background:#1e293b;padding:2rem;border-radius:8px;color:#fff}
    input{display:block;width:200px;padding:.5rem;margin:.5rem 0 1rem;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#fff}
    button{background:#fbbf24;color:#78350f;border:none;padding:.75rem 1.5rem;border-radius:4px;cursor:pointer;font-weight:600}</style>
    </head><body><form method="post"><h2>Flow Admin</h2>
    <?php if(isset($error)):?><p style="color:#ef4444"><?=$error?></p><?php endif;?>
    <input type="password" name="password" placeholder="Password" autofocus>
    <button type="submit">Login</button></form><!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body></html>
    <?php
    exit;
}
$_SESSION['admin_hub_auth'] = true;

require_once __DIR__ . '/../../lib/CustomerFlow/Flow.php';

$flow = new CustomerFlow\Flow();

// Handle actions
if (isset($_POST['complete']) && isset($_POST['job_id'])) {
    $flow->complete($_POST['job_id']);
    header('Location: /admin/flow/');
    exit;
}

if (isset($_POST['followup']) && isset($_POST['job_id'])) {
    $flow->sendFollowUp($_POST['job_id']);
    header('Location: /admin/flow/');
    exit;
}

// Handle test quote
if (isset($_POST['test_phone'])) {
    $testResult = $flow->sendQuote([
        'phone' => $_POST['test_phone'],
        'name' => $_POST['test_name'] ?: 'Test',
        'vehicle' => $_POST['test_vehicle'] ?: '2020 Honda Accord',
        'services' => [['name' => 'Check Engine Diagnostic', 'price' => 150]],
        'total' => 150,
    ]);
}

$stats = $flow->getStats();
$jobs = $flow->getRecent(25);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flow Admin - EzLead4U</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;padding:1.5rem}
        .header{background:linear-gradient(135deg,#fbbf24,#f59e0b);padding:1.5rem;border-radius:8px;color:#78350f;margin-bottom:1.5rem}
        h1{font-size:1.5rem;margin-bottom:.25rem}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat{background:#1e293b;padding:1rem;border-radius:8px;text-align:center}
        .stat-num{font-size:2rem;font-weight:700;color:#fbbf24}
        .stat-label{font-size:.75rem;color:#94a3b8;margin-top:.25rem}
        .card{background:#1e293b;border-radius:8px;padding:1.5rem;margin-bottom:1.5rem}
        .card h2{font-size:1rem;margin-bottom:1rem;color:#fbbf24}
        table{width:100%;border-collapse:collapse;font-size:.875rem}
        th,td{padding:.75rem;text-align:left;border-bottom:1px solid #334155}
        th{color:#94a3b8;font-weight:500}
        .badge{display:inline-block;padding:.2rem .5rem;border-radius:12px;font-size:.7rem;font-weight:600}
        .badge-1{background:#3b82f6;color:#fff}
        .badge-2{background:#8b5cf6;color:#fff}
        .badge-3{background:#22c55e;color:#fff}
        .badge-4{background:#f59e0b;color:#fff}
        .badge-5{background:#ec4899;color:#fff}
        .btn{padding:.4rem .75rem;border:none;border-radius:4px;cursor:pointer;font-size:.75rem;font-weight:600}
        .btn-complete{background:#22c55e;color:#fff}
        .btn-followup{background:#ec4899;color:#fff}
        input{padding:.5rem;border:1px solid #334155;border-radius:4px;background:#0f172a;color:#fff;width:100%;margin-bottom:.5rem}
        .test-btn{background:#fbbf24;color:#78350f;border:none;padding:.75rem 1rem;border-radius:4px;cursor:pointer;font-weight:600;width:100%}
        .alert{background:#22c55e;color:#fff;padding:1rem;border-radius:4px;margin-bottom:1rem}
        a{color:#fbbf24}
        .nav{margin-bottom:1rem}
        .nav a{color:#94a3b8;margin-right:1rem;text-decoration:none}
        .nav a:hover{color:#fbbf24}
    </style>
</head>
<body>
    <div class="nav">
        <a href="/admin/">← Admin</a>
        <a href="/admin/flow/">Flow Dashboard</a>
    </div>

    <div class="header">
        <h1>1-2-3-4-5 Customer Flow</h1>
        <p>Quote → Approve → Complete → Review</p>
    </div>

    <?php if (isset($testResult)): ?>
    <div class="alert">
        Test quote sent! Job ID: <strong><?= htmlspecialchars($testResult['job_id']) ?></strong>
        | <a href="/q/<?= htmlspecialchars($testResult['job_id']) ?>" target="_blank" style="color:#fff">View Quote</a>
    </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat"><div class="stat-num"><?= $stats['total'] ?? 0 ?></div><div class="stat-label">Total Jobs</div></div>
        <div class="stat"><div class="stat-num"><?= $stats['approved'] ?? 0 ?></div><div class="stat-label">Approved</div></div>
        <div class="stat"><div class="stat-num"><?= $stats['completed'] ?? 0 ?></div><div class="stat-label">Completed</div></div>
        <div class="stat"><div class="stat-num"><?= $stats['followed_up'] ?? 0 ?></div><div class="stat-label">Reviewed</div></div>
    </div>

    <div class="card">
        <h2>Test Quote</h2>
        <form method="post">
            <input type="tel" name="test_phone" value="+19046634789" placeholder="Phone" required>
            <input type="text" name="test_name" value="Kyle" placeholder="Name">
            <input type="text" name="test_vehicle" value="2020 Honda Accord" placeholder="Vehicle">
            <button type="submit" class="test-btn">Send Test Quote</button>
        </form>
    </div>

    <div class="card">
        <h2>Live Call Test</h2>
        <p style="margin-bottom:.75rem;color:#94a3b8">Call <strong style="color:#fbbf24">(904) 706-6669</strong> and say:</p>
        <p style="background:#0f172a;padding:.75rem;border-radius:4px;font-size:.9rem">"Hi, I'm Kyle. I have a 2018 Honda Accord with a check engine light. Code P0420."</p>
    </div>

    <div class="card">
        <h2>Recent Jobs</h2>
        <table>
            <thead>
                <tr><th>Job</th><th>Customer</th><th>Vehicle</th><th>Total</th><th>Step</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $job): ?>
                <tr>
                    <td><a href="/q/<?= htmlspecialchars($job['job_id']) ?>" target="_blank"><?= htmlspecialchars($job['job_id']) ?></a></td>
                    <td><?= htmlspecialchars($job['name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($job['vehicle'] ?: '-') ?></td>
                    <td>$<?= number_format($job['total'], 2) ?></td>
                    <td>
                        <?php
                        $step = 1;
                        if ($job['followup_sent_at']) $step = 5;
                        elseif ($job['completed_at']) $step = 4;
                        elseif ($job['approved_at']) $step = 3;
                        elseif ($job['quote_viewed_at']) $step = 2;
                        ?>
                        <span class="badge badge-<?= $step ?>">Step <?= $step ?></span>
                    </td>
                    <td>
                        <?php if ($job['approved_at'] && !$job['completed_at']): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="job_id" value="<?= htmlspecialchars($job['job_id']) ?>">
                            <button type="submit" name="complete" class="btn btn-complete">Complete</button>
                        </form>
                        <?php elseif ($job['completed_at'] && !$job['followup_sent_at']): ?>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="job_id" value="<?= htmlspecialchars($job['job_id']) ?>">
                            <button type="submit" name="followup" class="btn btn-followup">Send Review</button>
                        </form>
                        <?php elseif ($job['followup_sent_at']): ?>
                        <span style="color:#22c55e">✓ Done</span>
                        <?php else: ?>
                        <span style="color:#94a3b8">Waiting</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($jobs)): ?>
                <tr><td colspan="6" style="text-align:center;color:#94a3b8">No jobs yet. Send a test quote above.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
