<?php
/**
 * Migration: Add list_ids support for multi-list campaigns
 * Run this once: php migrate-multi-list.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';

echo "=== Multi-List Campaign Migration ===\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if column already exists
    $stmt = $db->query("PRAGMA table_info(queue_jobs)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $hasListIds = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'list_ids') {
            $hasListIds = true;
            break;
        }
    }
    
    if ($hasListIds) {
        echo "✓ Column 'list_ids' already exists in queue_jobs table\n";
    } else {
        echo "Adding 'list_ids' column to queue_jobs table...\n";
        $db->exec("ALTER TABLE queue_jobs ADD COLUMN list_ids TEXT");
        echo "✓ Column added successfully\n";
    }
    
    // Update existing jobs to use their campaign's list_id
    echo "\nUpdating existing queue jobs...\n";
    $stmt = $db->query("SELECT qj.id, ec.list_id 
                        FROM queue_jobs qj 
                        JOIN email_campaigns ec ON qj.campaign_id = ec.id 
                        WHERE qj.list_ids IS NULL");
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($jobs) > 0) {
        $updateStmt = $db->prepare("UPDATE queue_jobs SET list_ids = ? WHERE id = ?");
        foreach ($jobs as $job) {
            $updateStmt->execute([$job['list_id'], $job['id']]);
        }
        echo "✓ Updated " . count($jobs) . " existing job(s)\n";
    } else {
        echo "✓ No jobs to update\n";
    }
    
    echo "\n=== Migration completed successfully! ===\n";
    
} catch (Exception $e) {
    echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
