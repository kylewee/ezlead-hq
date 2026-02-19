<?php
/**
 * Buyer Portal - Database Initialization
 * Creates SQLite database for buyer management
 *
 * Stolen from LeadProsper.io (with love)
 */

$dbFile = __DIR__ . '/../data/buyers.db';
$db = new SQLite3($dbFile);

// Enable foreign keys
$db->exec('PRAGMA foreign_keys = ON');

// Buyers table - contractors who buy leads
$db->exec("
CREATE TABLE IF NOT EXISTS buyers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    name TEXT NOT NULL,
    company TEXT,
    phone TEXT,
    stripe_customer_id TEXT,
    balance INTEGER DEFAULT 0,         -- cents (prepaid balance)
    min_balance INTEGER DEFAULT 3500,  -- cents (auto-pause threshold, $35 minimum)
    price_per_lead INTEGER DEFAULT 3500, -- cents ($35 default per lead)
    free_leads_remaining INTEGER DEFAULT 3, -- 3 free test leads to start
    status TEXT DEFAULT 'active',      -- active, paused, suspended
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
)");

// Buyer leads - tracks which leads were sold to which buyers
$db->exec("
CREATE TABLE IF NOT EXISTS buyer_leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    buyer_id INTEGER NOT NULL,
    crm_lead_id INTEGER NOT NULL,      -- links to Rukovoditel entity 26
    site_domain TEXT,                  -- which site generated the lead
    lead_data TEXT,                    -- JSON snapshot of lead at time of sale
    price INTEGER NOT NULL,            -- cents charged for this lead
    status TEXT DEFAULT 'pending',     -- pending, delivered, returned, disputed
    delivery_method TEXT,              -- email, sms, api, portal
    delivered_at TEXT,
    returned_at TEXT,
    return_reason TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (buyer_id) REFERENCES buyers(id)
)");

// Transactions - payment history
$db->exec("
CREATE TABLE IF NOT EXISTS buyer_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    buyer_id INTEGER NOT NULL,
    type TEXT NOT NULL,                -- deposit, charge, refund, adjustment
    amount INTEGER NOT NULL,           -- cents (positive for deposits, negative for charges)
    balance_after INTEGER,             -- balance after this transaction
    stripe_payment_id TEXT,            -- Stripe payment intent or charge ID
    description TEXT,
    reference_id INTEGER,              -- buyer_leads.id if charge for a lead
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (buyer_id) REFERENCES buyers(id)
)");

// Campaigns - filter/routing rules for lead distribution
$db->exec("
CREATE TABLE IF NOT EXISTS buyer_campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    buyer_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    site_domain TEXT,                  -- which site(s) to get leads from (null = all)
    filters TEXT,                      -- JSON filter rules
    delivery_method TEXT DEFAULT 'portal', -- portal, email, sms, api
    delivery_config TEXT,              -- JSON (email address, phone, API endpoint)
    price_per_lead INTEGER,            -- override buyer default price
    max_per_day INTEGER,               -- daily cap
    max_per_week INTEGER,              -- weekly cap
    status TEXT DEFAULT 'active',      -- active, paused
    leads_today INTEGER DEFAULT 0,
    leads_this_week INTEGER DEFAULT 0,
    last_lead_at TEXT,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (buyer_id) REFERENCES buyers(id)
)");

// Sessions - for login management
$db->exec("
CREATE TABLE IF NOT EXISTS buyer_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    buyer_id INTEGER NOT NULL,
    token TEXT UNIQUE NOT NULL,
    ip_address TEXT,
    user_agent TEXT,
    expires_at TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (buyer_id) REFERENCES buyers(id)
)");

// Create indexes
$db->exec("CREATE INDEX IF NOT EXISTS idx_buyer_leads_buyer ON buyer_leads(buyer_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_buyer_leads_crm ON buyer_leads(crm_lead_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_buyer_leads_status ON buyer_leads(status)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_transactions_buyer ON buyer_transactions(buyer_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_campaigns_buyer ON buyer_campaigns(buyer_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_token ON buyer_sessions(token)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_sessions_buyer ON buyer_sessions(buyer_id)");

echo "Buyer portal database initialized: {$dbFile}\n";
echo "Tables created: buyers, buyer_leads, buyer_transactions, buyer_campaigns, buyer_sessions\n";

$db->close();
