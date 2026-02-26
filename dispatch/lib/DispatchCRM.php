<?php
/**
 * DispatchCRM - CRM operations for the dispatch system.
 * Wraps Rukovoditel REST API for Conversations entity (51).
 */

class DispatchCRM {
    private array $config;
    private array $fields;

    public function __construct() {
        $fullConfig = require __DIR__ . '/../config.php';
        $this->config = $fullConfig['crm'];
        $this->fields = $fullConfig['conversations']['fields'];
    }

    /**
     * Create a new conversation record.
     */
    public function createConversation(array $data): ?int {
        $items = [];
        foreach ($data as $key => $value) {
            if (isset($this->fields[$key])) {
                $items["items[field_{$this->fields[$key]}]"] = $value;
            }
        }

        $result = $this->apiCall('insert', 51, $items);
        return isset($result['data']['id']) ? (int)$result['data']['id'] : null;
    }

    /**
     * Update a conversation record.
     */
    public function updateConversation(int $id, array $data): bool {
        $params = [
            'update_by_field[id]' => $id,
        ];
        foreach ($data as $key => $value) {
            if (isset($this->fields[$key])) {
                $params["data[field_{$this->fields[$key]}]"] = $value;
            }
        }

        $result = $this->apiCall('update', 51, $params);
        return !isset($result['error']);
    }

    /**
     * Get recent conversations, optionally filtered.
     */
    public function getConversations(array $filters = [], int $limit = 50): array {
        $params = [];
        if (!empty($filters['channel'])) {
            $params["filters[field_{$this->fields['channel']}]"] = $filters['channel'];
        }
        if (!empty($filters['site'])) {
            $params["filters[field_{$this->fields['site']}]"] = $filters['site'];
        }
        $params['limit'] = $limit;

        return $this->apiCall('select', 51, $params);
    }

    /**
     * Look up customer by phone (uses entity 47).
     */
    public function lookupCustomer(string $phone): ?array {
        $phone = $this->normalizePhone($phone);
        $result = $this->apiCall('select', 47, [
            "filters[field_428]" => $phone,
        ]);

        if (!empty($result) && is_array($result)) {
            $items = array_values(array_filter($result, fn($r) => is_array($r) && isset($r['id'])));
            return $items[0] ?? null;
        }
        return null;
    }

    /**
     * Normalize phone to 10-digit format.
     */
    private function normalizePhone(string $phone): string {
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }
        return $digits;
    }

    private function apiCall(string $action, int $entityId, array $extra = []): array {
        $params = array_merge([
            'username' => $this->config['username'],
            'password' => $this->config['password'],
            'key' => $this->config['api_key'],
            'action' => $action,
            'entity_id' => $entityId,
        ], $extra);

        $ch = curl_init($this->config['api_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: [];
    }
}
