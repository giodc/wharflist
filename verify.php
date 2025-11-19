<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';

$token = $_GET['token'] ?? '';
$message = '';
$success = false;

$db = Database::getInstance()->getConnection();

// Get logo settings
$stmt = $db->query("SELECT key, value FROM settings WHERE key IN ('email_logo', 'logo_position', 'logo_name')");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

if (empty($token)) {
    $message = 'Invalid verification link';
} else {
    $stmt = $db->prepare("SELECT * FROM subscribers WHERE verification_token = ? AND verified = 0");
    $stmt->execute([$token]);
    $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($subscriber) {
        $stmt = $db->prepare("UPDATE subscribers SET verified = 1, verified_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$subscriber['id']])) {
            $success = true;
            $message = 'Your email has been verified successfully!';
        } else {
            $message = 'Verification failed. Please try again.';
        }
    } else {
        $message = 'Invalid or expired verification link';
    }
}

$logoUrl = $settings['email_logo'] ?? '';
$logoName = $settings['logo_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Verification</title>
    <script src="https://unpkg.com/tailwindcss-jit-cdn"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8 text-center">
        <?php if (!empty($logoUrl) || !empty($logoName)): ?>
            <div class="mb-6">
                <?php if (!empty($logoUrl)): ?>
                    <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Logo" class="max-w-[200px] h-auto mx-auto mb-2">
                <?php endif; ?>
                <?php if (!empty($logoName)): ?>
                    <div class="text-xl font-bold text-gray-900"><?= htmlspecialchars($logoName) ?></div>
                <?php endif; ?>
            </div>
            <hr class="my-6 border-gray-200">
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Success!</h1>
            <p class="text-gray-600 mb-6"><?= htmlspecialchars($message) ?></p>
            <p class="text-sm text-gray-500">You can now close this window.</p>
        <?php else: ?>
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Verification Failed</h1>
            <p class="text-gray-600"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
