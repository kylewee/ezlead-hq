<?php
/**
 * Buyer Portal - Admin Dashboard
 * Create buyers, manage verticals, call routing, notes
 *
 * Features:
 * - Buyer management (create, credit, pause)
 * - Vertical management (sod, drainage, mechanic, etc.)
 * - Call routing options per vertical:
 *   - Voicemail: Customers leave message, transcribed and distributed
 *   - Round Robin: Calls ring through to next available buyer in queue
 * - Admin notes for reminders and documentation
 *
 * Pricing Model:
 * - $35 per lead (configurable per buyer)
 * - 3 free test leads for new buyers
 * - $35 minimum balance (auto-pause when low)
 */

session_start();

// Simple admin auth - TODO: Add proper authentication
$isAdmin = true;

require_once __DIR__ . '/../BuyerAuth.php';
$auth = new BuyerAuth();
$db = $auth->getDb();

$message = '';
$messageType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // Create Buyer
    if ($action === 'create_buyer') {
        try {
            $buyerId = $auth->createBuyer([
                'email' => $_POST['email'],
                'password' => $_POST['password'],
                'name' => $_POST['name'],
                'company' => $_POST['company'] ?? '',
                'phone' => $_POST['phone'] ?? '',
                'price_per_lead' => (int)($_POST['price_per_lead'] ?? 35) * 100,
            ]);
            $message = "Buyer created with ID: {$buyerId}. They have 3 free test leads.";
        } catch (Exception $e) {
            $message = "Error: " . $e->getMessage();
            $messageType = 'error';
        }
    }

    // Add Credit
    if ($action === 'add_credit') {
        $buyerId = (int)$_POST['buyer_id'];
        $amount = (int)((float)$_POST['amount'] * 100);
        $auth->updateBalance($buyerId, $amount, 'deposit', 'Manual credit by admin');
        $message = "Added \${$_POST['amount']} to buyer #{$buyerId}";
    }

    // Toggle Buyer Status
    if ($action === 'toggle_buyer_status') {
        $buyerId = (int)$_POST['buyer_id'];
        $newStatus = $_POST['new_status'];
        $stmt = $db->prepare("UPDATE buyers SET status = :status, updated_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':status', $newStatus);
        $stmt->bindValue(':id', $buyerId);
        $stmt->execute();
        $message = "Buyer #{$buyerId} status changed to {$newStatus}";
    }

    // Create/Update Vertical
    if ($action === 'save_vertical') {
        $name = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['name']));
        $displayName = $_POST['display_name'];
        $description = $_POST['description'] ?? '';

        $stmt = $db->prepare("INSERT OR REPLACE INTO verticals (name, display_name, description) VALUES (:name, :display, :desc)");
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':display', $displayName);
        $stmt->bindValue(':desc', $description);
        $stmt->execute();
        $message = "Vertical '{$displayName}' saved";
    }

    // Update Vertical Settings (Call Routing)
    if ($action === 'update_vertical_settings') {
        $verticalId = (int)$_POST['vertical_id'];
        $callMode = $_POST['call_routing_mode']; // voicemail, round_robin, hybrid
        $voicemailEnabled = isset($_POST['voicemail_enabled']) ? '1' : '0';
        $roundRobinEnabled = isset($_POST['round_robin_enabled']) ? '1' : '0';
        $ringTimeout = (int)($_POST['ring_timeout'] ?? 30);
        $maxAttempts = (int)($_POST['max_attempts'] ?? 3);

        $settings = [
            'call_routing_mode' => $callMode,
            'voicemail_enabled' => $voicemailEnabled,
            'round_robin_enabled' => $roundRobinEnabled,
            'ring_timeout_seconds' => (string)$ringTimeout,
            'max_ring_attempts' => (string)$maxAttempts,
        ];

        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO vertical_settings (vertical_id, setting_key, setting_value, updated_at) VALUES (:vid, :key, :val, datetime('now'))");
            $stmt->bindValue(':vid', $verticalId);
            $stmt->bindValue(':key', $key);
            $stmt->bindValue(':val', $value);
            $stmt->execute();
        }
        $message = "Call routing settings updated";
    }

    // Add Buyer to Call Queue
    if ($action === 'add_to_queue') {
        $verticalId = (int)$_POST['vertical_id'];
        $buyerId = (int)$_POST['buyer_id'];
        $priority = (int)($_POST['priority'] ?? 0);

        $stmt = $db->prepare("INSERT OR REPLACE INTO call_routing_queue (vertical_id, buyer_id, priority) VALUES (:vid, :bid, :pri)");
        $stmt->bindValue(':vid', $verticalId);
        $stmt->bindValue(':bid', $buyerId);
        $stmt->bindValue(':pri', $priority);
        $stmt->execute();
        $message = "Buyer added to call queue";
    }

    // Remove from Queue
    if ($action === 'remove_from_queue') {
        $queueId = (int)$_POST['queue_id'];
        $db->exec("DELETE FROM call_routing_queue WHERE id = {$queueId}");
        $message = "Buyer removed from queue";
    }

    // Save Note
    if ($action === 'save_note') {
        $title = $_POST['title'] ?? '';
        $content = $_POST['content'];
        $category = $_POST['category'] ?? 'general';
        $isPinned = isset($_POST['is_pinned']) ? 1 : 0;

        if (!empty($_POST['note_id'])) {
            $stmt = $db->prepare("UPDATE admin_notes SET title = :title, content = :content, category = :cat, is_pinned = :pin, updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':id', (int)$_POST['note_id']);
        } else {
            $stmt = $db->prepare("INSERT INTO admin_notes (title, content, category, is_pinned) VALUES (:title, :content, :cat, :pin)");
        }
        $stmt->bindValue(':title', $title);
        $stmt->bindValue(':content', $content);
        $stmt->bindValue(':cat', $category);
        $stmt->bindValue(':pin', $isPinned);
        $stmt->execute();
        $message = "Note saved";
    }

    // Delete Note
    if ($action === 'delete_note') {
        $noteId = (int)$_POST['note_id'];
        $db->exec("DELETE FROM admin_notes WHERE id = {$noteId}");
        $message = "Note deleted";
    }

    // Run System Monitor
    if ($action === 'run_monitor') {
        $output = shell_exec('php ' . __DIR__ . '/../../cron/system_monitor.php 2>&1');
        $message = "System check complete. " . trim($output);
    }

    // Resolve Alert
    if ($action === 'resolve_alert') {
        $alertId = (int)$_POST['alert_id'];
        $db->exec("UPDATE system_alerts SET is_resolved = 1, resolved_at = datetime('now') WHERE id = {$alertId}");
        $message = "Alert resolved";
    }
}

