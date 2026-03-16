<?php
/**
 * Stripe Webhook Handler
 * Receives payment confirmations and credits buyer balances
 *
 * Configure in Stripe Dashboard -> Webhooks:
 *   URL: https://ezlead4u.com/buyer/webhook_stripe.php
 *   Events: checkout.session.completed
 */

$stripeWebhookSecret = getenv('STRIPE_WEBHOOK_SECRET') ?: '';
$payload = file_get_contents('php://input');

// Verify webhook signature if secret is configured
if ($stripeWebhookSecret) {
    $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    $elements = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$key, $value] = explode('=', $part, 2);
        $elements[trim($key)] = trim($value);
    }
    $timestamp = $elements['t'] ?? '';
    $signature = $elements['v1'] ?? '';
    $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $stripeWebhookSecret);

    if (!hash_equals($expected, $signature)) {
        http_response_code(400);
        exit('Invalid signature');
    }
}

$event = json_decode($payload, true);
if (!$event || ($event['type'] ?? '') !== 'checkout.session.completed') {
    http_response_code(200);
    exit('Ignored');
}

$session = $event['data']['object'] ?? [];
$buyerId = (int)($session['metadata']['buyer_id'] ?? 0);
$amountCents = (int)($session['amount_total'] ?? 0);
$paymentId = $session['payment_intent'] ?? $session['id'] ?? '';

if (!$buyerId || !$amountCents) {
    http_response_code(400);
    exit('Missing buyer_id or amount');
}

require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();

// Credit buyer balance
$auth->updateBalance($buyerId, $amountCents, 'deposit', 'Stripe payment', null, $paymentId);

// Reactivate if paused
$db = $auth->getDb();
$stmt = $db->prepare("UPDATE buyers SET status = 'active', updated_at = datetime('now') WHERE id = :id AND status = 'paused'");
$stmt->bindValue(':id', $buyerId);
$stmt->execute();

error_log("Stripe: Credited buyer #{$buyerId} with $" . number_format($amountCents / 100, 2) . " (payment: {$paymentId})");

http_response_code(200);
echo 'OK';
