<?php
/**
 * Buyer Portal - Authentication
 * Handles login, sessions, password reset
 */

class BuyerAuth {
    private SQLite3 $db;
    private string $cookieName = 'buyer_session';
    private int $sessionLifetime = 86400 * 7; // 7 days

    public function __construct() {
        $dbFile = __DIR__ . '/../data/buyers.db';
        $this->db = new SQLite3($dbFile);
        $this->db->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Create a new buyer account
     */
    public function createBuyer(array $data): int|false {
        $stmt = $this->db->prepare("
            INSERT INTO buyers (email, password_hash, name, company, phone, price_per_lead)
            VALUES (:email, :password_hash, :name, :company, :phone, :price_per_lead)
        ");

        $stmt->bindValue(':email', strtolower(trim($data['email'])));
        $stmt->bindValue(':password_hash', password_hash($data['password'], PASSWORD_DEFAULT));
        $stmt->bindValue(':name', trim($data['name']));
        $stmt->bindValue(':company', trim($data['company'] ?? ''));
        $stmt->bindValue(':phone', trim($data['phone'] ?? ''));
        $stmt->bindValue(':price_per_lead', $data['price_per_lead'] ?? 2500);

        if ($stmt->execute()) {
            return $this->db->lastInsertRowID();
        }
        return false;
    }

    /**
     * Authenticate buyer by email/password
     */
    public function login(string $email, string $password): array|false {
        $stmt = $this->db->prepare("SELECT * FROM buyers WHERE email = :email AND status != 'suspended'");
        $stmt->bindValue(':email', strtolower(trim($email)));
        $result = $stmt->execute();
        $buyer = $result->fetchArray(SQLITE3_ASSOC);

        if (!$buyer || !password_verify($password, $buyer['password_hash'])) {
            return false;
        }

        // Create session
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + $this->sessionLifetime);

        $stmt = $this->db->prepare("
            INSERT INTO buyer_sessions (buyer_id, token, ip_address, user_agent, expires_at)
            VALUES (:buyer_id, :token, :ip, :ua, :expires)
        ");
        $stmt->bindValue(':buyer_id', $buyer['id']);
        $stmt->bindValue(':token', $token);
        $stmt->bindValue(':ip', $_SERVER['REMOTE_ADDR'] ?? '');
        $stmt->bindValue(':ua', $_SERVER['HTTP_USER_AGENT'] ?? '');
        $stmt->bindValue(':expires', $expires);
        $stmt->execute();

        // Set cookie
        setcookie($this->cookieName, $token, [
            'expires' => time() + $this->sessionLifetime,
            'path' => '/buyer/',
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax'
        ]);

        unset($buyer['password_hash']);
        return $buyer;
    }

    /**
     * Get current authenticated buyer from session
     */
    public function getCurrentBuyer(): array|null {
        $token = $_COOKIE[$this->cookieName] ?? null;
        if (!$token) return null;

        $stmt = $this->db->prepare("
            SELECT b.* FROM buyers b
            JOIN buyer_sessions s ON s.buyer_id = b.id
            WHERE s.token = :token AND s.expires_at > datetime('now')
            AND b.status != 'suspended'
        ");
        $stmt->bindValue(':token', $token);
        $result = $stmt->execute();
        $buyer = $result->fetchArray(SQLITE3_ASSOC);

        if ($buyer) {
            unset($buyer['password_hash']);
        }
        return $buyer ?: null;
    }

    /**
     * Require authentication - redirect to login if not authenticated
     */
    public function requireAuth(): array {
        $buyer = $this->getCurrentBuyer();
        if (!$buyer) {
            header('Location: /buyer/login.php');
            exit;
        }
        return $buyer;
    }

    /**
     * Logout - destroy session
     */
    public function logout(): void {
        $token = $_COOKIE[$this->cookieName] ?? null;
        if ($token) {
            $stmt = $this->db->prepare("DELETE FROM buyer_sessions WHERE token = :token");
            $stmt->bindValue(':token', $token);
            $stmt->execute();
        }

        setcookie($this->cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/buyer/',
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax'
        ]);
    }

    /**
     * Update buyer's balance
     */
    public function updateBalance(int $buyerId, int $amount, string $type, string $description = '', ?int $referenceId = null, ?string $stripePaymentId = null): bool {
        // Get current balance
        $stmt = $this->db->prepare("SELECT balance FROM buyers WHERE id = :id");
        $stmt->bindValue(':id', $buyerId);
        $currentBalance = $stmt->execute()->fetchArray()['balance'] ?? 0;

        $newBalance = $currentBalance + $amount;

        // Update balance
        $stmt = $this->db->prepare("UPDATE buyers SET balance = :balance, updated_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':balance', $newBalance);
        $stmt->bindValue(':id', $buyerId);
        $stmt->execute();

        // Record transaction
        $stmt = $this->db->prepare("
            INSERT INTO buyer_transactions (buyer_id, type, amount, balance_after, stripe_payment_id, description, reference_id)
            VALUES (:buyer_id, :type, :amount, :balance_after, :stripe_id, :description, :ref_id)
        ");
        $stmt->bindValue(':buyer_id', $buyerId);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':amount', $amount);
        $stmt->bindValue(':balance_after', $newBalance);
        $stmt->bindValue(':stripe_id', $stripePaymentId);
        $stmt->bindValue(':description', $description);
        $stmt->bindValue(':ref_id', $referenceId);

        return $stmt->execute() !== false;
    }

    /**
     * Get buyer by ID
     */
    public function getBuyer(int $id): array|null {
        $stmt = $this->db->prepare("SELECT * FROM buyers WHERE id = :id");
        $stmt->bindValue(':id', $id);
        $result = $stmt->execute();
        $buyer = $result->fetchArray(SQLITE3_ASSOC);
        if ($buyer) {
            unset($buyer['password_hash']);
        }
        return $buyer ?: null;
    }

    /**
     * Get all buyers (admin)
     */
    public function getAllBuyers(): array {
        $result = $this->db->query("SELECT id, email, name, company, phone, balance, status, created_at FROM buyers ORDER BY created_at DESC");
        $buyers = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $buyers[] = $row;
        }
        return $buyers;
    }

    /**
     * Clean expired sessions
     */
    public function cleanExpiredSessions(): int {
        $this->db->exec("DELETE FROM buyer_sessions WHERE expires_at < datetime('now')");
        return $this->db->changes();
    }

    public function getDb(): SQLite3 {
        return $this->db;
    }
}
