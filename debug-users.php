<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';

$db = Database::getInstance()->getConnection();
$stmt = $db->query("SELECT id, username, email, totp_enabled, LENGTH(totp_secret) as secret_len FROM users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "=== Users 2FA Status ===\n";
foreach ($users as $user) {
    echo "ID: {$user['id']} | User: {$user['username']} | 2FA: {$user['totp_enabled']} | Secret Len: {$user['secret_len']}\n";
}

echo "\n=== Session Info ===\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
print_r($_SESSION);
