<?php
/**
 * Auto-Migration System
 * Checks and applies database migrations automatically
 */

function getMigrationsVersion($db)
{
    try {
        $stmt = $db->query("SELECT value FROM settings WHERE key = 'migrations_version'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? (int) $result['value'] : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function setMigrationsVersion($db, $version)
{
    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('migrations_version', ?)");
        $stmt->execute([$version]);
    } catch (Exception $e) {
        error_log("Could not save migrations version: " . $e->getMessage());
    }
}

function runMigrations($db, $force = false)
{
    // Check current version
    $currentVersion = getMigrationsVersion($db);
    $latestVersion = 3; // Increment this when adding new migrations

    // Skip if already at latest version (unless forced)
    if (!$force && $currentVersion >= $latestVersion) {
        return 0;
    }
    $migrations = [
        'add_list_ids_to_email_campaigns' => function ($db) {
            // Check if column already exists
            $stmt = $db->query("PRAGMA table_info(email_campaigns)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasListIds = false;

            foreach ($columns as $column) {
                if ($column['name'] === 'list_ids') {
                    $hasListIds = true;
                    break;
                }
            }

            if (!$hasListIds) {
                $db->exec("ALTER TABLE email_campaigns ADD COLUMN list_ids TEXT");
                error_log("Migration: Added list_ids column to email_campaigns");

                // Backfill existing campaigns
                $db->exec("UPDATE email_campaigns SET list_ids = list_id WHERE list_ids IS NULL");
                error_log("Migration: Backfilled list_ids for email_campaigns");
                return true;
            }
            return false;
        },
        'add_list_ids_to_queue_jobs' => function ($db) {
            // Check if column already exists
            $stmt = $db->query("PRAGMA table_info(queue_jobs)");
            $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $hasListIds = false;
            $hasStartedAt = false;

            foreach ($columns as $column) {
                if ($column['name'] === 'list_ids') {
                    $hasListIds = true;
                }
                if ($column['name'] === 'started_at') {
                    $hasStartedAt = true;
                }
            }

            $applied = false;

            if (!$hasListIds) {
                $db->exec("ALTER TABLE queue_jobs ADD COLUMN list_ids TEXT");
                error_log("Migration: Added list_ids column to queue_jobs");
                $applied = true;
            }

            if (!$hasStartedAt) {
                $db->exec("ALTER TABLE queue_jobs ADD COLUMN started_at DATETIME");
                error_log("Migration: Added started_at column to queue_jobs");
                $applied = true;
            }

            // Update existing jobs to use their campaign's list_id
            if ($applied) {
                try {
                    $stmt = $db->query("SELECT qj.id, ec.list_id 
                                        FROM queue_jobs qj 
                                        JOIN email_campaigns ec ON qj.campaign_id = ec.id 
                                        WHERE qj.list_ids IS NULL OR qj.list_ids = ''");
                    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    if (count($jobs) > 0) {
                        $updateStmt = $db->prepare("UPDATE queue_jobs SET list_ids = ? WHERE id = ?");
                        foreach ($jobs as $job) {
                            $updateStmt->execute([$job['list_id'], $job['id']]);
                        }
                        error_log("Migration: Updated " . count($jobs) . " existing queue jobs with list_ids");
                    }
                } catch (Exception $e) {
                    error_log("Migration warning: Could not backfill list_ids - " . $e->getMessage());
                }
            }

            return $applied;
        },
        'create_backup_codes_table' => function ($db) {
            $db->exec("
                CREATE TABLE IF NOT EXISTS backup_codes (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL,
                    code TEXT NOT NULL,
                    used INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                );
                CREATE INDEX IF NOT EXISTS idx_backup_codes_user ON backup_codes(user_id);
            ");
            error_log("Migration: Created backup_codes table");
            return true;
        }
    ];

    $migrationsRun = 0;
    foreach ($migrations as $name => $migration) {
        try {
            if ($migration($db)) {
                $migrationsRun++;
            }
        } catch (Exception $e) {
            error_log("Migration '$name' failed: " . $e->getMessage());
        }
    }

    if ($migrationsRun > 0) {
        error_log("Auto-migrations: Applied $migrationsRun migration(s)");
        // Update version to latest
        setMigrationsVersion($db, $latestVersion);
    } elseif ($currentVersion < $latestVersion) {
        // No migrations run but version updated (columns already existed)
        setMigrationsVersion($db, $latestVersion);
    }

    return $migrationsRun;
}

// Check if migrations are needed (quick version check)
function needsMigrations($db)
{
    $currentVersion = getMigrationsVersion($db);
    return $currentVersion < 3; // Update this when adding new migrations
}
