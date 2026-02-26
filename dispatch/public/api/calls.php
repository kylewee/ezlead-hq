<?php
/**
 * Cloudflare Calls API Proxy
 *
 * Keeps the API token server-side. The dispatch dashboard and customer widgets
 * call this endpoint instead of Cloudflare directly.
 *
 * POST /api/calls.php?action=new_session
 * POST /api/calls.php?action=new_tracks
 * PUT  /api/calls.php?action=renegotiate
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
