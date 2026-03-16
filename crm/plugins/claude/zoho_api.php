<?php
/**
 * Zoho Books API Helper
 * Handles token refresh and API calls.
 */

class ZohoBooks {
    private static $token_file = '/var/lib/zoho/tokens.json';
    private static $config_file = '/var/lib/zoho/config.json';
    private static $org_id = null;

    /**
     * Get a valid access token (auto-refreshes if expired)
     */
    public static function getAccessToken(): ?string {
        if (!file_exists(self::$token_file)) return null;
        $tokens = json_decode(file_get_contents(self::$token_file), true);
        if (!$tokens) return null;

        // Check if expired (tokens last 1 hour)
        $age = time() - ($tokens['obtained_at'] ?? 0);
        if ($age > 3500 && !empty($tokens['refresh_token'])) {
            $tokens = self::refreshToken($tokens['refresh_token']);
            if (!$tokens) return null;
        }

        return $tokens['access_token'] ?? null;
    }

    /**
     * Refresh the access token using refresh token
     */
    private static function refreshToken(string $refreshToken): ?array {
        $config = json_decode(file_get_contents(self::$config_file), true);
        if (!$config) return null;

        $ch = curl_init('https://accounts.zoho.com/oauth/v2/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type'    => 'refresh_token',
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
                'refresh_token' => $refreshToken,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($resp, true);
        if (empty($data['access_token'])) return null;

        // Merge with existing (keep refresh_token)
        $existing = json_decode(file_get_contents(self::$token_file), true) ?: [];
        $existing['access_token'] = $data['access_token'];
        $existing['obtained_at'] = time();
        file_put_contents(self::$token_file, json_encode($existing, JSON_PRETTY_PRINT));

        return $existing;
    }

    /**
     * Get organization ID (cached)
     */
    public static function getOrgId(): ?string {
        if (self::$org_id) return self::$org_id;

        $result = self::request('GET', 'https://www.zohoapis.com/books/v3/organizations');
        if ($result && !empty($result['organizations'])) {
            self::$org_id = (string)$result['organizations'][0]['organization_id'];
            return self::$org_id;
        }
        return null;
    }

    /**
     * Make an API request to Zoho Books
     */
    public static function request(string $method, string $url, array $params = []): ?array {
        $token = self::getAccessToken();
        if (!$token) return null;

        if ($method === 'GET' && $params) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => ["Authorization: Zoho-oauthtoken $token"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);

        if ($method === 'POST' && $params) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Authorization: Zoho-oauthtoken $token",
                "Content-Type: application/json",
            ]);
        }

        $resp = curl_exec($ch);
        curl_close($ch);

        return json_decode($resp, true);
    }

    /**
     * Search contacts by name
     */
    public static function searchContact(string $name): ?array {
        $orgId = self::getOrgId();
        if (!$orgId) return null;

        $result = self::request('GET', 'https://www.zohoapis.com/books/v3/contacts', [
            'organization_id' => $orgId,
            'contact_name_contains' => $name,
        ]);

        return $result['contacts'] ?? null;
    }

    /**
     * Get contact details by ID
     */
    public static function getContact(string $contactId): ?array {
        $orgId = self::getOrgId();
        if (!$orgId) return null;

        $result = self::request('GET', "https://www.zohoapis.com/books/v3/contacts/$contactId", [
            'organization_id' => $orgId,
        ]);

        return $result['contact'] ?? null;
    }

    /**
     * Get estimates for a contact
     */
    public static function getEstimates(string $contactId = ''): ?array {
        $orgId = self::getOrgId();
        if (!$orgId) return null;

        $params = ['organization_id' => $orgId];
        if ($contactId) $params['customer_id'] = $contactId;

        $result = self::request('GET', 'https://www.zohoapis.com/books/v3/estimates', $params);

        return $result['estimates'] ?? null;
    }

    /**
     * Get invoices for a contact
     */
    public static function getInvoices(string $contactId = ''): ?array {
        $orgId = self::getOrgId();
        if (!$orgId) return null;

        $params = ['organization_id' => $orgId];
        if ($contactId) $params['customer_id'] = $contactId;

        $result = self::request('GET', 'https://www.zohoapis.com/books/v3/invoices', $params);

        return $result['invoices'] ?? null;
    }
}
