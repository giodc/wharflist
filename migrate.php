<?php
/**
 * Database Migration Script
 * Run this once if you're upgrading from an older version
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';

echo "Starting database migration...\n\n";

$db = Database::getInstance()->getConnection();

try {
    // Check current schema
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Current tables: " . implode(', ', $tables) . "\n\n";
    
    // Migration 1: Add unsubscribed column to subscribers if it doesn't exist
    echo "[1/5] Checking subscribers.unsubscribed column...\n";
    $columns = $db->query("PRAGMA table_info(subscribers)")->fetchAll(PDO::FETCH_ASSOC);
    $hasUnsubscribed = false;
    $hasListId = false;
    
    foreach ($columns as $col) {
        if ($col['name'] === 'unsubscribed') $hasUnsubscribed = true;
        if ($col['name'] === 'list_id') $hasListId = true;
    }
    
    if (!$hasUnsubscribed) {
        echo "  Adding unsubscribed column...\n";
        $db->exec("ALTER TABLE subscribers ADD COLUMN unsubscribed INTEGER DEFAULT 0");
        echo "  ✓ Added\n";
    } else {
        echo "  ✓ Already exists\n";
    }
    
    // Migration 2: Create subscriber_lists junction table
    echo "\n[2/5] Checking subscriber_lists table...\n";
    if (!in_array('subscriber_lists', $tables)) {
        echo "  Creating subscriber_lists table...\n";
        $db->exec("
            CREATE TABLE subscriber_lists (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subscriber_id INTEGER NOT NULL,
                list_id INTEGER NOT NULL,
                unsubscribed INTEGER DEFAULT 0,
                subscribed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (subscriber_id) REFERENCES subscribers(id) ON DELETE CASCADE,
                FOREIGN KEY (list_id) REFERENCES lists(id) ON DELETE CASCADE,
                UNIQUE(subscriber_id, list_id)
            )
        ");
        
        // Migrate existing data if list_id column exists
        if ($hasListId) {
            echo "  Migrating existing subscriber data to junction table...\n";
            $db->exec("
                INSERT INTO subscriber_lists (subscriber_id, list_id, subscribed_at)
                SELECT id, list_id, subscribed_at FROM subscribers
            ");
            echo "  ✓ Migrated existing data\n";
        }
        
        echo "  ✓ Created\n";
    } else {
        echo "  ✓ Already exists\n";
        
        // Check if unsubscribed column exists in subscriber_lists
        $slColumns = $db->query("PRAGMA table_info(subscriber_lists)")->fetchAll(PDO::FETCH_ASSOC);
        $slHasUnsub = false;
        foreach ($slColumns as $col) {
            if ($col['name'] === 'unsubscribed') $slHasUnsub = true;
        }
        
        if (!$slHasUnsub) {
            echo "  Adding unsubscribed column to subscriber_lists...\n";
            $db->exec("ALTER TABLE subscriber_lists ADD COLUMN unsubscribed INTEGER DEFAULT 0");
            echo "  ✓ Added\n";
        }
    }
    
    // Migration 3: Remove list_id from subscribers if it exists
    if ($hasListId) {
        echo "\n[3/5] Removing old list_id column from subscribers...\n";
        echo "  Creating new table without list_id...\n";
        
        $db->exec("
            BEGIN TRANSACTION;
            
            CREATE TABLE subscribers_new (
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
            
            INSERT INTO subscribers_new (id, email, site_id, verification_token, verified, unsubscribed, custom_data, subscribed_at, verified_at)
            SELECT id, email, site_id, verification_token, verified, 
                   COALESCE(unsubscribed, 0), custom_data, subscribed_at, verified_at
            FROM subscribers;
            
            DROP TABLE subscribers;
            ALTER TABLE subscribers_new RENAME TO subscribers;
            
            CREATE INDEX IF NOT EXISTS idx_subscribers_email ON subscribers(email);
            CREATE INDEX IF NOT EXISTS idx_subscribers_verified ON subscribers(verified);
            CREATE INDEX IF NOT EXISTS idx_subscribers_unsubscribed ON subscribers(unsubscribed);
            
            COMMIT;
        ");
        
        echo "  ✓ Removed list_id column\n";
    } else {
        echo "\n[3/5] list_id column already removed ✓\n";
    }
    
    // Migration 4: Create queue_jobs table
    echo "\n[4/5] Checking queue_jobs table...\n";
    if (!in_array('queue_jobs', $tables)) {
        echo "  Creating queue_jobs table...\n";
        $db->exec("
            CREATE TABLE queue_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                campaign_id INTEGER NOT NULL,
                status TEXT DEFAULT 'pending',
                progress INTEGER DEFAULT 0,
                total INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                completed_at DATETIME,
                FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id)
            )
        ");
        echo "  ✓ Created\n";
    } else {
        echo "  ✓ Already exists\n";
    }
    
    // Migration 5: Add status column to email_campaigns
    echo "\n[5/6] Checking email_campaigns.status column...\n";
    $ecColumns = $db->query("PRAGMA table_info(email_campaigns)")->fetchAll(PDO::FETCH_ASSOC);
    $hasStatus = false;
    foreach ($ecColumns as $col) {
        if ($col['name'] === 'status') $hasStatus = true;
    }
    
    if (!$hasStatus) {
        echo "  Adding status column...\n";
        $db->exec("ALTER TABLE email_campaigns ADD COLUMN status TEXT DEFAULT 'sent'");
        echo "  ✓ Added\n";
    } else {
        echo "  ✓ Already exists\n";
    }
    
    // Migration 6: Add indexes
    echo "\n[6/6] Creating indexes...\n";
    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_subscriber_lists_subscriber ON subscriber_lists(subscriber_id);
        CREATE INDEX IF NOT EXISTS idx_subscriber_lists_list ON subscriber_lists(list_id);
        CREATE INDEX IF NOT EXISTS idx_subscriber_lists_unsub ON subscriber_lists(unsubscribed);
        CREATE INDEX IF NOT EXISTS idx_queue_jobs_status ON queue_jobs(status);
    ");
    echo "  ✓ All indexes created\n";
    
    echo "\n✅ Migration completed successfully!\n";
    echo "\nYour database is now up to date.\n";
    
} catch (Exception $e) {
    echo "\n❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Please backup your database and contact support.\n";
    exit(1);
}
