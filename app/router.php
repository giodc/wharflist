<?php
// Simple Router - Clean URLs without .htaccess
// Usage: router.php/campaign-status/123

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

// DEBUG: Show what we're receiving (remove in production)
if (isset($_GET['_debug'])) {
    echo "<pre>";
    echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NULL') . "\n";
    echo "PHP_SELF: " . ($_SERVER['PHP_SELF'] ?? 'NULL') . "\n";
    echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'NULL') . "\n";
    echo "PATH_INFO: " . ($_SERVER['PATH_INFO'] ?? 'NULL') . "\n";
    echo "</pre>";
    exit;
}

// Get the route from REQUEST_URI (more reliable with .htaccess)
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';

// Remove query string if present
$route = strtok($requestUri, '?');

// Remove leading slash and clean up
$route = trim($route, '/');

// If route starts with router.php, extract the path after it
if (strpos($route, 'router.php') === 0) {
    $route = substr($route, strlen('router.php'));
    $route = trim($route, '/');
}

// Parse route into parts
$parts = $route ? explode('/', $route) : [];
$page = !empty($parts[0]) ? $parts[0] : 'dashboard';
$id = $parts[1] ?? null;
$action = $parts[2] ?? null;

// Route to appropriate page
switch ($page) {
    case 'dashboard':
    case '':
        require 'index.php';
        break;
        
    case 'subscribers':
        require 'subscribers.php';
        break;
        
    case 'lists':
        require 'lists.php';
        break;
        
    case 'sites':
        require 'sites.php';
        break;
        
    case 'import':
        require 'import.php';
        break;
        
    case 'compose':
        require 'compose.php';
        break;
        
    case 'settings':
        require 'settings.php';
        break;
        
    case 'campaign-status':
        if ($id) {
            $_GET['job_id'] = $id;
        }
        require 'campaign-status.php';
        break;
        
    case 'verify':
        if ($id) {
            $_GET['token'] = $id;
        }
        require 'verify.php';
        break;
        
    case 'unsubscribe':
        // Keep query params for this one
        require 'unsubscribe.php';
        break;
        
    case 'logout':
        require 'logout.php';
        break;
        
    case 'trigger-worker':
        require 'trigger-worker.php';
        break;
        
    case 'debug-router':
        require 'debug-router.php';
        break;
        
    default:
        http_response_code(404);
        echo '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
    <script src="https://unpkg.com/tailwindcss-jit-cdn"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex items-center justify-center px-4">
        <div class="text-center">
            <h1 class="text-6xl font-bold text-gray-900 mb-4">404</h1>
            <p class="text-xl text-gray-600 mb-8">Page not found</p>
            <a href="/" class="inline-block px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                Go to Dashboard
            </a>
        </div>
    </div>
</body>
</html>';
        break;
}
