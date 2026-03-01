<?php
/**
 * TwiML handler for dispatch conference calls.
 *
 * Called by SignalWire when a call leg connects. Both the customer and
 * Kyle's phone get TwiML that joins them to the same conference room.
 *
 * Query params:
 *   room    - Conference room name (unique per call)
 *   role    - "customer" or "dispatcher"
 *   callId  - Internal call tracking ID
 */
header('Content-Type: text/xml');

$room = $_REQUEST['room'] ?? 'dispatch-default';
$role = $_REQUEST['role'] ?? 'customer';
$callId = $_REQUEST['callId'] ?? '';

// Conference attributes
$beep = $role === 'dispatcher' ? 'false' : 'true';
$startOnEnter = $role === 'dispatcher' ? 'true' : 'false';
$endOnExit = $role === 'dispatcher' ? 'true' : 'false';

// Status callback for when the conference ends
$statusCallback = "https://dispatch.ezlead4u.com/voice/status.php?callId={$callId}";

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
<?php if ($role === 'customer'): ?>
    <Say voice="alice">Please hold while we connect you.</Say>
<?php endif; ?>
    <Dial>
        <Conference
            beep="<?= $beep ?>"
            startConferenceOnEnter="<?= $startOnEnter ?>"
            endConferenceOnExit="<?= $endOnExit ?>"
            record="record-from-start"
            recordingStatusCallback="<?= htmlspecialchars($statusCallback) ?>"
            recordingStatusCallbackMethod="POST"
            statusCallback="<?= htmlspecialchars($statusCallback) ?>"
            statusCallbackEvent="start end join leave"
            statusCallbackMethod="POST"
        ><?= htmlspecialchars($room) ?></Conference>
    </Dial>
</Response>
