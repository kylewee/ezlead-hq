<?php
/**
 * QueueManager - Notification approval queue backed by SQLite.
 *
 * Incoming leads/estimates/calls land here as pending items.
 * Kyle reviews on the dispatch dashboard and taps Send/Hold/Spam.
 */

class QueueManager {
    private PDO $db;
    private array $config;

    public function __construct() {
        $this->config = require __DIR__ . '/../config.php';
        $dbPath = __DIR__ . '/../data/queue.db';
        $isNew = !file_exists($dbPath);

        $this->db = new PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');

        if ($isNew) {
            $this->initSchema();
        }
    }

    private function initSchema(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS queue_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,           -- lead, estimate, call
                status TEXT NOT NULL DEFAULT 'pending',  -- pending, approved, held, spam
                phone TEXT,
                name TEXT,
                site TEXT,                    -- mechanicstaugustine.com, sodjax.com, etc.
                summary TEXT,                 -- one-line preview
                data TEXT,                    -- JSON blob with full details
                action_taken TEXT,            -- what was done on approval
                created_at INTEGER NOT NULL,  -- unix timestamp
                acted_at INTEGER              -- unix timestamp when acted on
            );
            CREATE INDEX IF NOT EXISTS idx_queue_status ON queue_items(status);
            CREATE INDEX IF NOT EXISTS idx_queue_created ON queue_items(created_at DESC);
        ");
    }

    /**
     * Add a new item to the queue. Sends SMS + WebSocket notification.
     */
    public function add(string $type, ?string $phone, ?string $name, ?string $site, string $summary, array $data = []): int {
        $stmt = $this->db->prepare("
            INSERT INTO queue_items (type, phone, name, site, summary, data, created_at)
            VALUES (:type, :phone, :name, :site, :summary, :data, :created_at)
        ");
        $stmt->execute([
            ':type' => $type,
            ':phone' => $phone,
            ':name' => $name,
            ':site' => $site,
            ':summary' => $summary,
            ':data' => json_encode($data),
            ':created_at' => time(),
        ]);
        $id = (int)$this->db->lastInsertId();

        // Push to dispatch dashboard via WebSocket
        $this->notifyDashboard($id, $type, $phone, $name, $site, $summary);

        // Text Kyle
        $this->notifyKyle($id, $type, $name, $phone, $summary, $site);

        return $id;
    }

    /**
     * List queue items by status.
     */
    public function list(string $status = 'pending', int $limit = 50): array {
        $stmt = $this->db->prepare("
            SELECT * FROM queue_items WHERE status = :status
            ORDER BY created_at DESC LIMIT :limit
        ");
        $stmt->execute([':status' => $status, ':limit' => $limit]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['data'] = json_decode($row['data'], true) ?: [];
        }
        return $rows;
    }

    /**
     * Get a single item.
     */
    public function getById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM queue_items WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['data'] = json_decode($row['data'], true) ?: [];
        }
        return $row ?: null;
    }

    /**
     * Take action on a queue item.
     *
     * @param int    $id     Queue item ID
     * @param string $action One of: approve, hold, spam
     * @return array Result with 'success' and optional 'message'
     */
    public function act(int $id, string $action): array {
        $item = $this->getById($id);
        if (!$item) {
            return ['success' => false, 'error' => 'Item not found'];
        }
        if ($item['status'] !== 'pending' && $item['status'] !== 'held') {
            return ['success' => false, 'error' => 'Item already processed'];
        }

        $result = ['success' => true];

        switch ($action) {
            case 'approve':
                $result = $this->executeApproval($item);
                $this->updateStatus($id, 'approved', $result['action_taken'] ?? 'approved');
                break;

            case 'hold':
                $this->updateStatus($id, 'held', 'held_for_review');
                $result['message'] = 'Held for review';
                break;

            case 'spam':
                $this->updateStatus($id, 'spam', 'marked_spam');
                $result['message'] = 'Marked as spam';
                break;

            default:
                return ['success' => false, 'error' => 'Unknown action: ' . $action];
        }

        // Notify dashboard of the state change
        require_once __DIR__ . '/WSNotifier.php';
        WSNotifier::send('queue_update', [
            'id' => $id,
            'action' => $action,
            'status' => $action === 'approve' ? 'approved' : ($action === 'hold' ? 'held' : 'spam'),
        ]);

        return $result;
    }

    /**
     * Get counts by status.
     */
    public function stats(): array {
        $stmt = $this->db->query("
            SELECT status, COUNT(*) as count FROM queue_items
            GROUP BY status
        ");
        $stats = ['pending' => 0, 'approved' => 0, 'held' => 0, 'spam' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $stats[$row['status']] = (int)$row['count'];
        }
        return $stats;
    }

    // ---- Private helpers ----

    private function updateStatus(int $id, string $status, string $actionTaken): void {
        $stmt = $this->db->prepare("
            UPDATE queue_items SET status = :status, action_taken = :action, acted_at = :at
            WHERE id = :id
        ");
        $stmt->execute([
            ':status' => $status,
            ':action' => $actionTaken,
            ':at' => time(),
            ':id' => $id,
        ]);
    }

    /**
     * Execute the approval action based on item type.
     */
    private function executeApproval(array $item): array {
        switch ($item['type']) {
            case 'estimate':
                return $this->approveEstimate($item);
            case 'lead':
                return $this->approveLead($item);
            case 'call':
                return $this->approveCall($item);
            default:
                return ['success' => true, 'action_taken' => 'approved', 'message' => 'Approved'];
        }
    }

    /**
     * Approve an estimate: trigger the estimate send flow.
     * Sets estimate status to "Sent" in CRM so mechanic_automation picks it up.
     */
    private function approveEstimate(array $item): array {
        $data = $item['data'];
        $estimateId = $data['estimate_id'] ?? null;

        if ($estimateId) {
            // Update estimate status to Sent (206) in CRM
            require_once __DIR__ . '/DispatchCRM.php';
            $crm = new DispatchCRM();
            $params = [
                'username' => $this->config['crm']['username'],
                'password' => $this->config['crm']['password'],
                'key' => $this->config['crm']['api_key'],
                'action' => 'update',
                'entity_id' => 53,
                'update_by_field[id]' => $estimateId,
                'data[field_519]' => 206, // Sent
            ];

            $ch = curl_init($this->config['crm']['api_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);

            return [
                'success' => true,
                'action_taken' => 'estimate_sent',
                'message' => "Estimate #{$estimateId} marked for sending",
            ];
        }

        return ['success' => true, 'action_taken' => 'approved', 'message' => 'Approved (no estimate ID)'];
    }

    /**
     * Approve a lead: mark lead as active/qualified in CRM.
     */
    private function approveLead(array $item): array {
        $data = $item['data'];
        $leadId = $data['lead_id'] ?? null;

        if ($leadId) {
            // Update lead status to Active/Qualified
            $params = [
                'username' => $this->config['crm']['username'],
                'password' => $this->config['crm']['password'],
                'key' => $this->config['crm']['api_key'],
                'action' => 'update',
                'entity_id' => 25,
                'update_by_field[id]' => $leadId,
                'data[field_218]' => 78, // Active status
            ];

            $ch = curl_init($this->config['crm']['api_url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            curl_exec($ch);
            curl_close($ch);

            return [
                'success' => true,
                'action_taken' => 'lead_activated',
                'message' => "Lead #{$leadId} activated",
            ];
        }

        return ['success' => true, 'action_taken' => 'approved', 'message' => 'Approved'];
    }

    /**
     * Approve a call: mark for callback.
     */
    private function approveCall(array $item): array {
        // Send SMS to the customer acknowledging the call
        if ($item['phone']) {
            $sw = $this->config['signalwire'];
            $fromNumber = $sw['numbers']['mechanic_ported']; // use ported number

            // Determine from number based on site
            if ($item['site'] && stripos($item['site'], 'sod') !== false) {
                $fromNumber = $sw['numbers']['sod'];
            }

            $url = "https://{$sw['space']}/api/laml/2010-04-01/Accounts/{$sw['project_id']}/Messages.json";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'To' => $item['phone'],
                    'From' => $fromNumber,
                    'Body' => "Hi" . ($item['name'] ? " {$item['name']}" : "") . ", this is Ez Mobile Mechanic. We got your call and will get back to you shortly!",
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

        return [
            'success' => true,
            'action_taken' => 'callback_queued',
            'message' => 'Callback text sent to customer',
        ];
    }

    /**
     * Push a new queue item notification to the WebSocket server.
     */
    private function notifyDashboard(int $id, string $type, ?string $phone, ?string $name, ?string $site, string $summary): void {
        require_once __DIR__ . '/WSNotifier.php';
        WSNotifier::send('queue_new', [
            'id' => $id,
            'type' => $type,
            'phone' => $phone,
            'name' => $name,
            'site' => $site,
            'summary' => $summary,
        ]);
    }

    /**
     * Text Kyle about a new queue item.
     */
    private function notifyKyle(int $id, string $type, ?string $name, ?string $phone, string $summary, ?string $site): void {
        $sw = $this->config['signalwire'];
        $kylePhone = $sw['forward_to']; // +19046634789

        // Pick from number based on site
        $fromNumber = $sw['numbers']['mechanic_ported'];
        if ($site && stripos($site, 'sod') !== false) {
            $fromNumber = $sw['numbers']['sod'];
        }

        $typeLabel = ucfirst($type);
        $nameStr = $name ?: 'Unknown';
        $phoneStr = $phone ? " ({$phone})" : '';

        // Build compact message with direct link
        $link = "https://dispatch.ezlead4u.com/?token={$this->config['auth']['password']}#queue";
        $msg = "[{$typeLabel}] {$nameStr}{$phoneStr}\n{$summary}\n\n{$link}";

        $url = "https://{$sw['space']}/api/laml/2010-04-01/Accounts/{$sw['project_id']}/Messages.json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_USERPWD => $sw['project_id'] . ':' . $sw['token'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'To' => $kylePhone,
                'From' => $fromNumber,
                'Body' => $msg,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
