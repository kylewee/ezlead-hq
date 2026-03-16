<?php
require_once __DIR__ . '/BuyerAuth.php';
$auth = new BuyerAuth();
$auth->logout();
header('Location: /buyer/login.php');
exit;
