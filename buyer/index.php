<?php
/**
 * Buyer Portal - Dashboard
 * Shows leads from CRM (app_entity_25)
 */
require_once __DIR__ . '/auth.php';
$user = requireAuth();

$db = getDb();

// Get lead stats
$stats = ['balance' => floatval($user['balance'] ?? 0), 'leads_total' => 0, 'leads_this_week' => 0, 'leads_today' => 0];

// Total leads for this buyer
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM app_entity_25 WHERE field_219 = :uid");
$stmt->execute([':uid' => $user['user_id']]);
$stats['leads_total'] = $stmt->fetch()['cnt'];

// Leads this week
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM app_entity_25 WHERE field_219 = :uid AND date_added >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$stmt->execute([':uid' => $user['user_id']]);
$stats['leads_this_week'] = $stmt->fetch()['cnt'];

// Leads today
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM app_entity_25 WHERE field_219 = :uid AND DATE(date_added) = CURDATE()");
$stmt->execute([':uid' => $user['user_id']]);
$stats['leads_today'] = $stmt->fetch()['cnt'];

// Get recent leads
$stmt = $db->prepare("
    SELECT id, field_210 as name, field_211 as phone, field_212 as email,
           field_213 as address, field_214 as zip, field_216 as vertical,
           field_217 as notes, field_218 as stage, date_added
    FROM app_entity_25
    WHERE field_219 = :uid
    ORDER BY date_added DESC
    LIMIT 50
");
$stmt->execute([':uid' => $user['user_id']]);
$leads = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Portal - Dashboard</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; min-height: 100vh; }
        .header { background: linear-gradient(135deg, #1e3a5f 0%, #2a4a75 100%); color: white; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 20px; }
        .header-right { display: flex; align-items: center; gap: 20px; }
        .balance { background: rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 8px; font-size: 14px; }
        .balance strong { font-size: 18px; }
        .header a { color: rgba(255,255,255,0.8); text-decoration: none; font-size: 14px; }
        .header a:hover { color: white; }
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 24px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .stat-card h3 { font-size: 13px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-card .value { font-size: 32px; font-weight: 700; color: #1e3a5f; }
        .stat-card.balance-card .value { color: #27ae60; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .section-header h2 { font-size: 20px; color: #333; }
        .leads-table { background: white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); overflow: hidden; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; font-size: 13px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
        td { font-size: 14px; }
        tr:hover { background: #fafbfc; }
        tr:last-child td { border-bottom: none; }
        .lead-name { font-weight: 600; color: #333; }
        .lead-contact { color: #666; font-size: 13px; }
        .lead-vertical { display: inline-block; padding: 4px 10px; background: #e8f4fd; color: #1e3a5f; border-radius: 4px; font-size: 12px; }
        .empty-state { text-align: center; padding: 60px 20px; color: #666; }
        .empty-state h3 { font-size: 18px; margin-bottom: 10px; color: #333; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Buyer Portal</h1>
        <div class="header-right">
            <span>Welcome, <?= htmlspecialchars($user['company'] ?: $user['email']) ?></span>
            <div class="balance">Balance: <strong>$<?= number_format($stats['balance'], 2) ?></strong></div>
            <a href="/buyer/logout.php">Logout</a>
        </div>
    </div>
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card balance-card"><h3>Balance</h3><div class="value">$<?= number_format($stats['balance'], 2) ?></div></div>
            <div class="stat-card"><h3>Leads Today</h3><div class="value"><?= $stats['leads_today'] ?></div></div>
            <div class="stat-card"><h3>This Week</h3><div class="value"><?= $stats['leads_this_week'] ?></div></div>
            <div class="stat-card"><h3>Total Leads</h3><div class="value"><?= $stats['leads_total'] ?></div></div>
        </div>
        <div class="section-header"><h2>Recent Leads</h2></div>
        <div class="leads-table">
            <?php if (empty($leads)): ?>
                <div class="empty-state"><h3>No leads yet</h3><p>Leads will appear here as they're assigned to you.</p></div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Name</th><th>Contact</th><th>Location</th><th>Service</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td><div class="lead-name"><?= htmlspecialchars($lead['name']) ?></div></td>
                            <td><div><?= htmlspecialchars($lead['phone']) ?></div><div class="lead-contact"><?= htmlspecialchars($lead['email']) ?></div></td>
                            <td><div><?= htmlspecialchars($lead['address']) ?></div><div class="lead-contact"><?= htmlspecialchars($lead['zip']) ?></div></td>
                            <td><span class="lead-vertical"><?= htmlspecialchars($lead['vertical']) ?></span></td>
                            <td><?= date('M j, g:i a', strtotime($lead['date_added'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
