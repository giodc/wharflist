<?php
// Debug Router - See what's being passed
?>
<!DOCTYPE html>
<html>
<head>
    <title>Router Debug</title>
    <script src="https://unpkg.com/tailwindcss-jit-cdn"></script>
</head>
<body class="bg-gray-50 p-8">
    <div class="max-w-4xl mx-auto bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-4">Router Debug Information</h1>
        
        <div class="space-y-4">
            <div>
                <strong>Current URL:</strong> 
                <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A') ?></code>
            </div>
            
            <div>
                <strong>PHP_SELF:</strong> 
                <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SERVER['PHP_SELF'] ?? 'N/A') ?></code>
            </div>
            
            <div>
                <strong>SCRIPT_NAME:</strong> 
                <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? 'N/A') ?></code>
            </div>
            
            <div>
                <strong>PATH_INFO:</strong> 
                <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SERVER['PATH_INFO'] ?? 'NOT SET') ?></code>
            </div>
            
            <div>
                <strong>QUERY_STRING:</strong> 
                <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SERVER['QUERY_STRING'] ?? 'N/A') ?></code>
            </div>
            
            <?php
            // Show what router would parse
            $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
            $route = strtok($requestUri, '?');
            $route = trim($route, '/');
            if (strpos($route, 'router.php') === 0) {
                $route = substr($route, strlen('router.php'));
                $route = trim($route, '/');
            }
            $parts = $route ? explode('/', $route) : [];
            $page = !empty($parts[0]) ? $parts[0] : 'dashboard';
            ?>
            
            <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded">
                <strong>Router would parse as:</strong><br>
                Route: <code class="bg-white px-2 py-1 rounded"><?= htmlspecialchars($route) ?></code><br>
                Page: <code class="bg-white px-2 py-1 rounded"><?= htmlspecialchars($page) ?></code>
            </div>
            
            <h2 class="text-xl font-bold mt-6 mb-2">Test Links:</h2>
            <div class="space-x-4">
                <a href="/settings/" class="text-blue-600 hover:underline">Settings</a>
                <a href="/subscribers/" class="text-blue-600 hover:underline">Subscribers</a>
                <a href="/lists/" class="text-blue-600 hover:underline">Lists</a>
            </div>
        </div>
    </div>
</body>
</html>
