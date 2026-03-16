<?php
/**
 * Zoho OAuth Callback
 * Captures the authorization code and exchanges it for access + refresh tokens.
 * Stores tokens in /var/lib/zoho/tokens.json for reuse.
 */

$token_file = '/var/lib/zoho/tokens.json';
$config_file = '/var/lib/zoho/config.json';

// Load config
if (!file_exists($config_file)) {
    die("Zoho not configured. Run setup first.");
}
$config = json_decode(file_get_contents($config_file), true);

// Step 1: If we have a code param, exchange it for tokens
if (isset($_GET['code'])) {
    $code = $_GET['code'];

    $ch = curl_init('https://accounts.zoho.com/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri'  => $config['redirect_uri'],
            'code'          => $code,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);

    if (!empty($data['access_token'])) {
        // Save tokens
        $data['obtained_at'] = time();
        @mkdir(dirname($token_file), 0755, true);
        file_put_contents($token_file, json_encode($data, JSON_PRETTY_PRINT));

        echo "<html><body style='font-family:sans-serif;text-align:center;padding:60px;'>";
        echo "<h1 style='color:#16a34a;'>Zoho Connected!</h1>";
        echo "<p>Access token and refresh token saved. You can close this tab.</p>";
        echo "<p><a href='index.php?module=ext/ipages/view&id=7'>Go to Quick Estimate</a></p>";
        echo "</body></html>";
    } else {
        echo "<html><body style='font-family:sans-serif;text-align:center;padding:60px;'>";
        echo "<h1 style='color:#ef4444;'>Error</h1>";
        echo "<pre>" . htmlspecialchars($resp) . "</pre>";
        echo "</body></html>";
    }
    exit;
}

// Step 2: If no code, redirect to Zoho auth
$auth_url = 'https://accounts.zoho.com/oauth/v2/auth?' . http_build_query([
    'scope'         => 'ZohoBooks.contacts.READ,ZohoBooks.estimates.READ,ZohoBooks.invoices.READ,ZohoBooks.settings.READ',
    'client_id'     => $config['client_id'],
    'response_type' => 'code',
    'redirect_uri'  => $config['redirect_uri'],
    'access_type'   => 'offline',
    'prompt'        => 'consent',
]);

header("Location: $auth_url");
exit;
