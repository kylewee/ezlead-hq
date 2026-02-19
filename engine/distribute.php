<?php
/**
 * EzLead Distribution Engine
 *
 * Runs via cron every minute to:
 * 1. Find new unassigned leads
 * 2. Match to eligible buyers (zip, vertical, balance)
 * 3. Assign using distribution method (round_robin, first_claim, priority)
 * 4. Deduct balance, create transaction
 * 5. Auto-pause buyers below $5
 * 6. Send notifications
 */

require_once __DIR__ . '/config.php';

class LeadDistributor {
    private $db;
    private $roundRobinState = [];

    public function __construct() {
        $this->db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->loadRoundRobinState();
    }

    /**
     * Main entry point - process all new leads
     */
    public function run() {
        $leads = $this->getNewLeads();
        $processed = 0;

        foreach ($leads as $lead) {
            if ($this->distributeLead($lead)) {
                $processed++;
            }
        }

        $this->saveRoundRobinState();
        return $processed;
    }

    /**
     * Get leads with stage = 'New' (unassigned)
     * Rukovoditel stores data in direct field columns (field_210, field_211, etc.)
     */
    private function getNewLeads() {
        $sql = "SELECT id, date_added,
                    field_210 as name,
                    field_211 as phone,
                    field_212 as email,
                    field_213 as address,
                    field_214 as zip,
                    field_215 as source,
                    field_216 as vertical,
                    field_217 as notes,
                    field_218 as stage,
                    field_219 as assigned_buyer
                FROM app_entity_25
                WHERE field_218 = 'New' OR field_218 IS NULL OR field_218 = ''";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get eligible buyers for a lead
     */
    private function getEligibleBuyers($zip, $vertical) {
        $sql = "SELECT id,
                    field_223 as company,
                    field_224 as contact_name,
                    field_225 as phone,
                    field_226 as email,
                    field_227 as balance,
                    field_228 as price_per_lead,
                    field_229 as zip_codes,
                    field_230 as verticals,
                    field_231 as notify_pref,
                    field_232 as status,
                    field_251 as user_id
                FROM app_entity_26
                WHERE field_232 = 'active'
                    AND CAST(field_227 AS DECIMAL(10,2)) >= ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([MIN_BALANCE]);

        $buyers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter by zip and vertical
        return array_filter($buyers, function($buyer) use ($zip, $vertical) {
            // Check zip match
            $buyerZips = array_map('trim', explode(',', $buyer['zip_codes'] ?? ''));
            $zipMatch = empty($buyerZips[0]) || in_array($zip, $buyerZips);

            // Check vertical match
            $buyerVerticals = array_map('trim', explode(',', strtolower($buyer['verticals'] ?? '')));
            $verticalMatch = empty($buyerVerticals[0]) ||
                             in_array(strtolower($vertical), $buyerVerticals);

            return $zipMatch && $verticalMatch;
        });
    }

    /**
     * Get distribution method for a source
     */
    private function getDistributionMethod($sourceDomain) {
        $sql = "SELECT
                    MAX(CASE WHEN v.fields_id = ? THEN v.value END) as dist_method
                FROM app_entity_28 e
                LEFT JOIN app_entity_28_values v ON e.id = v.items_id
                GROUP BY e.id
                HAVING MAX(CASE WHEN v.fields_id = ? THEN v.value END) = ?";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([FIELD_SOURCE_DIST_METHOD, FIELD_SOURCE_DOMAIN, $sourceDomain]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result['dist_method'] ?? 'round_robin';
    }

    /**
     * Count how many leads a buyer has received (for free leads tracking)
     */
    private function getBuyerLeadCount($buyerId) {
        // Count leads assigned to this buyer (by user_id in field_219)
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as cnt FROM app_entity_25
            WHERE field_219 = ?
        ");
        $stmt->execute([$buyerId]);
        return (int) $stmt->fetch(PDO::FETCH_ASSOC)['cnt'];
    }

    /**
     * Check if buyer still has free leads remaining
     */
    private function hasFreeLeadsRemaining($buyerId) {
        $leadCount = $this->getBuyerLeadCount($buyerId);
        return $leadCount < FREE_LEADS_COUNT;
    }

    /**
     * Select winner based on distribution method
     */
    private function selectWinner($buyers, $method, $vertical) {
        if (empty($buyers)) return null;

        $buyers = array_values($buyers); // Re-index

        switch ($method) {
            case 'priority':
                // Highest price gets it
                usort($buyers, function($a, $b) {
                    return floatval($b['price_per_lead'] ?? DEFAULT_LEAD_PRICE) -
                           floatval($a['price_per_lead'] ?? DEFAULT_LEAD_PRICE);
                });
                return $buyers[0];

            case 'first_claim':
                // For now, same as round robin (real first-claim needs real-time UI)
                // Fall through to round_robin

            case 'round_robin':
            default:
                $key = $vertical ?: 'default';
                $index = $this->roundRobinState[$key] ?? 0;
                $winner = $buyers[$index % count($buyers)];
                $this->roundRobinState[$key] = ($index + 1) % count($buyers);
                return $winner;
        }
    }

    /**
     * Distribute a single lead
     */
    private function distributeLead($lead) {
        $zip = $lead['zip'] ?? '';
        $vertical = $lead['vertical'] ?? '';
        $source = $lead['source'] ?? '';

        // Get eligible buyers
        $buyers = $this->getEligibleBuyers($zip, $vertical);

        if (empty($buyers)) {
            $this->log("No eligible buyers for lead #{$lead['id']} (zip: $zip, vertical: $vertical)");
            return false;
        }

        // Get distribution method
        $method = $this->getDistributionMethod($source);

        // Select winner
        $winner = $this->selectWinner($buyers, $method, $vertical);

        if (!$winner) {
            $this->log("No winner selected for lead #{$lead['id']}");
            return false;
        }

        // Get user_id for this buyer (for portal visibility)
        $userId = $winner['user_id'] ?? $winner['id'];

        // Check if this is a free lead (count by user_id)
        $isFree = $this->hasFreeLeadsRemaining($userId);
        $leadCount = $this->getBuyerLeadCount($userId) + 1; // +1 for this lead

        // Get price (0 if free)
        $price = $isFree ? 0 : floatval($winner['price_per_lead'] ?? DEFAULT_LEAD_PRICE);
        $newBalance = floatval($winner['balance']) - $price;

        // Assign lead to buyer (using user_id for portal visibility)
        $this->updateFieldValue(ENTITY_LEADS, $lead['id'], FIELD_LEAD_ASSIGNED_BUYER, $userId);
        $this->updateFieldValue(ENTITY_LEADS, $lead['id'], FIELD_LEAD_STAGE, 'Distributed');

        // Create transaction (even for free leads, for tracking)
        $txnNote = $isFree
            ? "FREE Lead #{$lead['id']} ({$leadCount}/" . FREE_LEADS_COUNT . ") - {$lead['name']}"
            : "Lead #{$lead['id']} - {$lead['name']}";
        $this->createTransaction($winner['id'], $isFree ? 'free' : 'debit', $price, $lead['id'], $txnNote);

        // Update buyer balance (only if not free)
        if (!$isFree) {
            $this->updateFieldValue(ENTITY_BUYERS, $winner['id'], FIELD_BUYER_BALANCE, number_format($newBalance, 2, '.', ''));

            // Auto-pause if below minimum
            if ($newBalance < MIN_BALANCE) {
                $this->updateFieldValue(ENTITY_BUYERS, $winner['id'], FIELD_BUYER_STATUS, 'paused');
                $this->sendLowBalanceNotification($winner);
            }
        }

        // Send lead notification
        $this->sendLeadNotification($winner, $lead, $isFree, $leadCount);

        $priceDisplay = $isFree ? "FREE ({$leadCount}/" . FREE_LEADS_COUNT . ")" : "\${$price}";
        $this->log("Lead #{$lead['id']} assigned to {$winner['company']} (#{$winner['id']}) - {$priceDisplay}");

        return true;
    }

    /**
     * Update a field value in the CRM (direct column)
     */
    private function updateFieldValue($entityId, $itemId, $fieldId, $value) {
        $table = "app_entity_{$entityId}";
        $column = "field_{$fieldId}";

        $stmt = $this->db->prepare("UPDATE $table SET $column = ? WHERE id = ?");
        $stmt->execute([$value, $itemId]);
    }

    /**
     * Create a transaction record
     */
    private function createTransaction($buyerId, $type, $amount, $leadId, $notes) {
        // Create item with all field values in one INSERT
        $stmt = $this->db->prepare("INSERT INTO app_entity_27
            (date_added, created_by, parent_item_id, field_237, field_238, field_239, field_240)
            VALUES (NOW(), 1, ?, ?, ?, ?, ?)");
        $stmt->execute([$buyerId, $type, number_format($amount, 2, '.', ''), $leadId, $notes]);
    }

    /**
     * Send lead notification to buyer
     */
    private function sendLeadNotification($buyer, $lead, $isFree = false, $leadCount = 0) {
        $pref = strtolower($buyer['notify_pref'] ?? 'email');

        $freeTag = $isFree ? " (FREE - {$leadCount}/" . FREE_LEADS_COUNT . ")" : "";
        $subject = "New Lead: {$lead['name']}{$freeTag}";

        $freeNote = $isFree
            ? "This is FREE lead #{$leadCount} of your " . FREE_LEADS_COUNT . " free trial leads!\n\n"
            : "";

        $remainingFree = FREE_LEADS_COUNT - $leadCount;
        $freeRemaining = ($isFree && $remainingFree > 0)
            ? "You have {$remainingFree} free lead(s) remaining.\n\n"
            : ($isFree && $remainingFree == 0 ? "This was your last free lead. Future leads will be charged at your normal rate.\n\n" : "");

        $message = "You have a new lead!\n\n" .
                   $freeNote .
                   "Name: {$lead['name']}\n" .
                   "Phone: {$lead['phone']}\n" .
                   "Email: {$lead['email']}\n" .
                   "Address: {$lead['address']}\n" .
                   "Zip: {$lead['zip']}\n\n" .
                   $freeRemaining .
                   "View your leads: https://ezlead4u.com/buyer/";

        if ($pref === 'email' || $pref === 'both') {
            $this->sendEmail($buyer['email'], $subject, $message);
        }

        if ($pref === 'sms' || $pref === 'both') {
            $smsMsg = $isFree
                ? "FREE Lead ({$leadCount}/" . FREE_LEADS_COUNT . "): {$lead['name']} - {$lead['phone']}"
                : "New Lead: {$lead['name']} - {$lead['phone']}";
            $this->sendSMS($buyer['phone'], $smsMsg);
        }
    }

    /**
     * Send low balance notification
     */
    private function sendLowBalanceNotification($buyer) {
        $subject = "Low Balance - Account Paused";
        $message = "Your EzLead account has been paused due to low balance.\n\n" .
                   "Current Balance: \${$buyer['balance']}\n" .
                   "Minimum Required: \$" . MIN_BALANCE . "\n\n" .
                   "Add funds to resume receiving leads:\n" .
                   "https://ezlead4u.com/buyer/";

        $this->sendEmail($buyer['email'], $subject, $message);
    }

    /**
     * Send email via SMTP using PHPMailer
     */
    private function sendEmail($to, $subject, $message) {
        require_once '/var/www/ezlead-hq/crm/includes/libs/PHPMailer/6.8.0/vendor/phpmailer/phpmailer/src/PHPMailer.php';
        require_once '/var/www/ezlead-hq/crm/includes/libs/PHPMailer/6.8.0/vendor/phpmailer/phpmailer/src/SMTP.php';
        require_once '/var/www/ezlead-hq/crm/includes/libs/PHPMailer/6.8.0/vendor/phpmailer/phpmailer/src/Exception.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;

            $mail->setFrom(FROM_EMAIL, FROM_NAME);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $message;

            $mail->send();
            $this->log("Email sent to $to: $subject");
        } catch (\Exception $e) {
            $this->log("Email failed to $to: " . $mail->ErrorInfo);
        }
    }

    /**
     * Send SMS (placeholder - integrate with SignalWire)
     */
    private function sendSMS($to, $message) {
        // TODO: Integrate with SignalWire
        $this->log("SMS would be sent to $to: $message");
    }

    /**
     * Load round robin state from file
     */
    private function loadRoundRobinState() {
        $file = __DIR__ . '/round_robin_state.json';
        if (file_exists($file)) {
            $this->roundRobinState = json_decode(file_get_contents($file), true) ?: [];
        }
    }

    /**
     * Save round robin state to file
     */
    private function saveRoundRobinState() {
        $file = __DIR__ . '/round_robin_state.json';
        file_put_contents($file, json_encode($this->roundRobinState));
    }

    /**
     * Log message
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logFile = __DIR__ . '/distribution.log';
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
        echo "[$timestamp] $message\n";
    }
}

// Run if called directly
if (php_sapi_name() === 'cli' || !empty($_GET['run'])) {
    $distributor = new LeadDistributor();
    $count = $distributor->run();
    echo "Processed $count leads\n";
}