// Get current tab
$tab = $_GET['tab'] ?? 'buyers';

// Get all data
$buyers = $auth->getAllBuyers();

// Get verticals with settings
$verticals = [];
$result = $db->query("SELECT * FROM verticals ORDER BY display_name");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $row['settings'] = [];
    $stmt = $db->prepare("SELECT setting_key, setting_value FROM vertical_settings WHERE vertical_id = :id");
    $stmt->bindValue(':id', $row['id']);
    $settingsResult = $stmt->execute();
    while ($setting = $settingsResult->fetchArray(SQLITE3_ASSOC)) {
        $row['settings'][$setting['setting_key']] = $setting['setting_value'];
    }
    $verticals[] = $row;
}

// Get call routing queues
$callQueues = [];
foreach ($verticals as $v) {
    $stmt = $db->prepare("
        SELECT q.*, b.name as buyer_name, b.company, b.phone, b.status as buyer_status
        FROM call_routing_queue q
        JOIN buyers b ON q.buyer_id = b.id
        WHERE q.vertical_id = :vid
        ORDER BY q.priority DESC, q.id ASC
    ");
    $stmt->bindValue(':vid', $v['id']);
    $result = $stmt->execute();
    $callQueues[$v['id']] = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $callQueues[$v['id']][] = $row;
    }
}

// Get notes
$notes = [];
$result = $db->query("SELECT * FROM admin_notes ORDER BY is_pinned DESC, updated_at DESC");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $notes[] = $row;
}

// Get recent leads
$recentLeads = [];
$result = $db->query("SELECT * FROM buyer_leads ORDER BY created_at DESC LIMIT 20");
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $recentLeads[] = $row;
}

