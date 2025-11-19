<?php

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        try {
            $this->conn = new PDO('sqlite:' . DB_PATH);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            die("Connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function initDatabase() {
        $sql = "
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            totp_secret TEXT,
            totp_enabled INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            is_default INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS sites (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            domain TEXT NOT NULL,
            list_id INTEGER NOT NULL,
            api_key TEXT UNIQUE NOT NULL,
            custom_fields TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (list_id) REFERENCES lists(id)
        );

        CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            site_id INTEGER NOT NULL,
            verification_token TEXT,
            verified INTEGER DEFAULT 0,
            unsubscribed INTEGER DEFAULT 0,
            custom_data TEXT,
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            verified_at DATETIME,
            FOREIGN KEY (site_id) REFERENCES sites(id)
        );

        CREATE TABLE IF NOT EXISTS subscriber_lists (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subscriber_id INTEGER NOT NULL,
            list_id INTEGER NOT NULL,
            unsubscribed INTEGER DEFAULT 0,
            subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
            FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
            UNIQUE(subscriber_id, list_id)
        );

        CREATE TABLE IF NOT EXISTS queue_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            campaign_id INTEGER NOT NULL,
            list_ids TEXT,
            status TEXT DEFAULT 'pending',
            progress INTEGER DEFAULT 0,
            total INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME,
            completed_at DATETIME,
            FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id)
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            site_id INTEGER NOT NULL,
            attempts INTEGER DEFAULT 1,
            last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(ip_address, site_id)
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS email_campaigns (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            list_id INTEGER NOT NULL,
            subject TEXT NOT NULL,
            body TEXT NOT NULL,
            status TEXT DEFAULT 'sent',
            sent_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME,
            FOREIGN KEY (list_id) REFERENCES lists(id)
        );

        CREATE INDEX IF NOT EXISTS idx_subscribers_email ON subscribers(email);
        CREATE INDEX IF NOT EXISTS idx_subscribers_verified ON subscribers(verified);
        CREATE INDEX IF NOT EXISTS idx_subscribers_unsubscribed ON subscribers(unsubscribed);
        CREATE INDEX IF NOT EXISTS idx_subscriber_lists_subscriber ON subscriber_lists(subscriber_id);
        CREATE INDEX IF NOT EXISTS idx_subscriber_lists_list ON subscriber_lists(list_id);
        CREATE INDEX IF NOT EXISTS idx_subscriber_lists_unsub ON subscriber_lists(unsubscribed);
        CREATE INDEX IF NOT EXISTS idx_queue_jobs_status ON queue_jobs(status);
        CREATE INDEX IF NOT EXISTS idx_rate_limits_ip ON rate_limits(ip_address);
        ";

        $this->conn->exec($sql);
    }
}
