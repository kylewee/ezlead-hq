<?php
/**
 * Credits Wallet API
 *
 * Endpoints (all POST, JSON response):
 *   action=balance      &phone=...               - Get balance
 *   action=add          &phone=...&amount_cents=...&payment_method=...&payment_ref=...  - Add credits
 *   action=deduct       &phone=...&minutes=...    - Deduct for call ($1/min)
 *   action=history      &phone=...               - Transaction history
 *   action=confirm_paypal &phone=...&amount=...&order_id=...  - PayPal confirmation
 *
 * Auth: API key in X-Api-Key header or api_key param (same as CRM REST API key)
 */

// Bootstrap CRM for DB access
chdir(dirname(dirname(__DIR__)));
define('IS_CRON', true);
require('includes/application_core.php');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: X-Api-Key, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Auth check - require API key for add/deduct/confirm, allow balance/history without
$api_key = $_SERVER['HTTP_X_API_KEY'] ?? $_REQUEST['api_key'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

$write_actions = ['add', 'deduct', 'confirm_paypal', 'refund'];
if (in_array($action, $write_actions)) {
    // Verify API key matches CRM REST API
    $key_check = db_query("SELECT id FROM app_configuration WHERE configuration_name='CFG_API_KEY' AND configuration_value='" . db_input($api_key) . "'");
    if (!db_num_rows($key_check)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key']);
        exit;
    }
}

// Entity IDs
define('ENTITY_ACCOUNTS', 54);
define('ENTITY_TRANSACTIONS', 55);

// Field IDs - Credit Accounts
define('F_PHONE', 532);
define('F_NAME', 533);
define('F_EMAIL', 534);
define('F_BALANCE', 535);
define('F_CUSTOMER', 536);
define('F_BUSINESS', 537);

// Field IDs - Credit Transactions
define('F_TYPE', 538);        // 222=Purchase, 223=Deduct, 224=Refund
define('F_AMOUNT', 539);
define('F_BALANCE_AFTER', 540);
define('F_DESCRIPTION', 541);
define('F_PAY_METHOD', 542);
define('F_PAY_REF', 543);
define('F_DATE', 544);

// Transaction type choice IDs
define('TYPE_PURCHASE', 222);
define('TYPE_DEDUCT', 223);
define('TYPE_REFUND', 224);

try {
    switch ($action) {
        case 'balance':
            echo json_encode(getBalance());
            break;
        case 'add':
            echo json_encode(addCredits());
            break;
        case 'deduct':
            echo json_encode(deductCredits());
            break;
        case 'history':
            echo json_encode(getHistory());
            break;
        case 'confirm_paypal':
            echo json_encode(confirmPaypal());
            break;
        case 'refund':
            echo json_encode(refundCredits());
            break;
        default:
            echo json_encode(['error' => 'Unknown action. Use: balance, add, deduct, history, confirm_paypal, refund']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function normalizePhone($phone) {
    return preg_replace('/[^0-9]/', '', $phone);
}

function getOrCreateAccount($phone, $name = '', $email = '') {
    $phone = normalizePhone($phone);
    if (!$phone) return null;

    $q = db_query("SELECT * FROM app_entity_" . ENTITY_ACCOUNTS . " WHERE field_" . F_PHONE . "='" . db_input($phone) . "' LIMIT 1");
    if ($row = db_fetch_array($q)) {
        return $row;
    }

    // Create new account
    $sql_data = [
        'field_' . F_PHONE => $phone,
        'field_' . F_NAME => $name,
        'field_' . F_EMAIL => $email,
        'field_' . F_BALANCE => 0,
        'field_' . F_CUSTOMER => '',
        'field_' . F_BUSINESS => '',
    ];
    db_perform('app_entity_' . ENTITY_ACCOUNTS, $sql_data);
    $id = db_insert_id();

    return array_merge(['id' => $id], $sql_data);
}

function recordTransaction($account_id, $type_choice, $amount_cents, $balance_after, $desc = '', $method = '', $ref = '') {
    $sql_data = [
        'parent_id' => ENTITY_ACCOUNTS,
        'parent_item_id' => (int)$account_id,
        'field_' . F_TYPE => $type_choice,
        'field_' . F_AMOUNT => $amount_cents,
        'field_' . F_BALANCE_AFTER => $balance_after,
        'field_' . F_DESCRIPTION => $desc,
        'field_' . F_PAY_METHOD => $method,
        'field_' . F_PAY_REF => $ref,
        'field_' . F_DATE => time(),
    ];
    db_perform('app_entity_' . ENTITY_TRANSACTIONS, $sql_data);
    return db_insert_id();
}

function formatResponse($account) {
    $balance = (int)($account['field_' . F_BALANCE] ?? 0);
    return [
        'success' => true,
        'account_id' => (int)$account['id'],
        'phone' => $account['field_' . F_PHONE] ?? '',
        'balance_cents' => $balance,
        'balance_dollars' => number_format($balance / 100, 2),
        'minutes_available' => floor($balance / 100),
    ];
}

function getBalance() {
    $phone = $_POST['phone'] ?? $_GET['phone'] ?? '';
    if (!$phone) return ['error' => 'Phone required'];

    $account = getOrCreateAccount($phone);
    if (!$account) return ['error' => 'Invalid phone'];

    return formatResponse($account);
}

function addCredits() {
    $phone = $_POST['phone'] ?? '';
    $amount_cents = (int)($_POST['amount_cents'] ?? 0);
    $name = $_POST['name'] ?? '';
    $email = $_POST['email'] ?? '';
    $method = $_POST['payment_method'] ?? '';
    $ref = $_POST['payment_ref'] ?? '';

    if (!$phone || $amount_cents <= 0) return ['error' => 'Phone and positive amount_cents required'];

    $account = getOrCreateAccount($phone, $name, $email);
    if (!$account) return ['error' => 'Invalid phone'];

    $current = (int)($account['field_' . F_BALANCE] ?? 0);
    $new_balance = $current + $amount_cents;

    // Update balance
    db_query("UPDATE app_entity_" . ENTITY_ACCOUNTS . " SET field_" . F_BALANCE . "=" . $new_balance . " WHERE id=" . (int)$account['id']);

    // Record transaction
    $desc = '$' . number_format($amount_cents / 100, 2) . ' credit purchase';
    recordTransaction($account['id'], TYPE_PURCHASE, $amount_cents, $new_balance, $desc, $method, $ref);

    $account['field_' . F_BALANCE] = $new_balance;
    return formatResponse($account);
}

function deductCredits() {
    $phone = $_POST['phone'] ?? '';
    $minutes = (int)($_POST['minutes'] ?? 0);

    if (!$phone || $minutes <= 0) return ['error' => 'Phone and positive minutes required'];

    $amount_cents = $minutes * 100; // $1/min
    $account = getOrCreateAccount($phone);
    if (!$account) return ['error' => 'Invalid phone'];

    $current = (int)($account['field_' . F_BALANCE] ?? 0);
    $new_balance = max(0, $current - $amount_cents);
    $actual_charged = $current - $new_balance;

    // Update balance
    db_query("UPDATE app_entity_" . ENTITY_ACCOUNTS . " SET field_" . F_BALANCE . "=" . $new_balance . " WHERE id=" . (int)$account['id']);

    // Record transaction
    $desc = $minutes . ' minute(s) call';
    recordTransaction($account['id'], TYPE_DEDUCT, $actual_charged, $new_balance, $desc);

    $account['field_' . F_BALANCE] = $new_balance;
    $resp = formatResponse($account);
    $resp['charged_cents'] = $actual_charged;
    return $resp;
}

function refundCredits() {
    $phone = $_POST['phone'] ?? '';
    $amount_cents = (int)($_POST['amount_cents'] ?? 0);
    $ref = $_POST['payment_ref'] ?? '';

    if (!$phone || $amount_cents <= 0) return ['error' => 'Phone and positive amount_cents required'];

    $account = getOrCreateAccount($phone);
    if (!$account) return ['error' => 'Invalid phone'];

    $current = (int)($account['field_' . F_BALANCE] ?? 0);
    $new_balance = $current + $amount_cents;

    db_query("UPDATE app_entity_" . ENTITY_ACCOUNTS . " SET field_" . F_BALANCE . "=" . $new_balance . " WHERE id=" . (int)$account['id']);

    $desc = '$' . number_format($amount_cents / 100, 2) . ' refund';
    recordTransaction($account['id'], TYPE_REFUND, $amount_cents, $new_balance, $desc, '', $ref);

    $account['field_' . F_BALANCE] = $new_balance;
    return formatResponse($account);
}

function getHistory() {
    $phone = $_POST['phone'] ?? $_GET['phone'] ?? '';
    if (!$phone) return ['error' => 'Phone required'];

    $account = getOrCreateAccount($phone);
    if (!$account) return ['error' => 'Invalid phone'];

    $q = db_query("SELECT * FROM app_entity_" . ENTITY_TRANSACTIONS . " WHERE parent_item_id=" . (int)$account['id'] . " ORDER BY id DESC LIMIT 50");

    $type_names = [TYPE_PURCHASE => 'purchase', TYPE_DEDUCT => 'deduct', TYPE_REFUND => 'refund'];
    $rows = [];
    while ($row = db_fetch_array($q)) {
        $rows[] = [
            'id' => (int)$row['id'],
            'type' => $type_names[$row['field_' . F_TYPE]] ?? 'unknown',
            'amount_cents' => (int)$row['field_' . F_AMOUNT],
            'balance_after' => (int)$row['field_' . F_BALANCE_AFTER],
            'description' => $row['field_' . F_DESCRIPTION],
            'payment_method' => $row['field_' . F_PAY_METHOD],
            'payment_ref' => $row['field_' . F_PAY_REF],
            'date' => date('Y-m-d H:i:s', $row['field_' . F_DATE]),
        ];
    }

    $resp = formatResponse($account);
    $resp['transactions'] = $rows;
    return $resp;
}

function confirmPaypal() {
    $phone = $_POST['phone'] ?? '';
    $amount = floatval($_POST['amount'] ?? 0);
    $order_id = $_POST['order_id'] ?? '';
    $name = $_POST['name'] ?? '';

    if (!$phone || $amount <= 0) return ['error' => 'Phone and amount required'];

    $_POST['amount_cents'] = (int)($amount * 100);
    $_POST['payment_method'] = 'paypal';
    $_POST['payment_ref'] = $order_id;
    $_POST['name'] = $name;

    return addCredits();
}
