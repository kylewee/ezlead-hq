#!/usr/bin/env php
<?php
/**
 * Send Follow-up SMS + Review Requests
 *
 * Run daily via cron:
 * 0 10 * * * php /home/kylewee/code/inbred/cron/followups.php >> /home/kylewee/code/inbred/cron/followups.log 2>&1
 */

require_once __DIR__ . '/../lib/CustomerFlow/Flow.php';

$flow = new CustomerFlow\Flow();
$result = $flow->sendDueFollowUps();

echo date('Y-m-d H:i:s') . " - Sent {$result['sent']} follow-ups\n";
