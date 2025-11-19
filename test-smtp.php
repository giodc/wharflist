<?php
require_once 'config.php';
require_once 'database.php';
require_once 'auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testEmail = trim($_POST['test_email'] ?? '');
    
    if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        $testResult = ['success' => false, 'message' => 'Invalid email address'];
    } else {
        // Get SMTP settings
        $stmt = $db->query("SELECT key, value FROM settings");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
        
        if (empty($settings['smtp_host'])) {
            $testResult = ['success' => false, 'message' => 'SMTP not configured. Please configure in Settings.'];
        } else {
            try {
                require_once 'phpmailer.php';
                $mailer = new SimpleMailer(
                    $settings['smtp_host'],
                    $settings['smtp_port'],
                    $settings['smtp_user'],
                    $settings['smtp_pass']
                );
                
                $subject = "WharfList SMTP Test";
                $message = "
                <html>
                <body style='font-family: Arial, sans-serif; padding: 20px;'>
                    <h2>SMTP Test Successful!</h2>
                    <p>If you're reading this, your SMTP settings are working correctly.</p>
                    <hr>
                    <p><small>Sent from WharfList at " . date('Y-m-d H:i:s') . "</small></p>
                </body>
                </html>
                ";
                
                if ($mailer->send($settings['smtp_from'], $testEmail, $subject, $message)) {
                    $testResult = ['success' => true, 'message' => "Test email sent successfully to $testEmail"];
                } else {
                    $testResult = ['success' => false, 'message' => 'Failed to send email. Check SMTP settings.'];
                }
            } catch (Exception $e) {
                $testResult = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
            }
        }
    }
}

// Get current SMTP settings for display
$stmt = $db->query("SELECT key, value FROM settings WHERE key LIKE 'smtp_%'");
$smtpSettings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $smtpSettings[$row['key']] = $row['value'];
}

$user = $auth->getCurrentUser();
$pageTitle = 'SMTP Test';
?>
<?php include 'includes/header.php'; ?>

<h1 class="text-3xl font-bold text-gray-900 mb-6">SMTP Test</h1>

<?php if ($testResult): ?>
    <div class="<?= $testResult['success'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?> border px-4 py-3 rounded-lg mb-6">
        <p class="font-semibold"><?= $testResult['success'] ? '✓ Success' : '✗ Failed' ?></p>
        <p class="text-sm mt-1"><?= htmlspecialchars($testResult['message']) ?></p>
    </div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Test Form -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Send Test Email</h2>
        <form method="POST">
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Test Email Address</label>
                <input type="email" name="test_email" required 
                       placeholder="you@example.com"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Enter an email address where you can check for the test message</p>
            </div>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                Send Test Email
            </button>
        </form>
    </div>

    <!-- Current SMTP Settings -->
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Current SMTP Settings</h2>
        <dl class="space-y-2">
            <div>
                <dt class="text-xs font-medium text-gray-500">SMTP Host</dt>
                <dd class="text-sm text-gray-900"><?= htmlspecialchars($smtpSettings['smtp_host'] ?? 'Not set') ?></dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">SMTP Port</dt>
                <dd class="text-sm text-gray-900"><?= htmlspecialchars($smtpSettings['smtp_port'] ?? 'Not set') ?></dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">SMTP Username</dt>
                <dd class="text-sm text-gray-900"><?= htmlspecialchars($smtpSettings['smtp_user'] ?? 'Not set') ?></dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">From Email</dt>
                <dd class="text-sm text-gray-900"><?= htmlspecialchars($smtpSettings['smtp_from'] ?? 'Not set') ?></dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500">Password</dt>
                <dd class="text-sm text-gray-900"><?= !empty($smtpSettings['smtp_pass']) ? '••••••••' : 'Not set' ?></dd>
            </div>
        </dl>
        <a href="settings.php" class="inline-block mt-4 text-sm text-blue-600 hover:text-blue-700">
            → Configure SMTP Settings
        </a>
    </div>
</div>

<!-- Instructions -->
<div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-6">
    <h3 class="text-lg font-semibold text-blue-900 mb-3">📧 SMTP Configuration Tips</h3>
    <div class="text-sm text-blue-800 space-y-2">
        <p><strong>Gmail:</strong> Use smtp.gmail.com, port 587, and an App Password (not your regular password)</p>
        <p><strong>SendGrid:</strong> Use smtp.sendgrid.net, port 587, username "apikey", password is your API key</p>
        <p><strong>Mailgun:</strong> Use smtp.mailgun.org, port 587, with your Mailgun SMTP credentials</p>
        <p><strong>Postmark:</strong> Use smtp.postmarkapp.com, port 587, with your Server API Token</p>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
