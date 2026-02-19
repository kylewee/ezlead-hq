<?php
/**
 * Add Credit to Buyer Account
 *
 * Called from Stripe webhook or manual admin action
 *
 * Usage:
 *   POST /engine/add_credit.php
 *   {"buyer_id": 1, "amount": 100.00, "notes": "Stripe payment xyz"}
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Get input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$buyerId = intval($input['buyer_id'] ?? 0);
$amount = floatval($input['amount'] ?? 0);
$notes = $input['notes'] ?? 'Credit added';

if ($buyerId <= 0 || $amount <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid buyer_id or amount']);
    exit;
}

try {
    $db = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Get current balance
    $stmt = $db->prepare("SELECT value FROM app_entity_26_values WHERE items_id = ? AND fields_id = ?");
    $stmt->execute([$buyerId, FIELD_BUYER_BALANCE]);
    $currentBalance = floatval($stmt->fetchColumn() ?: 0);

    $newBalance = $currentBalance + $amount;

    // Update balance
    $stmt = $db->prepare("SELECT id FROM app_entity_26_values WHERE items_id = ? AND fields_id = ?");
    $stmt->execute([$buyerId, FIELD_BUYER_BALANCE]);
    $existing = $stmt->fetch();

    if ($existing) {
        $stmt = $db->prepare("UPDATE app_entity_26_values SET value = ? WHERE items_id = ? AND fields_id = ?");
        $stmt->execute([number_format($newBalance, 2, '.', ''), $buyerId, FIELD_BUYER_BALANCE]);
    } else {
        $stmt = $db->prepare("INSERT INTO app_entity_26_values (items_id, fields_id, value) VALUES (?, ?, ?)");
        $stmt->execute([$buyerId, FIELD_BUYER_BALANCE, number_format($newBalance, 2, '.', '')]);
    }

    // Create transaction
    $stmt = $db->prepare("INSERT INTO app_entity_27 (date_added, created_by, parent_item_id) VALUES (NOW(), 1, ?)");
    $stmt->execute([$buyerId]);
    $txnId = $db->lastInsertId();

    // Add transaction values
    $fields = [
        FIELD_TXN_TYPE => 'credit',
        FIELD_TXN_AMOUNT => number_format($amount, 2, '.', ''),
        FIELD_TXN_LEAD_ID => '',
        FIELD_TXN_NOTES => $notes
    ];

    foreach ($fields as $fieldId => $value) {
        $stmt = $db->prepare("INSERT INTO app_entity_27_values (items_id, fields_id, value) VALUES (?, ?, ?)");
        $stmt->execute([$txnId, $fieldId, $value]);
    }

    // If balance was below minimum and now above, reactivate
    if ($currentBalance < MIN_BALANCE && $newBalance >= MIN_BALANCE) {
        $stmt = $db->prepare("SELECT id FROM app_entity_26_values WHERE items_id = ? AND fields_id = ?");
        $stmt->execute([$buyerId, FIELD_BUYER_STATUS]);
        $existing = $stmt->fetch();

        if ($existing) {
            $stmt = $db->prepare("UPDATE app_entity_26_values SET value = 'active' WHERE items_id = ? AND fields_id = ?");
            $stmt->execute([$buyerId, FIELD_BUYER_STATUS]);
        }
    }

    // Log
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(__DIR__ . '/distribution.log',
        "[$timestamp] Credit added: Buyer #$buyerId +\$$amount (new balance: \$$newBalance)\n",
        FILE_APPEND);

    echo json_encode([
        'success' => true,
        'buyer_id' => $buyerId,
        'amount_added' => $amount,
        'new_balance' => $newBalance,
        'transaction_id' => $txnId
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
