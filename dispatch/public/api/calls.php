<?php
/**
 * Calls API - Cloudflare Calls proxy + SignalWire outbound dialing
 *
 * POST /api/calls.php?action=new_session    (Cloudflare Calls)
 * POST /api/calls.php?action=new_tracks     (Cloudflare Calls)
 * PUT  /api/calls.php?action=renegotiate    (Cloudflare Calls)
 * POST /api/calls.php?action=dial           (SignalWire outbound)
 * POST /api/calls.php?action=hangup         (SignalWire hangup)
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = require __DIR__ . '/../../config.php';

$cf = $config['cloudflare'];
$appId = $cf['app_id'];
$appToken = $cf['app_token'];
$baseUrl = $cf['base_url'];

$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !$action) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing action or body']);
    exit;
}

// Auth required for dial/hangup actions
if (in_array($action, ['dial', 'hangup'])) {
    $authToken = hash('sha256', $config['auth']['password'] . ':dispatch-auth');
    if (($_COOKIE['dispatch_auth'] ?? '') !== $authToken) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$sessionId = $input['sessionId'] ?? $_GET['sessionId'] ?? null;

switch ($action) {
    case 'new_session':
        $url = "$baseUrl/apps/$appId/sessions/new";
        $body = [
            'sessionDescription' => [
                'type' => 'offer',
                'sdp' => $input['sdp'],
            ],
        ];
        $result = cfRequest($url, $body, $appToken);
        break;

    case 'new_tracks':
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing sessionId']);
            exit;
        }
        $url = "$baseUrl/apps/$appId/sessions/$sessionId/tracks/new";
        $body = ['tracks' => $input['tracks']];
        if (!empty($input['sdp'])) {
            $body['sessionDescription'] = [
                'type' => 'offer',
                'sdp' => $input['sdp'],
            ];
        }
        $result = cfRequest($url, $body, $appToken);
        break;

    case 'renegotiate':
        if (!$sessionId) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing sessionId']);
            exit;
        }
        $url = "$baseUrl/apps/$appId/sessions/$sessionId/renegotiate";
        $body = [
            'sessionDescription' => [
                'type' => 'answer',
                'sdp' => $input['sdp'],
            ],
        ];
        $result = cfRequest($url, $body, $appToken, 'PUT');
        break;

    case 'dial':
        // Outbound call via SignalWire conference bridge
        $to = $input['to'] ?? '';
        $from = $input['from'] ?? $config['signalwire']['numbers']['mechanic_ported'];

        if (!$to) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing "to" phone number']);
            exit;
        }

        // Normalize phone
        $digits = preg_replace('/\D/', '', $to);
        if (strlen($digits) === 10) $to = '+1' . $digits;
        elseif (strlen($digits) === 11 && $digits[0] === '1') $to = '+' . $digits;
        else $to = '+' . $digits;

        $sw = $config['signalwire'];
        $callId = 'call_' . time() . '_' . bin2hex(random_bytes(4));
        $room = 'dispatch_' . $callId;
        $kylePhone = $sw['forward_to'];

        $baseCallback = "https://dispatch.ezlead4u.com/voice";

        // Leg 1: Dial the customer
        $customerUrl = "{$baseCallback}/conference.php?" . http_build_query([
            'room' => $room, 'role' => 'customer', 'callId' => $callId,
        ]);
        $statusUrl = "{$baseCallback}/status.php?" . http_build_query(['callId' => $callId]);

        $apiUrl = "https://{$sw['space']}/api/laml/2010-04-01/Accounts/{$sw['project_id']}/Calls.json";

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $to,
                'From' => $from,
                'Url' => $customerUrl,
                'Method' => 'POST',
                'StatusCallback' => $statusUrl,
                'StatusCallbackMethod' => 'POST',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $customerResponse = curl_exec($ch);
        $customerCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $customerResult = json_decode($customerResponse, true);

        if ($customerCode !== 201) {
            echo json_encode(['error' => 'Failed to dial customer', 'code' => $customerCode, 'detail' => $customerResult]);
            exit;
        }

        // Leg 2: Dial Kyle's phone into the same conference
        $dispatcherUrl = "{$baseCallback}/conference.php?" . http_build_query([
            'room' => $room, 'role' => 'dispatcher', 'callId' => $callId,
        ]);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $kylePhone,
                'From' => $from,
                'Url' => $dispatcherUrl,
                'Method' => 'POST',
                'StatusCallback' => $statusUrl,
                'StatusCallbackMethod' => 'POST',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $dispatcherResponse = curl_exec($ch);
        $dispatcherCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $dispatcherResult = json_decode($dispatcherResponse, true);

        // Notify dashboard
        require_once __DIR__ . '/../../lib/WSNotifier.php';
        WSNotifier::send('call_state', [
            'callId' => $callId,
            'status' => 'dialing',
            'to' => $to,
            'from' => $from,
            'room' => $room,
            'customerSid' => $customerResult['sid'] ?? null,
            'dispatcherSid' => $dispatcherResult['sid'] ?? null,
        ]);

        // Log to CRM
        require_once __DIR__ . '/../../lib/DispatchCRM.php';
        $crm = new DispatchCRM();
        $convoId = $crm->createConversation([
            'channel' => $config['conversations']['channels']['Call'],
            'direction' => $config['conversations']['directions']['Outbound'],
            'status' => $config['conversations']['statuses']['Ringing'],
            'started' => time(),
            'phone' => $to,
            'cf_session' => $callId,
        ]);

        $result = [
            'success' => true,
            'callId' => $callId,
            'room' => $room,
            'customerSid' => $customerResult['sid'] ?? null,
            'dispatcherSid' => $dispatcherResult['sid'] ?? null,
            'conversationId' => $convoId,
        ];
        break;

    case 'hangup':
        // End a call by SID
        $callSid = $input['callSid'] ?? '';
        if (!$callSid) {
            echo json_encode(['error' => 'Missing callSid']);
            exit;
        }

        $sw = $config['signalwire'];
        $apiUrl = "https://{$sw['space']}/api/laml/2010-04-01/Accounts/{$sw['project_id']}/Calls/{$callSid}.json";

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['Status' => 'completed']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = ['success' => $httpCode < 400, 'code' => $httpCode];
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => "Unknown action: $action"]);
        exit;
}

echo json_encode($result);

function cfRequest(string $url, array $body, string $token, string $method = 'POST'): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer $token",
        ],
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        http_response_code($httpCode);
    }

    return json_decode($response, true) ?: ['error' => 'Invalid response from Cloudflare'];
}
