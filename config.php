<?php
// Error reporting - hide deprecation warnings in production
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();

// Determine if we're in /app/ or root
$isInApp = strpos(__DIR__, '/app') !== false;
$rootDir = $isInApp ? dirname(__DIR__) : __DIR__;

define('ROOT_PATH', $rootDir);
define('APP_PATH', $rootDir . '/app');
define('DB_PATH', APP_PATH . '/data/wharflist.db');
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Check if app is installed
function isInstalled() {
    return file_exists(DB_PATH) && file_exists(ROOT_PATH . '/.installed');
}

// Redirect to setup if not installed (exclude public pages)
$publicPages = ['setup.php', 'api.php', 'widget.js', 'verify.php', 'unsubscribe.php'];
if (!isInstalled() && !in_array(basename($_SERVER['PHP_SELF']), $publicPages)) {
    header('Location: /setup.php');
    exit;
}
