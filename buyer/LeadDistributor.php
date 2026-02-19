<?php
/**
 * Lead Distributor - Assigns leads to buyers
 *
 * This class handles:
 * - Distributing new leads to eligible buyers
 * - Checking buyer balance and campaign limits
 * - Charging buyers for leads
 * - Notifying buyers of new leads
 */

class LeadDistributor {
    private SQLite3 $db;
    private array $config;

    public function __construct() {
        $dbFile = __DIR__ . '/../data/buyers.db';
        $this->db = new SQLite3($dbFile);
        $this->db->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Distribute a lead to eligible buyers
     *
     * @param array $leadData Lead data from CRM
     * @param string $siteDomain Source domain
     * @param int $crmLeadId CRM lead ID
     * @return array Results of distribution
     */
    public function distributeLead(array $leadData, string $siteDomain, int $crmLeadId): array {
        $results = [];

        // Find eligible buyers
        // - Status = active
        // - Balance >= min_balance
        // - Has campaign for this domain (or all domains)
        // - Under daily/weekly cap

        $buyers = $this->findEligibleBuyers($siteDomain);

        foreach ($buyers as $buyer) {
            $price = $buyer['price_per_lead'] ?? 2500; // cents

            // Check balance
            if ($buyer['balance'] < $buyer['min_balance']) {
                $results[] = [
                    'buyer_id' => $buyer['id'],
                    'status' => 'skipped',
                    'reason' => 'Balance below minimum',
                ];
                continue;
            }

            // Assign lead to buyer
            $leadId = $this->assignLead($buyer['id'], $crmLeadId, $siteDomain, $leadData, $price);

            if ($leadId) {
                // Charge buyer
                $this->chargeBuyer($buyer['id'], $price, $leadId);

                // Send notification (email/SMS)
                $this->notifyBuyer($buyer, $leadData);

                $results[] = [
                    'buyer_id' => $buyer['id'],
                    'buyer_lead_id' => $leadId,
                    'status' => 'delivered',
                    'price' => $price,
                ];
            }
        }

        return $results;
    }

    /**
     * Find buyers eligible to receive a lead from this domain
     */
    private function findEligibleBuyers(string $siteDomain): array {
        // Get active buyers with sufficient balance
        $stmt = $this->db->prepare("
            SELECT b.*
            FROM buyers b
            WHERE b.status = 'active'
            AND b.balance >= b.min_balance
            ORDER BY b.balance DESC
        ");

        $result = $stmt->execute();
        $buyers = [];

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // Check if buyer has campaign for this domain
            $campaign = $this->getActiveCampaign($row['id'], $siteDomain);

            if ($campaign) {
                // Check daily/weekly caps
                if ($this->isUnderCap($campaign)) {
                    $row['campaign'] = $campaign;
                    $row['price_per_lead'] = $campaign['price_per_lead'] ?? $row['price_per_lead'];
                    $buyers[] = $row;
                }
            } elseif (!$this->hasCampaigns($row['id'])) {
                // Buyer has no campaigns = accepts all leads
                $buyers[] = $row;
            }
        }

        return $buyers;
    }

    /**
     * Check if buyer has any campaigns configured
     */
    private function hasCampaigns(int $buyerId): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM buyer_campaigns WHERE buyer_id = :id");
        $stmt->bindValue(':id', $buyerId);
        return $stmt->execute()->fetchArray()[0] > 0;
    }

