<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'includes/application_core.php';

session_name(SESSION_NAME);
session_set_cookie_params(0, SESSION_COOKIE_PATH, SESSION_COOKIE_DOMAIN);

if(STORE_SESSIONS == 'mysql') {
    // session handler is registered in sessions.php already
}

session_start();

if(!isset($_SESSION['test_counter'])) {
    $_SESSION['test_counter'] = 0;
}
$_SESSION['test_counter']++;

echo json_encode([
    'session_id' => session_id(),
    'counter' => $_SESSION['test_counter'],
    'token' => $_SESSION['app_session_token'] ?? 'NOT SET',
]);
