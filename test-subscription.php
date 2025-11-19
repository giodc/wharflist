<?php
// Test subscription directly
require_once 'config.php';
require_once 'database.php';

$db = Database::getInstance()->getConnection();

echo "<h2>Subscriber Test</h2>";

// Check if subscribers table has data
$stmt = $db->query("SELECT COUNT(*) as total FROM subscribers");
$count = $stmt->fetch(PDO::FETCH_ASSOC);
echo "<p><strong>Total subscribers in database:</strong> {$count['total']}</p>";

// Show recent subscribers
$stmt = $db->query("SELECT * FROM subscribers ORDER BY id DESC LIMIT 10");
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Recent Subscribers:</h3>";
if (empty($subscribers)) {
    echo "<p><em>No subscribers found in database</em></p>";
} else {
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>ID</th><th>Email</th><th>List ID</th><th>Site ID</th><th>Verified</th><th>Subscribed At</th></tr>";
    foreach ($subscribers as $sub) {
        $verified = $sub['verified'] ? 'Yes' : 'No';
        echo "<tr>";
        echo "<td>{$sub['id']}</td>";
        echo "<td>{$sub['email']}</td>";
        echo "<td>{$sub['list_id']}</td>";
        echo "<td>{$sub['site_id']}</td>";
        echo "<td>{$verified}</td>";
        echo "<td>{$sub['subscribed_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// Show sites for reference
$stmt = $db->query("SELECT * FROM sites");
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Sites (for reference):</h3>";
echo "<table border='1' cellpadding='10'>";
echo "<tr><th>ID</th><th>Name</th><th>Domain</th><th>List ID</th><th>API Key</th></tr>";
foreach ($sites as $site) {
    echo "<tr>";
    echo "<td>{$site['id']}</td>";
    echo "<td>{$site['name']}</td>";
    echo "<td>{$site['domain']}</td>";
    echo "<td>{$site['list_id']}</td>";
    echo "<td>" . substr($site['api_key'], 0, 20) . "...</td>";
    echo "</tr>";
}
echo "</table>";

// Check error log
echo "<h3>Recent Error Logs (if available):</h3>";
$logFile = __DIR__ . '/error_log';
if (file_exists($logFile)) {
    $logs = file($logFile);
    $recentLogs = array_slice($logs, -20);
    echo "<pre style='background:#f4f4f4;padding:10px;'>";
    echo htmlspecialchars(implode('', $recentLogs));
    echo "</pre>";
} else {
    echo "<p><em>No error log found at: $logFile</em></p>";
    echo "<p>Check your PHP error_log location: " . ini_get('error_log') . "</p>";
}

echo "<hr>";
echo "<h3>Test API Call:</h3>";
echo "<p>You can test the API using browser console:</p>";
echo "<pre style='background:#f4f4f4;padding:10px;'>";
echo "fetch('" . BASE_URL . "/api.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
        api_key: 'YOUR_API_KEY_HERE',
        email: 'test@example.com',
        _t: Date.now(),
        _hp: ''
    })
}).then(r => r.json()).then(console.log);";
echo "</pre>";
?>
