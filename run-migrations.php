<?php
/**
 * Manual Migration Runner
 * Run this to manually apply migrations: php run-migrations.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/migrations.php';

echo "=== Running Migrations ===\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    $currentVersion = getMigrationsVersion($db);
    echo "Current migration version: $currentVersion\n\n";
    
    $migrationsRun = runMigrations($db, isset($argv[1]) && $argv[1] === '--force');
    
    if ($migrationsRun > 0) {
        echo "\n✓ Applied $migrationsRun migration(s)\n";
    } else {
        echo "✓ No migrations needed - database is up to date\n";
    }
    
    $newVersion = getMigrationsVersion($db);
    echo "New migration version: $newVersion\n";
    
    echo "\n=== Migrations complete ===\n";
    
} catch (Exception $e) {
    echo "\n✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