// Get system alerts
$alerts = [];
$result = $db->query("SELECT * FROM system_alerts WHERE is_resolved = 0 ORDER BY created_at DESC LIMIT 20");
if ($result) {
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $alerts[] = $row;
    }
}

// Get recent resolved alerts
$resolvedAlerts = [];
$result = $db->query("SELECT * FROM system_alerts WHERE is_resolved = 1 ORDER BY resolved_at DESC LIMIT 10");
if ($result) {
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $resolvedAlerts[] = $row;
    }
}

// Get latest system stats
$systemStats = [];
$result = $db->query("SELECT stat_type, stat_value, MAX(recorded_at) as recorded_at FROM system_stats GROUP BY stat_type");
if ($result) {
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $systemStats[$row['stat_type']] = $row;
    }
}

// Stats
$totalBuyers = count($buyers);
$activeBuyers = count(array_filter($buyers, fn($b) => $b['status'] === 'active'));
$totalLeads = $db->querySingle("SELECT COUNT(*) FROM buyer_leads") ?? 0;
$totalRevenue = $db->querySingle("SELECT SUM(amount) FROM buyer_transactions WHERE type = 'deposit'") ?? 0;
$leadsToday = $db->querySingle("SELECT COUNT(*) FROM buyer_leads WHERE created_at >= date('now')") ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buyer Portal Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f7fa;
            min-height: 100vh;
        }
        .header {
            background: linear-gradient(135deg, #1e3a5f 0%, #2a4a75 100%);
            color: white;
            padding: 20px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header h1 { font-size: 22px; }
        .header-stats {
            display: flex;
            gap: 30px;
            font-size: 14px;
        }
        .header-stats span { opacity: 0.8; }
        .header-stats strong { font-size: 18px; }

        .container { max-width: 1400px; margin: 0 auto; padding: 30px; }

        .tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 30px;
            background: white;
            padding: 5px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .tab {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            color: #666;
            font-weight: 500;
            transition: all 0.2s;
        }
        .tab:hover { background: #f0f4f8; color: #1e3a5f; }
        .tab.active { background: #1e3a5f; color: white; }

        .message {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .message.success { background: #d4edda; color: #155724; }
        .message.error { background: #f8d7da; color: #721c24; }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 25px;
        }
        .card h2 {
            font-size: 18px;
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .card h3 {
            font-size: 15px;
            color: #666;
            margin: 20px 0 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .stat-card h3 { font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; }
        .stat-card .value { font-size: 28px; font-weight: 700; color: #1e3a5f; }
        .stat-card.green .value { color: #27ae60; }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        .form-row > * { flex: 1; min-width: 150px; }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        input, select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #1e3a5f;
        }
        textarea { resize: vertical; min-height: 100px; }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 0;
        }
        .checkbox-group input { width: auto; }

        button, .btn {
            padding: 10px 20px;
            background: #1e3a5f;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        button:hover, .btn:hover { background: #2a4a75; }
        .btn-secondary {
            background: white;
            color: #1e3a5f;
            border: 2px solid #1e3a5f;
        }
        .btn-secondary:hover { background: #f0f4f8; }
        .btn-danger { background: #dc3545; }
        .btn-danger:hover { background: #c82333; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
        th { font-weight: 600; color: #666; background: #f8f9fa; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover { background: #fafbfc; }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge.active { background: #d4edda; color: #155724; }
        .badge.paused { background: #fff3cd; color: #856404; }
        .badge.suspended { background: #f8d7da; color: #721c24; }
        .badge.voicemail { background: #e8f4fd; color: #1e3a5f; }
        .badge.round_robin { background: #e8f8ef; color: #27ae60; }
        .badge.hybrid { background: #fff3cd; color: #856404; }

        .inline-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .inline-form input { width: 80px; padding: 6px 10px; }
        .inline-form button { padding: 6px 12px; }

        .vertical-card {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
        }
        .vertical-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .vertical-header h3 { margin: 0; font-size: 16px; }

        .queue-list {
            background: white;
            border-radius: 6px;
            padding: 10px;
        }
        .queue-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .queue-item:last-child { border-bottom: none; }
        .queue-number {
            width: 30px;
            height: 30px;
            background: #1e3a5f;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 14px;
        }

        .note-card {
            background: #fffef0;
            border: 1px solid #f0e68c;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .note-card.pinned { border-color: #1e3a5f; background: #f0f4f8; }
        .note-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }
        .note-title { font-weight: 600; color: #333; }
        .note-meta { font-size: 12px; color: #666; }
        .note-content { font-size: 14px; line-height: 1.6; white-space: pre-wrap; }

        .help-text { font-size: 12px; color: #888; margin-top: 5px; }

        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
        @media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <div class="header">
        <h1>Buyer Portal Admin</h1>
        <div class="header-stats">
            <div><span>Buyers:</span> <strong><?= $activeBuyers ?>/<?= $totalBuyers ?></strong></div>
            <div><span>Leads Today:</span> <strong><?= $leadsToday ?></strong></div>
            <div><span>Total Leads:</span> <strong><?= $totalLeads ?></strong></div>
            <div><span>Revenue:</span> <strong>$<?= number_format($totalRevenue / 100, 2) ?></strong></div>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <div class="tabs">
            <a href="?tab=buyers" class="tab <?= $tab === 'buyers' ? 'active' : '' ?>">Buyers</a>
            <a href="?tab=verticals" class="tab <?= $tab === 'verticals' ? 'active' : '' ?>">Verticals & Call Routing</a>
            <a href="?tab=leads" class="tab <?= $tab === 'leads' ? 'active' : '' ?>">Recent Leads</a>
            <a href="?tab=notes" class="tab <?= $tab === 'notes' ? 'active' : '' ?>">Notes</a>
            <a href="?tab=system" class="tab <?= $tab === 'system' ? 'active' : '' ?>" style="<?= count($alerts) > 0 ? 'background:#dc3545;color:white;' : '' ?>">
                System <?= count($alerts) > 0 ? '(' . count($alerts) . ')' : '' ?>
            </a>
        </div>

        <?php if ($tab === 'buyers'): ?>
        <!-- BUYERS TAB -->
        <div class="stats-grid">
            <div class="stat-card green">
                <h3>Total Balance (All Buyers)</h3>
                <div class="value">$<?= number_format(array_sum(array_column($buyers, 'balance')) / 100, 2) ?></div>
            </div>
            <div class="stat-card">
                <h3>Active Buyers</h3>
                <div class="value"><?= $activeBuyers ?></div>
            </div>
            <div class="stat-card">
                <h3>Paused Buyers</h3>
                <div class="value"><?= count(array_filter($buyers, fn($b) => $b['status'] === 'paused')) ?></div>
            </div>
            <div class="stat-card">
                <h3>Avg Lead Price</h3>
                <div class="value">$<?= $totalBuyers > 0 ? number_format(array_sum(array_column($buyers, 'price_per_lead')) / $totalBuyers / 100, 2) : '0.00' ?></div>
            </div>
        </div>

        <div class="card">
            <h2>Create New Buyer</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_buyer">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="name" required>
                    </div>
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Company</label>
                        <input type="text" name="company">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone">
                    </div>
                    <div class="form-group">
                        <label>Price Per Lead ($)</label>
                        <input type="number" name="price_per_lead" value="35" min="1">
                        <div class="help-text">Default: $35. New buyers get 3 free test leads.</div>
                    </div>
                </div>
                <button type="submit">Create Buyer</button>
            </form>
        </div>

        <div class="card">
            <h2>All Buyers</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name / Company</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Balance</th>
                        <th>Free Leads</th>
                        <th>Price/Lead</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($buyers as $b): ?>
                        <tr>
                            <td><?= $b['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($b['name']) ?></strong>
                                <?php if ($b['company']): ?>
                                    <br><small style="color:#666"><?= htmlspecialchars($b['company']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($b['email']) ?></td>
                            <td><?= htmlspecialchars($b['phone'] ?? '-') ?></td>
                            <td><strong>$<?= number_format($b['balance'] / 100, 2) ?></strong></td>
                            <td><?= $b['free_leads_remaining'] ?? 0 ?></td>
                            <td>$<?= number_format($b['price_per_lead'] / 100, 2) ?></td>
                            <td><span class="badge <?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span></td>
                            <td>
                                <form method="POST" class="inline-form">
                                    <input type="hidden" name="action" value="add_credit">
                                    <input type="hidden" name="buyer_id" value="<?= $b['id'] ?>">
                                    <input type="number" name="amount" placeholder="$" step="0.01" min="0">
                                    <button type="submit" class="btn-sm">Add $</button>
                                </form>
                                <form method="POST" style="display:inline; margin-left: 10px;">
                                    <input type="hidden" name="action" value="toggle_buyer_status">
                                    <input type="hidden" name="buyer_id" value="<?= $b['id'] ?>">
                                    <?php if ($b['status'] === 'active'): ?>
                                        <input type="hidden" name="new_status" value="paused">
                                        <button type="submit" class="btn-sm btn-secondary">Pause</button>
                                    <?php else: ?>
                                        <input type="hidden" name="new_status" value="active">
                                        <button type="submit" class="btn-sm">Activate</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($buyers)): ?>
                        <tr><td colspan="9" style="text-align:center;color:#666;padding:30px;">No buyers yet. Create one above.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($tab === 'verticals'): ?>
        <!-- VERTICALS TAB -->
        <div class="two-col">
            <div>
                <div class="card">
                    <h2>Add New Vertical</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_vertical">
                        <div class="form-group">
                            <label>Vertical Name (lowercase, no spaces)</label>
                            <input type="text" name="name" placeholder="e.g. roofing" required pattern="[a-zA-Z0-9]+">
                        </div>
                        <div class="form-group">
                            <label>Display Name</label>
                            <input type="text" name="display_name" placeholder="e.g. Roofing Services" required>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" rows="2" placeholder="Brief description of this service vertical"></textarea>
                        </div>
                        <button type="submit">Add Vertical</button>
                    </form>
                </div>
            </div>

            <div>
                <div class="card">
                    <h2>Call Routing Explained</h2>
                    <p style="margin-bottom: 15px; color: #666; line-height: 1.6;">
                        Configure how incoming calls are handled for each vertical:
                    </p>
                    <p><span class="badge voicemail">Voicemail</span> Customers leave a message. It's transcribed by AI and distributed as a lead.</p>
                    <p style="margin-top: 10px;"><span class="badge round_robin">Round Robin</span> Calls ring through to buyers in order. If no answer, goes to next buyer in queue.</p>
                    <p style="margin-top: 10px;"><span class="badge hybrid">Hybrid</span> Try round robin first, fall back to voicemail if no one answers.</p>
                    <p style="margin-top: 15px; font-size: 13px; color: #888;">
                        <strong>Tip:</strong> For new verticals, start with Voicemail to capture all leads. Switch to Round Robin once you have reliable buyers.
                    </p>
                </div>
            </div>
        </div>

        <?php foreach ($verticals as $v): ?>
            <div class="vertical-card">
                <div class="vertical-header">
                    <div>
                        <h3><?= htmlspecialchars($v['display_name']) ?></h3>
                        <small style="color:#666"><?= htmlspecialchars($v['description'] ?? '') ?></small>
                    </div>
                    <span class="badge <?= $v['settings']['call_routing_mode'] ?? 'voicemail' ?>">
                        <?= ucwords(str_replace('_', ' ', $v['settings']['call_routing_mode'] ?? 'voicemail')) ?>
                    </span>
                </div>

                <div class="two-col">
                    <div>
                        <h3>Call Routing Settings</h3>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_vertical_settings">
                            <input type="hidden" name="vertical_id" value="<?= $v['id'] ?>">

                            <div class="form-group">
                                <label>Call Routing Mode</label>
                                <select name="call_routing_mode">
                                    <option value="voicemail" <?= ($v['settings']['call_routing_mode'] ?? '') === 'voicemail' ? 'selected' : '' ?>>Voicemail Only</option>
                                    <option value="round_robin" <?= ($v['settings']['call_routing_mode'] ?? '') === 'round_robin' ? 'selected' : '' ?>>Round Robin Only</option>
                                    <option value="hybrid" <?= ($v['settings']['call_routing_mode'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Hybrid (Round Robin + Voicemail Fallback)</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Ring Timeout (seconds)</label>
                                    <input type="number" name="ring_timeout" value="<?= $v['settings']['ring_timeout_seconds'] ?? 30 ?>" min="10" max="60">
                                </div>
                                <div class="form-group">
                                    <label>Max Attempts</label>
                                    <input type="number" name="max_attempts" value="<?= $v['settings']['max_ring_attempts'] ?? 3 ?>" min="1" max="10">
                                    <div class="help-text">How many buyers to try before voicemail</div>
                                </div>
                            </div>

                            <div class="checkbox-group">
                                <input type="checkbox" name="voicemail_enabled" id="vm_<?= $v['id'] ?>" <?= ($v['settings']['voicemail_enabled'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label for="vm_<?= $v['id'] ?>">Enable Voicemail Fallback</label>
                            </div>

                            <button type="submit">Save Settings</button>
                        </form>
                    </div>

                    <div>
                        <h3>Round Robin Queue</h3>
                        <div class="queue-list">
                            <?php if (empty($callQueues[$v['id']])): ?>
                                <p style="color:#666; padding: 10px;">No buyers in queue. Add buyers below.</p>
                            <?php else: ?>
                                <?php $pos = 1; foreach ($callQueues[$v['id']] as $q): ?>
                                    <div class="queue-item">
                                        <div style="display:flex; align-items:center; gap:15px;">
                                            <span class="queue-number"><?= $pos++ ?></span>
                                            <div>
                                                <strong><?= htmlspecialchars($q['buyer_name']) ?></strong>
                                                <?php if ($q['phone']): ?>
                                                    <br><small><?= htmlspecialchars($q['phone']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <span class="badge <?= $q['buyer_status'] ?>"><?= ucfirst($q['buyer_status']) ?></span>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:10px;">
                                            <small style="color:#666">Calls: <?= $q['calls_received'] ?> (<?= $q['calls_answered'] ?> answered)</small>
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="remove_from_queue">
                                                <input type="hidden" name="queue_id" value="<?= $q['id'] ?>">
                                                <button type="submit" class="btn-sm btn-danger">Remove</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <form method="POST" style="margin-top: 15px;">
                            <input type="hidden" name="action" value="add_to_queue">
                            <input type="hidden" name="vertical_id" value="<?= $v['id'] ?>">
                            <div class="form-row">
                                <select name="buyer_id" required>
                                    <option value="">Select Buyer...</option>
                                    <?php foreach ($buyers as $b): ?>
                                        <?php if ($b['status'] === 'active' && $b['phone']): ?>
                                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?> - <?= htmlspecialchars($b['phone']) ?></option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="priority" value="0" placeholder="Priority" style="width:80px;">
                                <button type="submit">Add to Queue</button>
                            </div>
                            <div class="help-text">Higher priority = called first. Buyers need a phone number to be in call queue.</div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php elseif ($tab === 'leads'): ?>
        <!-- LEADS TAB -->
        <div class="card">
            <h2>Recent Leads (Last 20)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Buyer</th>
                        <th>Lead Data</th>
                        <th>Source</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLeads as $lead): ?>
                        <?php $data = json_decode($lead['lead_data'] ?? '{}', true); ?>
                        <tr>
                            <td><?= $lead['id'] ?></td>
                            <td>
                                <?php
                                $buyerName = '-';
                                foreach ($buyers as $b) {
                                    if ($b['id'] == $lead['buyer_id']) {
                                        $buyerName = $b['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($buyerName);
                                ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')) ?></strong><br>
                                <small><?= htmlspecialchars($data['phone'] ?? '') ?></small>
                            </td>
                            <td><span class="badge"><?= htmlspecialchars($lead['site_domain'] ?? 'Direct') ?></span></td>
                            <td>$<?= number_format(($lead['price'] ?? 0) / 100, 2) ?></td>
                            <td><span class="badge <?= $lead['status'] ?>"><?= ucfirst($lead['status']) ?></span></td>
                            <td><?= date('M j, g:i a', strtotime($lead['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentLeads)): ?>
                        <tr><td colspan="7" style="text-align:center;color:#666;padding:30px;">No leads yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php elseif ($tab === 'notes'): ?>
        <!-- NOTES TAB -->
        <div class="two-col">
            <div>
                <div class="card">
                    <h2>Add Note</h2>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_note">
                        <div class="form-group">
                            <label>Title</label>
                            <input type="text" name="title" placeholder="Note title (optional)">
                        </div>
                        <div class="form-group">
                            <label>Content</label>
                            <textarea name="content" rows="5" placeholder="Your note..." required></textarea>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Category</label>
                                <select name="category">
                                    <option value="general">General</option>
                                    <option value="todo">To Do</option>
                                    <option value="idea">Idea</option>
                                    <option value="issue">Issue</option>
                                    <option value="reminder">Reminder</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <div class="checkbox-group" style="margin-top: 25px;">
                                    <input type="checkbox" name="is_pinned" id="pin_note">
                                    <label for="pin_note">Pin to top</label>
                                </div>
                            </div>
                        </div>
                        <button type="submit">Save Note</button>
                    </form>
                </div>

                <div class="card">
                    <h2>Quick Reference</h2>
                    <div style="font-size: 14px; line-height: 1.8; color: #555;">
                        <p><strong>Pricing Model:</strong></p>
                        <ul style="margin-left: 20px; margin-bottom: 15px;">
                            <li>$35 per lead (default, configurable per buyer)</li>
                            <li>3 free test leads for new buyers</li>
                            <li>$35 minimum balance (auto-pause when low)</li>
                        </ul>
                        <p><strong>Lead Flow:</strong></p>
                        <ul style="margin-left: 20px; margin-bottom: 15px;">
                            <li>Form submit → ezlead4u.com HQ → Distribution</li>
                            <li>Phone call → Voicemail/Round Robin → Lead created</li>
                        </ul>
                        <p><strong>Sites:</strong></p>
                        <ul style="margin-left: 20px;">
                            <li>sodjacksonvillefl.com (sod, has phone)</li>
                            <li>sodjax.com (sod, forms only)</li>
                            <li>jacksonvillesod.com (sod, forms only)</li>
                            <li>drainagejax.com (drainage, forms only)</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div>
                <div class="card">
                    <h2>Notes</h2>
                    <?php if (empty($notes)): ?>
                        <p style="color:#666;">No notes yet. Add one using the form.</p>
                    <?php else: ?>
                        <?php foreach ($notes as $note): ?>
                            <div class="note-card <?= $note['is_pinned'] ? 'pinned' : '' ?>">
                                <div class="note-header">
                                    <div>
                                        <?php if ($note['title']): ?>
                                            <div class="note-title"><?= htmlspecialchars($note['title']) ?></div>
                                        <?php endif; ?>
                                        <div class="note-meta">
                                            <span class="badge"><?= ucfirst($note['category']) ?></span>
                                            <?= date('M j, Y g:i a', strtotime($note['updated_at'])) ?>
                                            <?php if ($note['is_pinned']): ?> - Pinned<?php endif; ?>
                                        </div>
                                    </div>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_note">
                                        <input type="hidden" name="note_id" value="<?= $note['id'] ?>">
                                        <button type="submit" class="btn-sm btn-danger" onclick="return confirm('Delete this note?')">Delete</button>
                                    </form>
                                </div>
                                <div class="note-content"><?= nl2br(htmlspecialchars($note['content'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif ($tab === 'system'): ?>
        <!-- SYSTEM TAB -->
        <div class="stats-grid">
            <div class="stat-card <?= ($systemStats['api_status']['stat_value'] ?? '') === 'up' ? 'green' : '' ?>">
                <h3>API Status</h3>
                <div class="value"><?= strtoupper($systemStats['api_status']['stat_value'] ?? 'Unknown') ?></div>
            </div>
            <div class="stat-card">
                <h3>Pending Deliveries</h3>
                <div class="value"><?= $systemStats['pending_deliveries']['stat_value'] ?? '0' ?></div>
            </div>
            <div class="stat-card">
                <h3>Failed Deliveries</h3>
                <div class="value"><?= $systemStats['failed_deliveries']['stat_value'] ?? '0' ?></div>
            </div>
            <div class="stat-card">
                <h3>Disk Usage</h3>
                <div class="value"><?= $systemStats['disk_usage']['stat_value'] ?? '?' ?>%</div>
            </div>
        </div>

        <div class="two-col">
            <div>
                <div class="card">
                    <h2>Service Status</h2>
                    <table>
                        <thead>
                            <tr><th>Service</th><th>Status</th><th>Last Check</th></tr>
                        </thead>
                        <tbody>
                            <?php
                            $services = ['ezlead4u', 'ezlead4u-worker', 'redis-server', 'postgresql', 'caddy', 'php8.3-fpm'];
                            foreach ($services as $svc):
                                $key = "service_$svc";
                                $status = $systemStats[$key]['stat_value'] ?? 'unknown';
                                $lastCheck = $systemStats[$key]['recorded_at'] ?? '-';
                            ?>
                            <tr>
                                <td><strong><?= $svc ?></strong></td>
                                <td>
                                    <span class="badge <?= $status === 'up' ? 'active' : 'suspended' ?>">
                                        <?= strtoupper($status) ?>
                                    </span>
                                </td>
                                <td><?= $lastCheck !== '-' ? date('M j, g:i a', strtotime($lastCheck)) : '-' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div style="margin-top: 15px; font-size: 12px; color: #666;">
                        Monitor runs every 5 minutes via cron.
                        <br>Last stats: <?= $systemStats['api_status']['recorded_at'] ?? 'Never' ?>
                    </div>
                </div>

                <div class="card">
                    <h2>Run Monitor Now</h2>
                    <p style="color: #666; margin-bottom: 15px;">Click to manually check system health:</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="run_monitor">
                        <button type="submit">Run System Check</button>
                    </form>
                </div>
            </div>

            <div>
                <div class="card" style="<?= count($alerts) > 0 ? 'border: 2px solid #dc3545;' : '' ?>">
                    <h2 style="color: <?= count($alerts) > 0 ? '#dc3545' : '#333' ?>">
                        Active Alerts <?= count($alerts) > 0 ? '(' . count($alerts) . ')' : '' ?>
                    </h2>
                    <?php if (empty($alerts)): ?>
                        <p style="color: #27ae60; font-weight: 500;">All systems operational.</p>
                    <?php else: ?>
                        <?php foreach ($alerts as $alert): ?>
                            <div style="background: <?= $alert['severity'] === 'critical' ? '#f8d7da' : '#fff3cd' ?>; padding: 15px; border-radius: 8px; margin-bottom: 10px;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                    <div>
                                        <span class="badge <?= $alert['severity'] === 'critical' ? 'suspended' : 'paused' ?>">
                                            <?= strtoupper($alert['severity']) ?>
                                        </span>
                                        <strong style="margin-left: 10px;"><?= htmlspecialchars($alert['message']) ?></strong>
                                        <?php if ($alert['details']): ?>
                                            <div style="font-size: 13px; color: #666; margin-top: 5px;">
                                                <?= htmlspecialchars($alert['details']) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 12px; color: #888; margin-top: 5px;">
                                            <?= date('M j, g:i a', strtotime($alert['created_at'])) ?>
                                        </div>
                                    </div>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="resolve_alert">
                                        <input type="hidden" name="alert_id" value="<?= $alert['id'] ?>">
                                        <button type="submit" class="btn-sm btn-secondary">Resolve</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <h2>Recently Resolved</h2>
                    <?php if (empty($resolvedAlerts)): ?>
                        <p style="color: #666;">No resolved alerts yet.</p>
                    <?php else: ?>
                        <table>
                            <thead><tr><th>Alert</th><th>Resolved</th></tr></thead>
                            <tbody>
                                <?php foreach ($resolvedAlerts as $alert): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($alert['message']) ?></td>
                                        <td><?= date('M j, g:i a', strtotime($alert['resolved_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
<!-- CRM Analytics -->
<script>(function(){navigator.sendBeacon("https://ezlead4u.com/crm/plugins/claude/track.php",JSON.stringify({url:location.href,ref:document.referrer}));})()</script>
</body>
</html>
