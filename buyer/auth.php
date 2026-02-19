<?php
/**
 * Simple session-based auth for buyer portal
 * Authenticates against CRM users (app_entity_1)
 */

session_start();
require_once __DIR__ . '/db.php';

function login(string $email, string $password): array|false {
    $db = getDb();

    // Find user by email (field_4 = email, field_5 = status, field_6 = group)
    $stmt = $db->prepare("
        SELECT id, field_2 as username, field_3 as password_hash, field_4 as email
        FROM app_entity_1
        WHERE field_4 = :email AND field_5 = 1 AND field_6 = 6
    ");
    $stmt->execute([':email' => strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user) return false;

    // Rukovoditel uses MD5 for passwords
    if (md5($password) !== $user['password_hash']) {
        return false;
    }

    // Get linked buyer record
    $stmt = $db->prepare("
        SELECT id, field_223 as company, field_224 as contact, field_227 as balance
        FROM app_entity_26
        WHERE field_251 = :user_id
    ");
    $stmt->execute([':user_id' => $user['id']]);
    $buyer = $stmt->fetch();

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['buyer_id'] = $buyer['id'] ?? null;
    $_SESSION['email'] = $user['email'];
    $_SESSION['company'] = $buyer['company'] ?? '';

    return [
        'user_id' => $user['id'],
        'buyer_id' => $buyer['id'] ?? null,
        'email' => $user['email'],
        'company' => $buyer['company'] ?? '',
        'balance' => $buyer['balance'] ?? 0,
    ];
}

function getCurrentUser(): array|null {
    if (empty($_SESSION['user_id'])) return null;

    $db = getDb();
    $stmt = $db->prepare("
        SELECT u.id as user_id, u.field_4 as email,
               b.id as buyer_id, b.field_223 as company, b.field_227 as balance
        FROM app_entity_1 u
        LEFT JOIN app_entity_26 b ON b.field_251 = u.id
        WHERE u.id = :id AND u.field_5 = 1
    ");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    return $stmt->fetch() ?: null;
}

function requireAuth(): array {
    $user = getCurrentUser();
    if (!$user) {
        header('Location: /buyer/login.php');
        exit;
    }
    return $user;
}

function logout(): void {
    session_destroy();
}
