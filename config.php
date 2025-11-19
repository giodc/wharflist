<?php
// Error reporting - hide deprecation warnings in production
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '0');

// Session security configuration
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 1 : 0);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);

session_start();

// Determine if we're in /app/ or root
$isInApp = strpos(__DIR__, '/app') !== false;
$rootDir = $isInApp ? dirname(__DIR__) : __DIR__;

define('ROOT_PATH', $rootDir);
define('APP_PATH', $rootDir . '/app');
define('DB_PATH', APP_PATH . '/data/wharflist.db');
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Check if app is installed
function isInstalled()
{
    return file_exists(DB_PATH) && file_exists(ROOT_PATH . '/.installed');
}

// Redirect to setup if not installed (exclude public pages)
$publicPages = ['setup.php', 'api.php', 'widget.js', 'verify.php', 'unsubscribe.php'];
if (!isInstalled() && !in_array(basename($_SERVER['PHP_SELF']), $publicPages)) {
    header('Location: /setup.php');
    exit;
}

// Run auto-migrations after installation (only if needed)
if (isInstalled() && !in_array(basename($_SERVER['PHP_SELF']), $publicPages)) {
    require_once APP_PATH . '/database.php';
    require_once APP_PATH . '/migrations.php';
    try {
        $db = Database::getInstance()->getConnection();
        // Only run if migrations are needed (checks version, very fast)
        if (needsMigrations($db)) {
            runMigrations($db);
        }

        // Load timezone setting from database
        try {
            $stmt = $db->query("SELECT value FROM settings WHERE key = 'timezone' LIMIT 1");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($result && $result['value']) {
                date_default_timezone_set($result['value']);
            } else {
                // Fallback to UTC if not set
                date_default_timezone_set('UTC');
            }
        } catch (Exception $e) {
            // If settings table doesn't exist yet, use UTC
            date_default_timezone_set('UTC');
        }
    } catch (Exception $e) {
        error_log("Auto-migration error: " . $e->getMessage());
    }
} else {
    // For public pages, use UTC as default
    date_default_timezone_set('UTC');
}
