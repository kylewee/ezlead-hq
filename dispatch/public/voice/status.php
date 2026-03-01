<?php
/**
 * Call status webhook - receives updates from SignalWire.
 *
 * Handles:
 *   - Call status changes (ringing, in-progress, completed, etc.)
 *   - Conference events (start, end, join, leave)
 *   - Recording ready callbacks
 *
 * Pushes events to the dispatch dashboard via WebSocket.
 */
$config = require __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/WSNotifier.php';

$callId = $_REQUEST['callId'] ?? '';
$callStatus = $_REQUEST['CallStatus'] ?? '';
$conferenceSid = $_REQUEST['ConferenceSid'] ?? '';
$statusCallbackEvent = $_REQUEST['StatusCallbackEvent'] ?? '';
$recordingUrl = $_REQUEST['RecordingUrl'] ?? '';
$recordingDuration = $_REQUEST['RecordingDuration'] ?? '';
$from = $_REQUEST['From'] ?? '';
$to = $_REQUEST['To'] ?? '';

// Log for debugging
$logLine = date('Y-m-d H:i:s') . " callId={$callId} status={$callStatus} event={$statusCallbackEvent} from={$from} to={$to}";
if ($recordingUrl) $logLine .= " recording={$recordingUrl}";
file_put_contents(__DIR__ . '/../../data/call_status.log', $logLine . "\n", FILE_APPEND);

// Push call state to dashboard
if ($callStatus) {
    $stateMap = [
        'queued' => 'queued',
        'ringing' => 'ringing',
        'in-progress' => 'connected',
        'completed' => 'ended',
        'busy' => 'ended',
        'no-answer' => 'ended',
        'canceled' => 'ended',
        'failed' => 'ended',
    ];

    WSNotifier::send('call_state', [
        'callId' => $callId,
        'status' => $stateMap[$callStatus] ?? $callStatus,
        'rawStatus' => $callStatus,
        'from' => $from,
        'to' => $to,
    ]);
}

// Conference events - map to meaningful call states
if ($statusCallbackEvent) {
    $confStateMap = [
        'conference-start' => 'connected',
        'conference-end' => 'ended',
        'participant-join' => null,  // don't push, wait for conference-start
        'participant-leave' => null,
    ];

    $mappedStatus = $confStateMap[$statusCallbackEvent] ?? null;
    if ($mappedStatus) {
        WSNotifier::send('call_state', [
            'callId' => $callId,
            'status' => $mappedStatus,
            'event' => $statusCallbackEvent,
        ]);
    }
}

// Recording ready - log to CRM
if ($recordingUrl && $callId) {
    require_once __DIR__ . '/../../lib/DispatchCRM.php';
    $crm = new DispatchCRM();

    // Find conversation by callId stored in cf_session field
    // and update with recording URL
    WSNotifier::send('call_state', [
        'callId' => $callId,
        'status' => 'recording_ready',
        'recordingUrl' => $recordingUrl,
        'duration' => $recordingDuration,
    ]);
}

// Always return 200
http_response_code(200);
echo '<Response></Response>';
