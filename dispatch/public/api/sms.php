<?php
/**
 * SMS API - Send/receive SMS via SignalWire
 */
header('Content-Type: application/json');

$config = require __DIR__ . '/../../config.php';
$authToken = hash('sha256', $config['auth']['password'] . ':dispatch-auth');
if (($_COOKIE['dispatch_auth'] ?? '') !== $authToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
$sw = $config['signalwire'];

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? 'send';

switch ($action) {
    case 'send':
        $to = $input['to'] ?? '';
        $message = $input['message'] ?? '';
        $from = $input['from'] ?? $sw['numbers']['mechanic']; // default to mechanic number

        if (!$to || !$message) {
            echo json_encode(['error' => 'Missing to or message']);
            exit;
        }

        // Normalize phone
        $digits = preg_replace('/\D/', '', $to);
        if (strlen($digits) === 10) $to = '+1' . $digits;
        elseif (strlen($digits) === 11 && $digits[0] === '1') $to = '+' . $digits;
        else $to = '+' . $digits;

        // Send via SignalWire REST API
        $url = "https://{$sw['space']}/api/laml/2010-04-01/Accounts/{$sw['project_id']}/Messages.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $to,
                'From' => $from,
                'Body' => $message,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);

        if ($httpCode === 201) {
            // Notify dashboard via WebSocket
            require_once __DIR__ . '/../../lib/WSNotifier.php';
            WSNotifier::send('outbound_sms', [
                'phone' => $to,
                'message' => $message,
                'from' => $from,
                'sid' => $result['sid'] ?? '',
            ]);

            echo json_encode(['success' => true, 'sid' => $result['sid'] ?? '']);
        } else {
            echo json_encode(['error' => 'Send failed', 'code' => $httpCode, 'detail' => $result]);
        }
        break;

    case 'history':
        // Fetch recent SMS from SignalWire
        $phone = $input['phone'] ?? $_GET['phone'] ?? '';
        $url = "https://{$sw['space']}/api/laml/2010-04-01/Accounts/{$sw['project_id']}/Messages.json";
        if ($phone) {
            // Get both sent and received
            $url .= '?' . http_build_query(['PageSize' => 50]);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);

        $messages = [];
        foreach (($data['messages'] ?? []) as $msg) {
            // Filter by phone if specified
            if ($phone) {
                $phoneDigits = preg_replace('/\D/', '', $phone);
                $toDigits = preg_replace('/\D/', '', $msg['to']);
                $fromDigits = preg_replace('/\D/', '', $msg['from']);
                if (substr($toDigits, -10) !== substr($phoneDigits, -10) &&
                    substr($fromDigits, -10) !== substr($phoneDigits, -10)) {
                    continue;
                }
            }
            $messages[] = [
                'sid' => $msg['sid'],
                'from' => $msg['from'],
                'to' => $msg['to'],
                'body' => $msg['body'],
                'direction' => (in_array($msg['direction'], ['outbound-api', 'outbound-call'])) ? 'outbound' : 'inbound',
                'date' => $msg['date_sent'],
                'status' => $msg['status'],
            ];
        }

        echo json_encode(['messages' => $messages]);
        break;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
