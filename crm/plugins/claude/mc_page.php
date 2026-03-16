<?php
// Mission Control - self-contained page that inlines the JS
// This bypasses Cloudflare caching of static .js files
require_once(__DIR__ . '/../../config/database.php');
$db = new mysqli(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE);
$row = $db->query("SELECT html_code FROM app_ext_ipages WHERE id=6")->fetch_assoc();
$db->close();

// Get the HTML but strip the script tag
$html = $row['html_code'];
$html = preg_replace('/<script>.*?<\/script>/s', '', $html);

// Output the HTML
echo $html;

// Inline the JS directly
echo '<script>';
readfile(__DIR__ . '/mc3.js');
echo '</script>';
