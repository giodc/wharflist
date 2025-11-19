<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';

$db = Database::getInstance()->getConnection();

// Get logo settings
$stmt = $db->query("SELECT key, value FROM settings WHERE key IN ('email_logo', 'logo_position', 'logo_name')");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

$message = '';
$success = false;
$showConfirm = false;
$email = '';
$token = '';
$listId = null;
$listName = '';
$logoUrl = $settings['email_logo'] ?? '';
$logoName = $settings['logo_name'] ?? '';

// Handle unsubscribe confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && isset($_POST['token'])) {
    $email = $_POST['email'];
    $token = $_POST['token'];
    $listId = $_POST['list_id'] ?? null;
    
    // Verify token (simple hash of email + salt)
    $expectedToken = md5($email . 'unsubscribe_salt_2024');
    
    if ($token === $expectedToken) {
        if ($listId) {
            // List-specific unsubscribe - mark as unsubscribed in junction table
            $stmt = $db->prepare("UPDATE subscriber_lists SET unsubscribed = 1 
                                  WHERE subscriber_id = (SELECT id FROM subscribers WHERE email = ?) 
                                  AND list_id = ?");
            $stmt->execute([$email, $listId]);
            
            if ($stmt->rowCount() > 0) {
                $success = true;
                $message = "You have been unsubscribed from {$listName}.";
            } else {
                $message = 'Unable to unsubscribe. Please try again.';
            }
        } else {
            // Global unsubscribe - mark subscriber as unsubscribed
            $stmt = $db->prepare("UPDATE subscribers SET unsubscribed = 1 WHERE email = ?");
            if ($stmt->execute([$email])) {
                $success = true;
                $message = 'You have been successfully unsubscribed from all lists.';
            } else {
                $message = 'Error processing your request. Please try again.';
            }
        }
    } else {
        $message = 'Invalid unsubscribe link.';
    }
} elseif (isset($_GET['email']) && isset($_GET['token'])) {
    // Show confirmation page
    $email = $_GET['email'];
    $token = $_GET['token'];
    $listId = $_GET['list_id'] ?? null;
    
    // Verify token
    $expectedToken = md5($email . 'unsubscribe_salt_2024');
    
    if ($token === $expectedToken) {
        $showConfirm = true;
        
        // Get list name if list_id provided
        if ($listId) {
            $stmt = $db->prepare("SELECT name FROM lists WHERE id = ?");
            $stmt->execute([$listId]);
            $list = $stmt->fetch(PDO::FETCH_ASSOC);
            $listName = $list ? $list['name'] : 'this list';
        }
    } else {
        $message = 'Invalid unsubscribe link.';
    }
} else {
    $message = 'Invalid unsubscribe request.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unsubscribe - WharfList</title>
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
        
        <?php if ($showConfirm): ?>
            <!-- Confirmation Page -->
            <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Unsubscribe</h1>
            <p class="text-gray-600 mb-2">Are you sure you want to unsubscribe?</p>
            <p class="text-sm text-gray-500 mb-6">
                <strong><?= htmlspecialchars($email) ?></strong><br>
                <?php if ($listId && $listName): ?>
                    You will be removed from: <strong><?= htmlspecialchars($listName) ?></strong>
                <?php else: ?>
                    You will no longer receive emails from any mailing list.
                <?php endif; ?>
            </p>
            
            <form method="POST" class="space-y-3">
                <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>">
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <?php if ($listId): ?>
                <input type="hidden" name="list_id" value="<?= htmlspecialchars($listId) ?>">
                <?php endif; ?>
                <button type="submit" 
                        class="w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                    Confirm Unsubscribe
                </button>
                <a href="javascript:history.back()" 
                   class="block w-full px-6 py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium rounded-lg transition">
                    Cancel
                </a>
            </form>
        <?php elseif ($success): ?>
            <!-- Success Page -->
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Unsubscribed</h1>
            <p class="text-gray-600 mb-6"><?= htmlspecialchars($message) ?></p>
            <p class="text-sm text-gray-500">You will no longer receive emails from this mailing list.</p>
        <?php else: ?>
            <!-- Error Page -->
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </div>
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Error</h1>
            <p class="text-gray-600"><?= htmlspecialchars($message) ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
