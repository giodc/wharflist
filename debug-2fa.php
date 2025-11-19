<?php
// 2FA Debug Helper
// This script helps debug TOTP issues by showing you what codes the server is generating

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/auth.php';

header('Content-Type: text/plain');

echo "=== 2FA Debug Information ===\n\n";

// Check server time
echo "Server Time: " . date('Y-m-d H:i:s T') . "\n";
echo "Unix Timestamp: " . time() . "\n";
echo "Time Slice (30s): " . floor(time() / 30) . "\n\n";

// Get user with 2FA enabled
$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, username, email, totp_secret, totp_enabled FROM users WHERE totp_enabled = 1 LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "No user with 2FA enabled found.\n";
    exit;
}

echo "User: {$user['username']} ({$user['email']})\n";
echo "TOTP Secret: {$user['totp_secret']}\n\n";

// Generate current codes
$auth = new Auth();

echo "=== Current Valid Codes ===\n";
$timeSlice = floor(time() / 30);
for ($i = -1; $i <= 1; $i++) {
    $reflection = new ReflectionClass($auth);
    $method = $reflection->getMethod('getTOTPCode');
    $method->setAccessible(true);
    $code = $method->invoke($auth, $user['totp_secret'], $timeSlice + $i);

    $label = $i == -1 ? 'Previous (30s ago)' : ($i == 0 ? 'Current' : 'Next (in 30s)');
    echo "$label: $code\n";
}

echo "\n=== Instructions ===\n";
echo "Try logging in with one of the codes above.\n";
echo "If none work, check:\n";
echo "1. Server time is synchronized (use 'sudo ntpdate -s time.apple.com' on Mac)\n";
echo "2. Your authenticator app is using the correct time\n";
echo "3. The TOTP secret matches what's in your authenticator app\n";
