<?php
/**
 * Buyer Portal - Billing & Add Funds
 * Stripe Checkout for deposits, transaction history
 */
require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();
$buyer = $auth->requireAuth();

// Stripe config
$stripeSecretKey = getenv('STRIPE_SECRET_KEY') ?: '';
$stripePublicKey = getenv('STRIPE_PUBLIC_KEY') ?: '';
$configured = !empty($stripeSecretKey) && !empty($stripePublicKey);

$error = '';

// Handle Stripe Checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configured) {
    $amount = (int)($_POST['amount'] ?? 0);
    if ($amount < 35 || $amount > 5000) {
        $error = 'Amount must be between $35 and $5,000.';
    } else {
        $amountCents = $amount * 100;

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $stripeSecretKey . ':',
            CURLOPT_POSTFIELDS => http_build_query([
                'payment_method_types[]' => 'card',
                'line_items[0][price_data][currency]' => 'usd',
                'line_items[0][price_data][product_data][name]' => 'Lead Account Deposit',
                'line_items[0][price_data][unit_amount]' => $amountCents,
                'line_items[0][quantity]' => 1,
                'mode' => 'payment',
                'success_url' => 'https://ezlead4u.com/buyer/fund.php?success=1&amount=' . $amount,
                'cancel_url' => 'https://ezlead4u.com/buyer/fund.php?cancelled=1',
                'client_reference_id' => $buyer['id'],
                'customer_email' => $buyer['email'],
                'metadata[buyer_id]' => $buyer['id'],
                'metadata[type]' => 'deposit',
            ]),
        ]);

        $response = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($response['url'])) {
            header('Location: ' . $response['url']);
            exit;
        } else {
            $error = 'Payment setup failed. Please try again.';
            error_log('Stripe error: ' . json_encode($response));
        }
    }
}

$showSuccess = isset($_GET['success']);
$balance = $buyer['balance'] ?? 0;
$pricePerLead = ($buyer['price_per_lead'] ?? 3500) / 100;

// Transaction history
$db = $auth->getDb();
$stmt = $db->prepare("SELECT * FROM buyer_transactions WHERE buyer_id = :id ORDER BY created_at DESC LIMIT 30");
$stmt->bindValue(':id', $buyer['id']);
$result = $stmt->execute();
$transactions = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $transactions[] = $row;
}

$pageTitle = 'Billing';
$activePage = 'billing';
require_once __DIR__ . '/includes/portal_header.php';
?>

    <div class="portal-content">
        <!-- Balance Overview -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
            <div class="card" style="text-align: center;">
                <div class="stat-label" style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-400); margin-bottom: 8px;">Current Balance</div>
                <div style="font-size: 36px; font-weight: 700; color: var(--green);">$<?= number_format($balance / 100, 2) ?></div>
                <div style="font-size: 13px; color: var(--gray-400); margin-top: 4px;">~<?= floor(($balance / 100) / $pricePerLead) ?> leads remaining</div>
            </div>
            <div class="card" style="text-align: center;">
                <div class="stat-label" style="font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--gray-400); margin-bottom: 8px;">Price Per Lead</div>
                <div style="font-size: 36px; font-weight: 700; color: var(--gray-800);">$<?= number_format($pricePerLead, 2) ?></div>
                <div style="font-size: 13px; color: var(--gray-400); margin-top: 4px;">Auto-pause below this amount</div>
            </div>
        </div>

        <?php if ($showSuccess): ?>
            <div class="alert alert-success">Payment received. Your balance will update shortly.</div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Add Funds -->
        <div class="card">
            <div class="card-header"><h2>Add Funds</h2></div>

            <?php if (!$configured): ?>
                <div class="alert alert-warning">
                    Online payments are being set up. Contact us to add funds: <strong>kyle@ezlead4u.com</strong>
                </div>
            <?php else: ?>
                <form method="POST" id="fundForm">
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px;">
                        <button type="button" class="btn btn-outline amount-btn" data-amount="100" onclick="selectAmount(100)">$100</button>
                        <button type="button" class="btn btn-outline amount-btn" data-amount="250" onclick="selectAmount(250)">$250</button>
                        <button type="button" class="btn btn-outline amount-btn" data-amount="500" onclick="selectAmount(500)">$500</button>
                        <button type="button" class="btn btn-outline amount-btn" data-amount="1000" onclick="selectAmount(1000)">$1,000</button>
                    </div>
                    <div style="margin-bottom: 16px;">
                        <input type="number" name="amount" id="amountInput" min="35" max="5000" placeholder="Or enter custom amount ($35 min)"
                               style="width: 100%; padding: 12px; border: 2px solid var(--gray-200); border-radius: 8px; font-size: 16px;">
                    </div>
                    <button type="submit" class="btn btn-green" style="width: 100%; padding: 14px; font-size: 16px; justify-content: center;">Add Funds</button>
                </form>
                <script>
                function selectAmount(amt) {
                    document.getElementById('amountInput').value = amt;
                    document.querySelectorAll('.amount-btn').forEach(function(b) {
                        b.style.borderColor = b.dataset.amount == amt ? 'var(--navy)' : 'var(--gray-200)';
                        b.style.background = b.dataset.amount == amt ? 'var(--gray-50)' : 'white';
                        b.style.color = b.dataset.amount == amt ? 'var(--navy)' : 'var(--gray-600)';
                    });
                }
                </script>
            <?php endif; ?>
        </div>

        <!-- Transaction History -->
        <?php if (!empty($transactions)): ?>
        <div class="card">
            <div class="card-header"><h2>Transaction History</h2></div>
            <table class="data-table">
                <thead>
                    <tr><th>Date</th><th>Type</th><th>Description</th><th>Amount</th><th>Balance</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td><?= date('M j, g:i a', strtotime($tx['created_at'])) ?></td>
                        <td><?= ucfirst($tx['type']) ?></td>
                        <td><?= htmlspecialchars($tx['description']) ?></td>
                        <td class="<?= $tx['amount'] >= 0 ? 'amount-positive' : 'amount-negative' ?>">
                            <?= $tx['amount'] >= 0 ? '+' : '' ?>$<?= number_format(abs($tx['amount']) / 100, 2) ?>
                        </td>
                        <td>$<?= number_format($tx['balance_after'] / 100, 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

<?php require_once __DIR__ . '/includes/portal_footer.php'; ?>