    /**
     * Get active campaign for buyer + domain
     */
    private function getActiveCampaign(int $buyerId, string $siteDomain): ?array {
        $stmt = $this->db->prepare("
            SELECT * FROM buyer_campaigns
            WHERE buyer_id = :buyer_id
            AND status = 'active'
            AND (site_domain IS NULL OR site_domain = '' OR site_domain = :domain)
            LIMIT 1
        ");
        $stmt->bindValue(':buyer_id', $buyerId);
        $stmt->bindValue(':domain', $siteDomain);
        $result = $stmt->execute();
        $campaign = $result->fetchArray(SQLITE3_ASSOC);
        return $campaign ?: null;
    }

    /**
     * Check if campaign is under daily/weekly cap
     */
    private function isUnderCap(array $campaign): bool {
        // Daily cap
        if ($campaign['max_per_day'] && $campaign['leads_today'] >= $campaign['max_per_day']) {
            return false;
        }
        // Weekly cap
        if ($campaign['max_per_week'] && $campaign['leads_this_week'] >= $campaign['max_per_week']) {
            return false;
        }
        return true;
    }

    /**
     * Assign lead to buyer
     */
    private function assignLead(int $buyerId, int $crmLeadId, string $siteDomain, array $leadData, int $price): int|false {
        $stmt = $this->db->prepare("
            INSERT INTO buyer_leads (buyer_id, crm_lead_id, site_domain, lead_data, price, status, delivered_at)
            VALUES (:buyer_id, :crm_lead_id, :site_domain, :lead_data, :price, 'delivered', datetime('now'))
        ");

        $stmt->bindValue(':buyer_id', $buyerId);
        $stmt->bindValue(':crm_lead_id', $crmLeadId);
        $stmt->bindValue(':site_domain', $siteDomain);
        $stmt->bindValue(':lead_data', json_encode($leadData));
        $stmt->bindValue(':price', $price);

        if ($stmt->execute()) {
            $leadId = $this->db->lastInsertRowID();

            // Update campaign counters if applicable
            $this->updateCampaignCounters($buyerId, $siteDomain);

            return $leadId;
        }

        return false;
    }

    /**
     * Update campaign lead counters
     */
    private function updateCampaignCounters(int $buyerId, string $siteDomain): void {
        $stmt = $this->db->prepare("
            UPDATE buyer_campaigns
            SET leads_today = leads_today + 1,
                leads_this_week = leads_this_week + 1,
                last_lead_at = datetime('now'),
                updated_at = datetime('now')
            WHERE buyer_id = :buyer_id
            AND (site_domain IS NULL OR site_domain = '' OR site_domain = :domain)
        ");
        $stmt->bindValue(':buyer_id', $buyerId);
        $stmt->bindValue(':domain', $siteDomain);
        $stmt->execute();
    }

    /**
     * Charge buyer for a lead (or use free lead if available)
     */
    private function chargeBuyer(int $buyerId, int $price, int $leadId): void {
        // Check for free leads first
        $stmt = $this->db->prepare("SELECT balance, free_leads_remaining FROM buyers WHERE id = :id");
        $stmt->bindValue(':id', $buyerId);
        $buyer = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        $currentBalance = $buyer['balance'] ?? 0;
        $freeLeads = $buyer['free_leads_remaining'] ?? 0;

        // Use free lead if available
        if ($freeLeads > 0) {
            $stmt = $this->db->prepare("UPDATE buyers SET free_leads_remaining = free_leads_remaining - 1, updated_at = datetime('now') WHERE id = :id");
            $stmt->bindValue(':id', $buyerId);
            $stmt->execute();

            // Record as free lead transaction
            $stmt = $this->db->prepare("
                INSERT INTO buyer_transactions (buyer_id, type, amount, balance_after, description, reference_id)
                VALUES (:buyer_id, 'free_lead', 0, :balance_after, 'Free test lead (' || :remaining || ' remaining)', :ref_id)
            ");
            $stmt->bindValue(':buyer_id', $buyerId);
            $stmt->bindValue(':balance_after', $currentBalance);
            $stmt->bindValue(':remaining', $freeLeads - 1);
            $stmt->bindValue(':ref_id', $leadId);
            $stmt->execute();

            return; // No charge
        }

        // Otherwise charge normally
        $newBalance = $currentBalance - $price;

        // Update balance
        $stmt = $this->db->prepare("UPDATE buyers SET balance = :balance, updated_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':balance', $newBalance);
        $stmt->bindValue(':id', $buyerId);
        $stmt->execute();

        // Record transaction
        $stmt = $this->db->prepare("
            INSERT INTO buyer_transactions (buyer_id, type, amount, balance_after, description, reference_id)
            VALUES (:buyer_id, 'charge', :amount, :balance_after, 'Lead purchase', :ref_id)
        ");
        $stmt->bindValue(':buyer_id', $buyerId);
        $stmt->bindValue(':amount', -$price); // Negative for charge
        $stmt->bindValue(':balance_after', $newBalance);
        $stmt->bindValue(':ref_id', $leadId);
        $stmt->execute();

        // Check if balance is low - auto-pause if below minimum
        $stmt = $this->db->prepare("SELECT min_balance FROM buyers WHERE id = :id");
        $stmt->bindValue(':id', $buyerId);
        $minBalance = $stmt->execute()->fetchArray()['min_balance'] ?? 1000;

        if ($newBalance < $minBalance) {
            $this->pauseBuyer($buyerId, 'Balance below minimum');
        }
    }

    /**
     * Pause a buyer's account
     */
    public function pauseBuyer(int $buyerId, string $reason = ''): void {
        $stmt = $this->db->prepare("UPDATE buyers SET status = 'paused', updated_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':id', $buyerId);
        $stmt->execute();

        // Log the pause
        error_log("Buyer #{$buyerId} paused: {$reason}");
    }

    /**
     * Notify buyer of new lead
     */
    private function notifyBuyer(array $buyer, array $leadData): void {
        // Get delivery method from campaign or default
        $method = $buyer['campaign']['delivery_method'] ?? 'portal';

        // Send email if method is email or both
        if (in_array($method, ['email', 'both']) && !empty($buyer['email'])) {
            $this->sendEmailNotification($buyer, $leadData);
        }

        // Send SMS if method is sms or both, or if email method (send both for important leads)
        if (!empty($buyer['phone'])) {
            if (in_array($method, ['sms', 'both', 'email'])) {
                $this->sendSmsNotification($buyer, $leadData);
            }
        }
        // Portal = no notification, they check dashboard
    }

    /**
     * Send email notification
     */
    private function sendEmailNotification(array $buyer, array $leadData): void {
        $name = trim(($leadData['first_name'] ?? '') . ' ' . ($leadData['last_name'] ?? ''));
        $phone = $leadData['phone'] ?? '';
        $vertical = $leadData['vertical'] ?? 'New';
        $city = $leadData['city'] ?? '';
        $address = $leadData['address'] ?? '';

        $subject = "New {$vertical} Lead: {$name}";
        $body = "NEW LEAD RECEIVED\n";
        $body .= "==================\n\n";
        $body .= "Name: {$name}\n";
        $body .= "Phone: {$phone}\n";
        if (!empty($leadData['email'])) {
            $body .= "Email: {$leadData['email']}\n";
        }
        if ($address || $city) {
            $body .= "Location: {$address} {$city}\n";
        }
        if (!empty($leadData['notes'])) {
            $body .= "\nDetails:\n{$leadData['notes']}\n";
        }
        $body .= "\n---\n";
        $body .= "View all leads: https://ezlead4u.com/buyer/\n";

        $headers = "From: leads@ezlead4u.com\r\n";
        $headers .= "Reply-To: noreply@ezlead4u.com\r\n";

        $sent = @mail($buyer['email'], $subject, $body, $headers);
        if ($sent) {
            error_log("Email sent to {$buyer['email']}: {$subject}");
        } else {
            error_log("Email failed to {$buyer['email']}: {$subject}");
        }
    }

    /**
     * Send SMS notification via Twilio
     */
    private function sendSmsNotification(array $buyer, array $leadData): void {
        // Load Twilio config (optional - SMS disabled until credentials fixed)
        $twilioConfig = __DIR__ . '/../config/twilio.php';
        if (file_exists($twilioConfig) && is_readable($twilioConfig)) {
            @include_once $twilioConfig;
        }

        $name = trim(($leadData['first_name'] ?? '') . ' ' . ($leadData['last_name'] ?? ''));
        $customerPhone = $leadData['phone'] ?? '';
        $city = $leadData['city'] ?? '';
        $vertical = $leadData['vertical'] ?? 'lead';

        $message = "New {$vertical} lead: {$name}, {$customerPhone}";
        if ($city) $message .= ", {$city}";

        // Get Twilio credentials
        $accountSid = defined('TWILIO_ACCOUNT_SID') ? TWILIO_ACCOUNT_SID : '';
        $authToken = defined('TWILIO_AUTH_TOKEN') ? TWILIO_AUTH_TOKEN : '';
        $fromNumber = defined('TWILIO_PHONE_NUMBER') ? TWILIO_PHONE_NUMBER : '';

        if (empty($accountSid) || empty($authToken) || empty($fromNumber)) {
            error_log("SMS skipped - Twilio not configured");
            return;
        }

        $toNumber = '+1' . preg_replace('/[^0-9]/', '', $buyer['phone']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'From' => $fromNumber,
            'To' => $toNumber,
            'Body' => $message
        ]));
        curl_setopt($ch, CURLOPT_USERPWD, "{$accountSid}:{$authToken}");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("SMS sent to {$buyer['phone']}: {$message}");
        } else {
            error_log("SMS failed ({$httpCode}) to {$buyer['phone']}: {$result}");
        }
    }

    /**
     * Return a lead (refund buyer)
     */
    public function returnLead(int $buyerLeadId, string $reason): bool {
        // Get the lead
        $stmt = $this->db->prepare("SELECT * FROM buyer_leads WHERE id = :id AND status = 'delivered'");
        $stmt->bindValue(':id', $buyerLeadId);
        $lead = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$lead) {
            return false;
        }

        // Mark as returned
        $stmt = $this->db->prepare("
            UPDATE buyer_leads
            SET status = 'returned', returned_at = datetime('now'), return_reason = :reason
            WHERE id = :id
        ");
        $stmt->bindValue(':reason', $reason);
        $stmt->bindValue(':id', $buyerLeadId);
        $stmt->execute();

        // Refund buyer
        $buyerId = $lead['buyer_id'];
        $price = $lead['price'];

        // Get current balance
        $stmt = $this->db->prepare("SELECT balance FROM buyers WHERE id = :id");
        $stmt->bindValue(':id', $buyerId);
        $currentBalance = $stmt->execute()->fetchArray()['balance'] ?? 0;

        $newBalance = $currentBalance + $price;

        // Update balance
        $stmt = $this->db->prepare("UPDATE buyers SET balance = :balance, updated_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':balance', $newBalance);
        $stmt->bindValue(':id', $buyerId);
        $stmt->execute();

        // Record refund transaction
        $stmt = $this->db->prepare("
            INSERT INTO buyer_transactions (buyer_id, type, amount, balance_after, description, reference_id)
            VALUES (:buyer_id, 'refund', :amount, :balance_after, 'Lead returned: ' || :reason, :ref_id)
        ");
        $stmt->bindValue(':buyer_id', $buyerId);
        $stmt->bindValue(':amount', $price); // Positive for refund
        $stmt->bindValue(':balance_after', $newBalance);
        $stmt->bindValue(':reason', $reason);
        $stmt->bindValue(':ref_id', $buyerLeadId);
        $stmt->execute();

        return true;
    }

    /**
     * Reset daily counters (call from cron at midnight)
     */
    public function resetDailyCounters(): void {
        $this->db->exec("UPDATE buyer_campaigns SET leads_today = 0, updated_at = datetime('now')");
    }

    /**
     * Reset weekly counters (call from cron on Monday)
     */
    public function resetWeeklyCounters(): void {
        $this->db->exec("UPDATE buyer_campaigns SET leads_this_week = 0, updated_at = datetime('now')");
    }
}
