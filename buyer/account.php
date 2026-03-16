<?php
/**
 * Buyer Portal - Account Settings
 * Update profile, zip codes, notification prefs, change password
 */
require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();
$buyer = $auth->requireAuth();
$db = $auth->getDb();

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $name = trim($_POST['name'] ?? '');
        $company = trim($_POST['company'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $zipCodes = trim($_POST['zip_codes'] ?? '');

        if (!$name) {
            $message = 'Name is required.';
            $messageType = 'error';
        } else {
            $cleanZips = '';
            if ($zipCodes) {
                $cleanZips = implode(', ', array_filter(array_map('trim', explode(',', preg_replace('/[^0-9,\s]/', '', $zipCodes)))));
            }

            $stmt = $db->prepare("UPDATE buyers SET name = :name, company = :company, phone = :phone, zip_codes = :zips, updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':company', $company);
            $stmt->bindValue(':phone', preg_replace('/[^0-9]/', '', $phone));
            $stmt->bindValue(':zips', $cleanZips);
            $stmt->bindValue(':id', $buyer['id']);
            $stmt->execute();
            $message = 'Profile updated.';

            // Refresh buyer data
            $buyer = $auth->getBuyer($buyer['id']);
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        // Verify current password
        $stmt = $db->prepare("SELECT password_hash FROM buyers WHERE id = :id");
        $stmt->bindValue(':id', $buyer['id']);
        $hash = $stmt->execute()->fetchArray()['password_hash'] ?? '';

        if (!password_verify($current, $hash)) {
            $message = 'Current password is incorrect.';
            $messageType = 'error';
        } elseif (strlen($new) < 8) {
            $message = 'New password must be at least 8 characters.';
            $messageType = 'error';
        } elseif ($new !== $confirm) {
            $message = 'New passwords do not match.';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare("UPDATE buyers SET password_hash = :hash, updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':hash', password_hash($new, PASSWORD_DEFAULT));
            $stmt->bindValue(':id', $buyer['id']);
            $stmt->execute();
            $message = 'Password changed.';
        }
    }

    if ($action === 'toggle_pause') {
        $newStatus = ($buyer['status'] === 'active') ? 'paused' : 'active';
        $stmt = $db->prepare("UPDATE buyers SET status = :status, updated_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':status', $newStatus);
        $stmt->bindValue(':id', $buyer['id']);
        $stmt->execute();
        $message = $newStatus === 'paused' ? 'Lead delivery paused.' : 'Lead delivery resumed.';
        $buyer['status'] = $newStatus;
    }
}

// Get campaigns
$stmt = $db->prepare("SELECT * FROM buyer_campaigns WHERE buyer_id = :id ORDER BY created_at DESC");
$stmt->bindValue(':id', $buyer['id']);
$result = $stmt->execute();
$campaigns = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $campaigns[] = $row;
}

$pageTitle = 'Account';
$activePage = 'account';
require_once __DIR__ . '/includes/portal_header.php';
?>

    <div class="portal-content">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- Lead Delivery Status -->
        <div class="card" style="margin-bottom: 20px;">
            <div class="card-header">
                <h2>Lead Delivery</h2>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="toggle_pause">
                    <?php if ($buyer['status'] === 'active'): ?>
                        <button type="submit" class="btn btn-outline btn-sm" onclick="return confirm('Pause lead delivery?')">Pause Leads</button>
                    <?php else: ?>
                        <button type="submit" class="btn btn-green btn-sm">Resume Leads</button>
                    <?php endif; ?>
                </form>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span style="display: inline-block; width: 10px; height: 10px; border-radius: 50%; background: <?= $buyer['status'] === 'active' ? 'var(--green)' : 'var(--orange)' ?>;"></span>
                <span style="font-size: 15px; font-weight: 500;">
                    <?= $buyer['status'] === 'active' ? 'Active — receiving leads' : 'Paused — not receiving leads' ?>
                </span>
            </div>
            <?php if ($buyer['status'] === 'paused'): ?>
                <p style="font-size: 13px; color: var(--gray-400); margin-top: 8px;">Hit "Resume Leads" when you're ready to start receiving leads again.</p>
            <?php endif; ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- Profile -->
            <div class="card">
                <div class="card-header"><h2>Profile</h2></div>
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Name</label>
                        <input type="text" name="name" required value="<?= htmlspecialchars($buyer['name'] ?? '') ?>"
                               style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Company</label>
                        <input type="text" name="company" value="<?= htmlspecialchars($buyer['company'] ?? '') ?>"
                               style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Phone</label>
                        <input type="tel" name="phone" value="<?= htmlspecialchars($buyer['phone'] ?? '') ?>"
                               style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                    </div>
                    <div style="margin-bottom: 14px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Email</label>
                        <input type="email" disabled value="<?= htmlspecialchars($buyer['email'] ?? '') ?>"
                               style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px; background: var(--gray-50); color: var(--gray-400);">
                        <span style="font-size: 12px; color: var(--gray-400);">Contact us to change your email.</span>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Zip Codes</label>
                        <input type="text" name="zip_codes" placeholder="32080, 32084, 32092" value="<?= htmlspecialchars($buyer['zip_codes'] ?? '') ?>"
                               style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                        <span style="font-size: 12px; color: var(--gray-400);">Comma-separated. Leave blank for all areas.</span>
                    </div>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>

            <!-- Password -->
            <div>
                <div class="card" style="margin-bottom: 20px;">
                    <div class="card-header"><h2>Change Password</h2></div>
                    <form method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Current Password</label>
                            <input type="password" name="current_password" required
                                   style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                        </div>
                        <div style="margin-bottom: 14px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">New Password</label>
                            <input type="password" name="new_password" required minlength="8"
                                   style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                        </div>
                        <div style="margin-bottom: 16px;">
                            <label style="display: block; font-size: 13px; font-weight: 600; color: var(--gray-600); margin-bottom: 4px;">Confirm Password</label>
                            <input type="password" name="confirm_password" required minlength="8"
                                   style="width: 100%; padding: 10px; border: 1px solid var(--gray-200); border-radius: 6px; font-size: 14px;">
                        </div>
                        <button type="submit" class="btn btn-primary">Update Password</button>
                    </form>
                </div>

                <!-- Account Info -->
                <div class="card">
                    <div class="card-header"><h2>Account Details</h2></div>
                    <div style="font-size: 14px; color: var(--gray-600); line-height: 1.8;">
                        <div><strong>Member since:</strong> <?= date('M j, Y', strtotime($buyer['created_at'] ?? 'now')) ?></div>
                        <div><strong>Price per lead:</strong> $<?= number_format(($buyer['price_per_lead'] ?? 3500) / 100, 2) ?></div>
                        <div><strong>Status:</strong> <?= ucfirst($buyer['status'] ?? 'active') ?></div>
                        <?php if (($buyer['free_leads_remaining'] ?? 0) > 0): ?>
                        <div><strong>Free leads remaining:</strong> <?= $buyer['free_leads_remaining'] ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Campaigns -->
        <?php if (!empty($campaigns)): ?>
        <div class="card" style="margin-top: 20px;">
            <div class="card-header"><h2>Campaigns</h2></div>
            <table class="data-table">
                <thead>
                    <tr><th>Name</th><th>Source</th><th>Delivery</th><th>Daily Cap</th><th>Weekly Cap</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $c): ?>
                    <tr>
                        <td style="font-weight: 500;"><?= htmlspecialchars($c['name']) ?></td>
                        <td><span class="lead-source"><?= htmlspecialchars($c['site_domain'] ?: 'All') ?></span></td>
                        <td><?= ucfirst($c['delivery_method'] ?? 'portal') ?></td>
                        <td><?= $c['max_per_day'] ?: 'No limit' ?></td>
                        <td><?= $c['max_per_week'] ?: 'No limit' ?></td>
                        <td>
                            <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?= $c['status'] === 'active' ? 'var(--green)' : 'var(--orange)' ?>; margin-right: 4px;"></span>
                            <?= ucfirst($c['status']) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/includes/portal_footer.php'; ?>
