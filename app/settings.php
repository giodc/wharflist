<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$currentUser = $auth->getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'general') {
        $timezone = $_POST['timezone'] ?? 'UTC';
        $siteName = trim($_POST['site_name'] ?? 'WharfList');
        $siteUrl = trim($_POST['site_url'] ?? '');
        $emailLogo = trim($_POST['email_logo'] ?? '');
        $logoPosition = $_POST['logo_position'] ?? 'center';
        $logoName = trim($_POST['logo_name'] ?? '');
        $footerCompanyName = trim($_POST['footer_company_name'] ?? '');
        $footerAddress = trim($_POST['footer_address'] ?? '');
        $footerEmail = trim($_POST['footer_email'] ?? '');
        $footerPhone = trim($_POST['footer_phone'] ?? '');
        $footerWebsiteUrl = trim($_POST['footer_website_url'] ?? '');
        $footerPrivacyUrl = trim($_POST['footer_privacy_url'] ?? '');
        $footerText = trim($_POST['footer_text'] ?? '');

        // Use INSERT OR REPLACE to handle new settings
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['timezone', $timezone]);
        $stmt->execute(['site_name', $siteName]);
        $stmt->execute(['site_url', $siteUrl]);
        $stmt->execute(['email_logo', $emailLogo]);
        $stmt->execute(['logo_position', $logoPosition]);
        $stmt->execute(['logo_name', $logoName]);
        $stmt->execute(['footer_company_name', $footerCompanyName]);
        $stmt->execute(['footer_address', $footerAddress]);
        $stmt->execute(['footer_email', $footerEmail]);
        $stmt->execute(['footer_phone', $footerPhone]);
        $stmt->execute(['footer_website_url', $footerWebsiteUrl]);
        $stmt->execute(['footer_privacy_url', $footerPrivacyUrl]);
        $stmt->execute(['footer_text', $footerText]);

        $success = 'General settings updated';
    } elseif ($action === 'smtp') {
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = trim($_POST['smtp_port'] ?? '587');
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $smtpPass = $_POST['smtp_pass'] ?? '';
        $smtpFrom = trim($_POST['smtp_from'] ?? '');
        $emailsPerBatch = (int) ($_POST['emails_per_batch'] ?? 50);
        $delayBetweenEmails = (float) ($_POST['delay_between_emails'] ?? 0);

        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute(['smtp_host', $smtpHost]);
        $stmt->execute(['smtp_port', $smtpPort]);
        $stmt->execute(['smtp_user', $smtpUser]);
        if (!empty($smtpPass)) {
            $stmt->execute(['smtp_pass', $smtpPass]);
        }
        $stmt->execute(['smtp_from', $smtpFrom]);
        $stmt->execute(['emails_per_batch', $emailsPerBatch]);
        $stmt->execute(['delay_between_emails', $delayBetweenEmails]);

        $success = 'SMTP settings updated';
    } elseif ($action === 'test_smtp') {
        $smtpHost = trim($_POST['smtp_host'] ?? '');
        $smtpPort = trim($_POST['smtp_port'] ?? '587');
        $smtpUser = trim($_POST['smtp_user'] ?? '');
        $smtpPass = $_POST['smtp_pass'] ?? '';
        $smtpFrom = trim($_POST['smtp_from'] ?? '');
        $testEmail = trim($_POST['test_email'] ?? '');
        
        // If password is empty, try to get it from DB (for existing configuration)
        if (empty($smtpPass)) {
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'smtp_pass'");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $smtpPass = $result['value'] ?? '';
        }

        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please provide a valid test email address';
        } elseif (empty($smtpHost)) {
            $error = 'SMTP Host is required';
        } else {
            require_once __DIR__ . '/phpmailer.php';
            
            try {
                $mailer = new SimpleMailer($smtpHost, $smtpPort, $smtpUser, $smtpPass);
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
                
                if ($mailer->send($smtpFrom, $testEmail, $subject, $message)) {
                    $success = "Test email sent successfully to $testEmail";
                } else {
                    $error = "Failed to send test email. Check your settings and try again.";
                }
            } catch (Exception $e) {
                $error = "SMTP Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($currentPassword) || empty($newPassword)) {
            $error = 'All fields are required';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Passwords do not match';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters';
        } else {
            if ($auth->changePassword($currentUser['id'], $currentPassword, $newPassword)) {
                $success = 'Password changed successfully';
            } else {
                $error = 'Current password is incorrect';
            }
        }
    } elseif ($action === 'enable_2fa') {
        $secret = $auth->generateTOTPSecret();
        $_SESSION['temp_totp_secret'] = $secret;
        $success = 'Scan the QR code with your authenticator app';
    } elseif ($action === 'verify_2fa') {
        $code = $_POST['totp_code'] ?? '';
        $secret = $_SESSION['temp_totp_secret'] ?? '';

        if (empty($secret)) {
            $error = 'Please generate 2FA setup first';
        } elseif ($auth->verifyTOTP($secret, $code)) {
            $auth->enableTOTP($currentUser['id'], $secret);
            // Generate and save backup codes
            $backupCodes = $auth->regenerateBackupCodes($currentUser['id']);
            $_SESSION['new_backup_codes'] = $backupCodes;

            unset($_SESSION['temp_totp_secret']);
            $success = '2FA enabled successfully. Please save your backup codes.';
            $currentUser['totp_enabled'] = 1;
        } else {
            $error = 'Invalid verification code';
        }
    } elseif ($action === 'disable_2fa') {
        $auth->disableTOTP($currentUser['id']);
        // Also clear backup codes
        $stmt = $db->prepare("DELETE FROM backup_codes WHERE user_id = ?");
        $stmt->execute([$currentUser['id']]);

        $success = '2FA disabled';
        $currentUser['totp_enabled'] = 0;
    } elseif ($action === 'regenerate_backup_codes') {
        $password = $_POST['password'] ?? '';
        // Verify password first
        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$currentUser['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (password_verify($password, $user['password'])) {
            $backupCodes = $auth->regenerateBackupCodes($currentUser['id']);
            $_SESSION['new_backup_codes'] = $backupCodes;
            $success = 'New backup codes generated';
        } else {
            $error = 'Incorrect password';
        }
    } elseif ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $require2fa = isset($_POST['require_2fa']);

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'All fields are required';
        } elseif ($password !== $confirmPassword) {
            $error = 'Passwords do not match';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } else {
            try {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, email, password, created_at) VALUES (?, ?, ?, CURRENT_TIMESTAMP)");
                $stmt->execute([$username, $email, $hashedPassword]);
                $success = 'User created successfully';
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'UNIQUE constraint failed') !== false) {
                    $error = 'Username or email already exists';
                } else {
                    $error = 'Failed to create user: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'delete_user') {
        $userId = $_POST['user_id'] ?? 0;
        if ($userId == $currentUser['id']) {
            $error = 'You cannot delete your own account';
        } else {
            $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $success = 'User deleted successfully';
        }
    } elseif ($action === 'reset_user_2fa') {
        $userId = $_POST['user_id'] ?? 0;
        $auth->disableTOTP($userId);
        $stmt = $db->prepare("DELETE FROM backup_codes WHERE user_id = ?");
        $stmt->execute([$userId]);
        $success = 'User 2FA reset successfully';
    } elseif ($action === 'cron') {
        // Generate or regenerate cron key
        $cronKey = bin2hex(random_bytes(32));
        $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('cron_secret', ?)");
        $stmt->execute([$cronKey]);
        $success = 'Cron secret key generated';
        $activeTab = 'cron';
    }
}

