<?php
/**
 * Buyer Portal - Dashboard
 * Lead cards, stats, balance — the living room.
 */
require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();
$buyer = $auth->requireAuth();
$db = $auth->getDb();

$buyerId = $buyer['id'];
$balance = $buyer['balance'] ?? 0;
$freeLeads = $buyer['free_leads_remaining'] ?? 0;
$pricePerLead = ($buyer['price_per_lead'] ?? 3500) / 100;

// Stats
$stmt = $db->prepare("SELECT COUNT(*) FROM buyer_leads WHERE buyer_id = :id");
$stmt->bindValue(':id', $buyerId);
$totalLeads = $stmt->execute()->fetchArray()[0];

$stmt = $db->prepare("SELECT COUNT(*) FROM buyer_leads WHERE buyer_id = :id AND created_at >= datetime('now', '-7 days')");
$stmt->bindValue(':id', $buyerId);
$weekLeads = $stmt->execute()->fetchArray()[0];

$stmt = $db->prepare("SELECT COUNT(*) FROM buyer_leads WHERE buyer_id = :id AND date(created_at) = date('now')");
$stmt->bindValue(':id', $buyerId);
$todayLeads = $stmt->execute()->fetchArray()[0];

// Total spent
$stmt = $db->prepare("SELECT COALESCE(SUM(ABS(amount)), 0) FROM buyer_transactions WHERE buyer_id = :id AND type = 'charge'");
$stmt->bindValue(':id', $buyerId);
$totalSpent = $stmt->execute()->fetchArray()[0];

// Recent leads (last 50)
$stmt = $db->prepare("
    SELECT id, lead_data, site_domain, price, status, created_at
    FROM buyer_leads
    WHERE buyer_id = :id
    ORDER BY created_at DESC
    LIMIT 50
");
$stmt->bindValue(':id', $buyerId);
$result = $stmt->execute();
$leads = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data = json_decode($row['lead_data'], true) ?? [];
    $row['parsed'] = $data;
    // Mark leads from today as "new"
    $row['is_new'] = (date('Y-m-d', strtotime($row['created_at'])) === date('Y-m-d'));
    $leads[] = $row;
}

// Low balance warning
$lowBalance = ($balance / 100) < $pricePerLead && $freeLeads <= 0;

$pageTitle = 'Dashboard';
$activePage = 'dashboard';
require_once __DIR__ . '/includes/portal_header.php';
?>

    <?php if ($lowBalance): ?>
    <div style="padding: 0 24px; max-width: 1200px; margin: 16px auto 0;">
        <div class="alert alert-warning">
            Your balance is below $<?= number_format($pricePerLead, 2) ?> — new leads are paused until you add funds.
            <a href="/buyer/fund.php" class="btn btn-sm btn-green" style="margin-left: 12px;">Add Funds</a>
        </div>
    </div>
    <?php endif; ?>

    <div class="portal-content">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card stat-balance">
                <div class="stat-label">Balance</div>
                <div class="stat-value">$<?= number_format($balance / 100, 2) ?></div>
            </div>
            <?php if ($freeLeads > 0): ?>
            <div class="stat-card stat-free">
                <div class="stat-label">Free Leads Left</div>
                <div class="stat-value"><?= $freeLeads ?></div>
            </div>
            <?php endif; ?>
            <div class="stat-card stat-today">
                <div class="stat-label">Today</div>
                <div class="stat-value"><?= $todayLeads ?></div>
            </div>
            <div class="stat-card stat-week">
                <div class="stat-label">This Week</div>
                <div class="stat-value"><?= $weekLeads ?></div>
            </div>
            <div class="stat-card stat-total">
                <div class="stat-label">All Time</div>
                <div class="stat-value"><?= $totalLeads ?></div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div style="margin-bottom: 24px;">
            <div class="quick-actions">
                <a href="/buyer/fund.php" class="btn btn-green btn-sm">Add Funds</a>
                <?php if ($totalLeads > 0): ?>
                <a href="/buyer/export.php" class="btn btn-outline btn-sm">Export CSV</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leads -->
        <div class="section-header">
            <h2>Your Leads</h2>
            <?php if ($totalLeads > 0): ?>
            <span style="font-size: 13px; color: var(--gray-400);">Showing latest <?= min(50, $totalLeads) ?> of <?= $totalLeads ?></span>
            <?php endif; ?>
        </div>

        <?php if (empty($leads)): ?>
            <div class="card">
                <div class="empty-state">
                    <h3>No leads yet</h3>
                    <p>Leads will show up here as they come in.<?php if ($freeLeads > 0): ?> You have <?= $freeLeads ?> free test leads waiting.<?php endif; ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="leads-grid">
                <?php foreach ($leads as $lead):
                    $d = $lead['parsed'];
                    $name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
                    if (!$name || $name === ' ') $name = $d['name'] ?? 'Unknown';
                    $phone = $d['phone'] ?? '';
                    $email = $d['email'] ?? '';
                    $address = $d['address'] ?? $d['city'] ?? '';
                    $zip = $d['zip'] ?? '';
                    $notes = $d['notes'] ?? $d['service'] ?? $d['problem'] ?? '';
                    $vehicle = '';
                    if (!empty($d['year']) || !empty($d['make']) || !empty($d['model'])) {
                        $vehicle = trim(($d['year'] ?? '') . ' ' . ($d['make'] ?? '') . ' ' . ($d['model'] ?? ''));
                    }

                    $statusClass = 'badge-delivered';
                    if ($lead['is_new']) $statusClass = 'badge-new';
                    if ($lead['status'] === 'returned') $statusClass = 'badge-returned';

                    $cardClass = 'lead-card';
                    if ($lead['is_new']) $cardClass .= ' lead-new';
                    if ($lead['status'] === 'returned') $cardClass .= ' lead-returned';

                    $statusLabel = $lead['is_new'] ? 'New' : ucfirst($lead['status']);
                ?>
                <div class="<?= $cardClass ?>">
                    <div class="lead-card-top">
                        <div class="lead-name"><?= htmlspecialchars($name) ?></div>
                        <span class="lead-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                    </div>
                    <div class="lead-details">
                        <?php if ($phone): ?>
                        <div class="lead-detail">
                            <span class="icon">T</span>
                            <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= htmlspecialchars($phone) ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if ($email): ?>
                        <div class="lead-detail">
                            <span class="icon">@</span>
                            <a href="mailto:<?= htmlspecialchars($email) ?>"><?= htmlspecialchars($email) ?></a>
                        </div>
                        <?php endif; ?>
                        <?php if ($address || $zip): ?>
                        <div class="lead-detail">
                            <span class="icon">L</span>
                            <span><?= htmlspecialchars(trim("$address $zip")) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($vehicle): ?>
                        <div class="lead-detail">
                            <span class="icon">V</span>
                            <span><?= htmlspecialchars($vehicle) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($notes): ?>
                        <div class="lead-detail">
                            <span class="icon">N</span>
                            <span style="color: var(--gray-600);"><?= htmlspecialchars(mb_strimwidth($notes, 0, 80, '...')) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="lead-footer">
                        <span>
                            <span class="lead-source"><?= htmlspecialchars($lead['site_domain'] ?? '') ?></span>
                            <span class="lead-price" style="margin-left: 8px;">$<?= number_format($lead['price'] / 100, 2) ?></span>
                        </span>
                        <span><?= date('M j, g:i a', strtotime($lead['created_at'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/includes/portal_footer.php'; ?>
