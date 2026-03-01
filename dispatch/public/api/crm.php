<?php
/**
 * CRM API - Create jobs, lookup customers, fetch recent activity
 */
header('Content-Type: application/json');

$config = require __DIR__ . '/../../config.php';
$authToken = hash('sha256', $config['auth']['password'] . ':dispatch-auth');
if (($_COOKIE['dispatch_auth'] ?? '') !== $authToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../lib/DispatchCRM.php';
$crm = new DispatchCRM();

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create_job':
        $phone = $input['phone'] ?? '';
        $name = $input['name'] ?? '';
        if (!$phone && !$name) {
            echo json_encode(['error' => 'Need phone or name']);
            exit;
        }

        // Look up customer by phone
        $customer = null;
        if ($phone) {
            $customer = $crm->lookupCustomer($phone);
        }

        // Create job in CRM (entity 42)
        $crmConfig = $config['crm'];
        $params = [
            'username' => $crmConfig['username'],
            'password' => $crmConfig['password'],
            'key' => $crmConfig['api_key'],
            'action' => 'insert',
            'entity_id' => 42,
            'items[field_354]' => $name ?: ($customer['field_428'] ?? 'New Job'),
        ];

        // Link to customer if found
        if ($customer) {
            $params['items[field_355]'] = $customer['id'];
        }

        $ch = curl_init($crmConfig['api_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response, true) ?: [];

        if (!empty($result['data']['id'])) {
            echo json_encode(['success' => true, 'job_id' => $result['data']['id']]);
        } else {
            echo json_encode(['error' => 'Failed to create job', 'detail' => $result]);
        }
        break;

    case 'lookup_customer':
        $phone = $input['phone'] ?? $_GET['phone'] ?? '';
        if (!$phone) {
            echo json_encode(['error' => 'Missing phone']);
            exit;
        }
        $customer = $crm->lookupCustomer($phone);
        echo json_encode(['customer' => $customer]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action: ' . $action]);
}