// Get all users for management tab
$stmt = $db->query("SELECT id, username, email, totp_enabled, created_at FROM users ORDER BY created_at DESC");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current settings
$stmt = $db->query("SELECT key, value FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

// If we just tested SMTP, override settings with the posted values
// so the form fields don't revert to the database values
if (isset($_POST['action']) && $_POST['action'] === 'test_smtp') {
    $settings['smtp_host'] = $_POST['smtp_host'] ?? $settings['smtp_host'];
    $settings['smtp_port'] = $_POST['smtp_port'] ?? $settings['smtp_port'];
    $settings['smtp_user'] = $_POST['smtp_user'] ?? $settings['smtp_user'];
    $settings['smtp_from'] = $_POST['smtp_from'] ?? $settings['smtp_from'];
    $settings['emails_per_batch'] = $_POST['emails_per_batch'] ?? $settings['emails_per_batch'];
    $settings['delay_between_emails'] = $_POST['delay_between_emails'] ?? $settings['delay_between_emails'];
}

// Determine active tab based on action
$activeTab = 'general';
if (isset($_POST['action'])) {
    $act = $_POST['action'];
    if (in_array($act, ['smtp', 'test_smtp'])) {
        $activeTab = 'smtp';
    } elseif (in_array($act, ['password', 'enable_2fa', 'verify_2fa', 'disable_2fa', 'regenerate_backup_codes'])) {
        $activeTab = 'security';
    } elseif (in_array($act, ['add_user', 'delete_user', 'reset_user_2fa'])) {
        $activeTab = 'users';
    }
}

$pageTitle = 'Settings';
$additionalHead = '<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div x-data="{ activeTab: '<?= $activeTab ?>' }">
    <h1 class="text-3xl font-bold text-gray-900 mb-6">Settings</h1>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4">
            <p><?= htmlspecialchars($error) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4">
            <p><?= htmlspecialchars($success) ?></p>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="border-b border-gray-200 mb-6">
        <nav class="-mb-px flex space-x-8">
            <button @click="activeTab = 'general'"
                :class="activeTab === 'general' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="py-4 px-1 border-b-2 font-medium text-sm transition">
                General
            </button>
            <button @click="activeTab = 'smtp'"
                :class="activeTab === 'smtp' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="py-4 px-1 border-b-2 font-medium text-sm transition">
                SMTP
            </button>
            <button @click="activeTab = 'security'"
                :class="activeTab === 'security' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="py-4 px-1 border-b-2 font-medium text-sm transition">
                Security
            </button>
            <button @click="activeTab = 'cron'"
                :class="activeTab === 'cron' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="py-4 px-1 border-b-2 font-medium text-sm transition">
                Cron Jobs
            </button>
            <button @click="activeTab = 'users'"
                :class="activeTab === 'users' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
                class="py-4 px-1 border-b-2 font-medium text-sm transition">
                Users
            </button>
        </nav>
    </div>
    <!-- General Settings -->
    <div x-show="activeTab === 'general'" class="bg-white rounded-lg shadow p-6">
        <form method="POST">
            <input type="hidden" name="action" value="general">

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Site Name</label>
                <input type="text" name="site_name"
                    value="<?= htmlspecialchars($settings['site_name'] ?? 'WharfList') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Site URL</label>
                <input type="url" name="site_url"
                    value="<?= htmlspecialchars($settings['site_url'] ?? '') ?>"
                    placeholder="<?= htmlspecialchars(defined('BASE_URL') ? BASE_URL : 'https://example.com') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Base URL for the application. Leave blank to auto-detect (currently: <?= htmlspecialchars(defined('BASE_URL') ? BASE_URL : 'Unknown') ?>)</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Timezone</label>
                <select name="timezone"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <?php
                    $currentTimezone = $settings['timezone'] ?? 'UTC';
                    $timezones = DateTimeZone::listIdentifiers();
                    foreach ($timezones as $tz) {
                        $selected = $tz === $currentTimezone ? 'selected' : '';
                        echo "<option value=\"$tz\" $selected>$tz</option>";
                    }
                    ?>
                </select>
            </div>

            <hr class="my-6 border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Email Branding</h3>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Email Logo URL</label>
                <input type="url" name="email_logo" value="<?= htmlspecialchars($settings['email_logo'] ?? '') ?>"
                    placeholder="https://example.com/logo.png"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Direct URL to your logo image (recommended: 200px wide or less)
                </p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Logo Position and Footer Alignment</label>
                <select name="logo_position"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="left" <?= ($settings['logo_position'] ?? 'center') === 'left' ? 'selected' : '' ?>>Left
                    </option>
                    <option value="center" <?= ($settings['logo_position'] ?? 'center') === 'center' ? 'selected' : '' ?>>
                        Center</option>
                    <option value="right" <?= ($settings['logo_position'] ?? 'center') === 'right' ? 'selected' : '' ?>>
                        Right</option>
                </select>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Company/Brand Name</label>
                <input type="text" name="logo_name" value="<?= htmlspecialchars($settings['logo_name'] ?? '') ?>"
                    placeholder="Your Company Name"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Displayed below the logo in email templates</p>
            </div>

            <hr class="my-6 border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Email Footer (Compliance)</h3>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Company Name ⭐</label>
                <input type="text" name="footer_company_name"
                    value="<?= htmlspecialchars($settings['footer_company_name'] ?? '') ?>"
                    placeholder="Your Company LLC"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Physical Address ⭐</label>
                <input type="text" name="footer_address"
                    value="<?= htmlspecialchars($settings['footer_address'] ?? '') ?>"
                    placeholder="123 Main St, Suite 100, City, State 12345"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Required by CAN-SPAM Act</p>
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Contact Email</label>
                    <input type="email" name="footer_email"
                        value="<?= htmlspecialchars($settings['footer_email'] ?? '') ?>"
                        placeholder="support@example.com"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                    <input type="text" name="footer_phone"
                        value="<?= htmlspecialchars($settings['footer_phone'] ?? '') ?>" placeholder="(555) 123-4567"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Company Website URL</label>
                <input type="url" name="footer_website_url"
                    value="<?= htmlspecialchars($settings['footer_website_url'] ?? '') ?>"
                    placeholder="https://example.com"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Privacy Policy URL</label>
                <input type="url" name="footer_privacy_url"
                    value="<?= htmlspecialchars($settings['footer_privacy_url'] ?? '') ?>"
                    placeholder="https://example.com/privacy"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Footer Message</label>
                <textarea name="footer_text" rows="2"
                    placeholder="You're receiving this email because you subscribed to our newsletter."
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?= htmlspecialchars($settings['footer_text'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Short message explaining why they're receiving this email</p>
            </div>

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Save
                Changes</button>
        </form>
    </div>

    <!-- SMTP Settings -->
    <div x-show="activeTab === 'smtp'" class="bg-white rounded-lg shadow p-6" style="display: none;">
        <form method="POST">
            <input type="hidden" name="action" value="smtp">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Host</label>
                <input type="text" name="smtp_host" value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Port</label>
                <input type="number" name="smtp_port" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Username</label>
                <input type="text" name="smtp_user" value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Password</label>
                <input type="password" name="smtp_pass" placeholder="Leave blank to keep current"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">From Email</label>
                <input type="email" name="smtp_from" value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <hr class="my-6 border-gray-200">
            <h4 class="text-md font-semibold text-gray-900 mb-4">Sending Speed Control</h4>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Emails Per Batch</label>
                <input type="number" name="emails_per_batch" min="1" max="1000"
                    value="<?= htmlspecialchars($settings['emails_per_batch'] ?? '50') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Number of emails to send in each batch (default: 50)</p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Delay Between Emails (seconds)</label>
                <input type="number" name="delay_between_emails" min="0" max="10" step="0.1"
                    value="<?= htmlspecialchars($settings['delay_between_emails'] ?? '0') ?>"
                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Delay in seconds between each email (0 = no delay, helps avoid
                    rate limits)</p>
            </div>

            <hr class="my-6 border-gray-200">
            <h4 class="text-md font-semibold text-gray-900 mb-4">Test Configuration</h4>
            
            <div class="flex gap-4 items-end mb-6">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Test Recipient Email</label>
                    <input type="email" name="test_email" 
                           value="<?= htmlspecialchars($_POST['test_email'] ?? '') ?>"
                           placeholder="you@example.com"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>
                <button type="button" onclick="this.form.action.value='test_smtp'; this.form.submit();"
                        class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition whitespace-nowrap h-[42px]">
                    Test Connection
                </button>
            </div>

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Save
                SMTP Settings</button>
        </form>
    </div>

    <!-- Cron Jobs Settings -->
    <div x-show="activeTab === 'cron'" class="bg-white rounded-lg shadow p-6" style="display: none;">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">External Cron Configuration</h3>
        
        <?php 
            $cronSecret = $settings['cron_secret'] ?? '';
            $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST']);
            $cronUrl = $baseUrl . '/app/cron.php?key=' . $cronSecret;
        ?>

        <?php if (empty($cronSecret)): ?>
            <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded-lg mb-4">
                <p>You haven't generated a secret key yet. You need a key to secure your external cron jobs.</p>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="cron">
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    Generate Secret Key
                </button>
            </form>
        <?php else: ?>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cron URL</label>
                    <div class="flex gap-2">
                        <input type="text" readonly value="<?= htmlspecialchars($cronUrl) ?>"
                               class="flex-1 px-4 py-2 bg-gray-50 border border-gray-300 rounded-lg text-gray-600 font-mono text-sm">
                        <button onclick="navigator.clipboard.writeText('<?= htmlspecialchars($cronUrl) ?>')" 
                                class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                            Copy
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">Use this URL to set up an external cron job (e.g., EasyCron, Cron-Job.org)</p>
                </div>

                <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <h4 class="text-sm font-medium text-gray-900 mb-2">How to use</h4>
                    <p class="text-sm text-gray-600 mb-2">Set up a job to run every minute that calls the URL above.</p>
                    <p class="text-sm text-gray-600"><strong>Example via curl:</strong></p>
                    <code class="block bg-gray-800 text-white p-2 rounded mt-1 text-xs">curl -s "<?= htmlspecialchars($cronUrl) ?>"</code>
                </div>

                <div class="pt-4 border-t border-gray-200">
                    <form method="POST" onsubmit="return confirm('Regenerating the key will stop existing cron jobs from working. Continue?');">
                        <input type="hidden" name="action" value="cron">
                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm font-medium">
                            Regenerate Key
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Security Settings -->
    <div x-show="activeTab === 'security'" class="space-y-6" style="display: none;">
        <!-- Backup Codes Modal -->
        <?php if (isset($_SESSION['new_backup_codes'])): ?>
            <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
                <div class="bg-white rounded-lg max-w-md w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Save Your Backup Codes</h3>
                    <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 px-4 py-3 rounded mb-4 text-sm">
                        <strong>Important:</strong> These codes will only be shown once. Save them in a secure place. You
                        can use these to log in if you lose access to your authenticator app.
                    </div>
                    <div
                        class="grid grid-cols-2 gap-2 mb-6 font-mono text-sm bg-gray-50 p-4 rounded border border-gray-200">
                        <?php foreach ($_SESSION['new_backup_codes'] as $code): ?>
                            <div class="text-center py-1 select-all"><?= htmlspecialchars($code) ?></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="flex justify-end">
                        <button onclick="this.closest('.fixed').remove()"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">I've Saved
                            Them</button>
                    </div>
                </div>
            </div>
            <?php unset($_SESSION['new_backup_codes']); ?>
        <?php endif; ?>

        <!-- Change Password -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Change Password</h3>
            <form method="POST">
                <input type="hidden" name="action" value="password">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                    <input type="password" name="current_password" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                    <input type="password" name="new_password" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Confirm New Password</label>
                    <input type="password" name="confirm_password" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <button type="submit"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Change
                    Password</button>
            </form>
        </div>

        <!-- Two-Factor Authentication -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Two-Factor Authentication</h3>
            <?php if ($currentUser['totp_enabled']): ?>
                <div class="flex items-center text-green-600 mb-6">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd"
                            d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">2FA is currently enabled</span>
                </div>

                <div class="border-t border-gray-200 pt-6 mb-6">
                    <h4 class="text-md font-medium text-gray-900 mb-2">Backup Codes</h4>
                    <p class="text-sm text-gray-600 mb-4">
                        You have <?= $auth->getBackupCodesCount($currentUser['id']) ?> unused backup codes remaining.
                        Regenerating codes will invalidate all previous codes.
                    </p>
                    <form method="POST" class="flex items-end gap-4">
                        <input type="hidden" name="action" value="regenerate_backup_codes">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm Password</label>
                            <input type="password" name="password" required placeholder="Enter password to regenerate"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <button type="submit"
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                            Regenerate Codes
                        </button>
                    </form>
                </div>

                <div class="border-t border-gray-200 pt-6">
                    <form method="POST">
                        <input type="hidden" name="action" value="disable_2fa">
                        <button type="submit"
                            onclick="return confirm('Are you sure you want to disable 2FA? This will also delete your backup codes.')"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                            Disable 2FA
                        </button>
                    </form>
                </div>
            <?php else: ?>
                <?php if (isset($_SESSION['temp_totp_secret'])): ?>
                    <div id="qrcode" class="mb-4"></div>
                    <p class="text-sm text-gray-600 mb-4">Secret: <code
                            class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SESSION['temp_totp_secret']) ?></code>
                    </p>
                    <form method="POST">
                        <input type="hidden" name="action" value="verify_2fa">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Enter 6-digit code</label>
                            <input type="text" name="totp_code" placeholder="000000" maxlength="6" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Verify &
                            Enable</button>
                    </form>
                    <script>
                        QRCode.toCanvas(
                            document.getElementById('qrcode').appendChild(document.createElement('canvas')),
                            '<?= $auth->getTOTPUri($_SESSION['temp_totp_secret'], $currentUser['email']) ?>',
                            { width: 200 }
                        );
                    </script>
                <?php else: ?>
                    <p class="text-gray-600 mb-4">Enable two-factor authentication for added security.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="enable_2fa">
                        <button type="submit"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Setup 2FA</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Users Management -->
    <div x-show="activeTab === 'users'" class="space-y-6" style="display: none;">
        <!-- Add User -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add New User</h3>
            <form method="POST">
                <input type="hidden" name="action" value="add_user">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                        <input type="text" name="username" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
                        <input type="email" name="email" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                        <input type="password" name="password" required minlength="8"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                        <input type="password" name="confirm_password" required minlength="8"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
                <button type="submit"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Create
                    User</button>
            </form>
        </div>

        <!-- Users List -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">All Users</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                2FA Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Created</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($user['username']) ?>
                                        <?php if ($user['id'] == $currentUser['id']): ?>
                                            <span
                                                class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">You</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= htmlspecialchars($user['email']) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($user['totp_enabled']): ?>
                                        <span
                                            class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Enabled</span>
                                    <?php else: ?>
                                        <span
                                            class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <?php if ($user['id'] != $currentUser['id']): ?>
                                        <div class="flex justify-end gap-3">
                                            <?php if ($user['totp_enabled']): ?>
                                                <form method="POST" class="inline-block"
                                                    onsubmit="return confirm('Disable 2FA for this user?');">
                                                    <input type="hidden" name="action" value="reset_user_2fa">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="text-orange-600 hover:text-orange-900">Reset
                                                        2FA</button>
                                                </form>
                                            <?php endif; ?>
                                            <form method="POST" class="inline-block"
                                                onsubmit="return confirm('Are you sure you want to delete this user?');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>