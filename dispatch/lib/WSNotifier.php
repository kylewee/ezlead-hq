<?php
/**
 * WSNotifier - Send events to the WebSocket server from PHP.
 *
 * Usage:
 *   WSNotifier::send('incoming_call', ['phone' => '+19045551234', 'site' => 'mechanic']);
 */

class WSNotifier {
    private static string $url = 'http://127.0.0.1:8766/notify';
    private static string $token = 'dispatch-dev-token';

    public static function send(string $type, array $data = []): bool {
        $payload = json_encode([
            'type' => $type,
            'data' => $data,
            'timestamp' => time(),
        ]);

        $ch = curl_init(self::$url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::$token,
            ],
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);

        $response = curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        return $ok;
    }

    /**
     * Notify dashboard of incoming call.
     */
    public static function incomingCall(string $phone, string $site, ?array $customer = null): bool {
        return self::send('incoming_call', [
            'phone' => $phone,
            'site' => $site,
            'customer' => $customer,
            'channel' => 'pstn',
        ]);
    }

    /**
     * Notify dashboard of incoming SMS.
     */
    public static function incomingSMS(string $phone, string $message, string $site): bool {
        return self::send('incoming_sms', [
            'phone' => $phone,
            'message' => $message,
            'site' => $site,
        ]);
    }

    /**
     * Notify dashboard of WebRTC call request.
     */
    public static function webrtcRequest(string $sessionId, string $site, string $type = 'voice'): bool {
        return self::send('webrtc_request', [
            'cf_session_id' => $sessionId,
            'site' => $site,
            'type' => $type,
        ]);
    }

    /**
     * Notify dashboard of call state change.
     */
    public static function callStateChange(int $conversationId, string $status): bool {
        return self::send('call_state', [
            'conversation_id' => $conversationId,
            'status' => $status,
        ]);
    }
}
