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
        $emailsPerBatch = (int)($_POST['emails_per_batch'] ?? 50);
        $delayBetweenEmails = (float)($_POST['delay_between_emails'] ?? 0);

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
            unset($_SESSION['temp_totp_secret']);
            $success = '2FA enabled successfully';
            $currentUser['totp_enabled'] = 1;
        } else {
            $error = 'Invalid verification code';
        }
    } elseif ($action === 'disable_2fa') {
        $auth->disableTOTP($currentUser['id']);
        $success = '2FA disabled';
        $currentUser['totp_enabled'] = 0;
    }
}

// Get current settings
$stmt = $db->query("SELECT key, value FROM settings");
$settings = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['key']] = $row['value'];
}

$pageTitle = 'Settings';
$additionalHead = '<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.1/build/qrcode.min.js"></script>';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div x-data="{ activeTab: 'general' }">
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
                <label class="block text-sm font-medium text-gray-700 mb-2">Timezone</label>
                <select name="timezone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="UTC" <?= ($settings['timezone'] ?? 'UTC') === 'UTC' ? 'selected' : '' ?>>UTC</option>
                    <option value="America/New_York" <?= ($settings['timezone'] ?? '') === 'America/New_York' ? 'selected' : '' ?>>America/New_York</option>
                    <option value="America/Chicago" <?= ($settings['timezone'] ?? '') === 'America/Chicago' ? 'selected' : '' ?>>America/Chicago</option>
                    <option value="America/Denver" <?= ($settings['timezone'] ?? '') === 'America/Denver' ? 'selected' : '' ?>>America/Denver</option>
                    <option value="America/Los_Angeles" <?= ($settings['timezone'] ?? '') === 'America/Los_Angeles' ? 'selected' : '' ?>>America/Los_Angeles</option>
                    <option value="Europe/London" <?= ($settings['timezone'] ?? '') === 'Europe/London' ? 'selected' : '' ?>>Europe/London</option>
                    <option value="Europe/Paris" <?= ($settings['timezone'] ?? '') === 'Europe/Paris' ? 'selected' : '' ?>>Europe/Paris</option>
                    <option value="Asia/Tokyo" <?= ($settings['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : '' ?>>Asia/Tokyo</option>
                </select>
            </div>

            <hr class="my-6 border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Email Branding</h3>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Email Logo URL</label>
                <input type="url" name="email_logo" 
                       value="<?= htmlspecialchars($settings['email_logo'] ?? '') ?>"
                       placeholder="https://example.com/logo.png"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <p class="text-xs text-gray-500 mt-1">Direct URL to your logo image (recommended: 200px wide or less)</p>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Logo Position and Footer Alignment</label>
                <select name="logo_position" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="left" <?= ($settings['logo_position'] ?? 'center') === 'left' ? 'selected' : '' ?>>Left</option>
                    <option value="center" <?= ($settings['logo_position'] ?? 'center') === 'center' ? 'selected' : '' ?>>Center</option>
                    <option value="right" <?= ($settings['logo_position'] ?? 'center') === 'right' ? 'selected' : '' ?>>Right</option>
                </select>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 mb-2">Company/Brand Name</label>
                <input type="text" name="logo_name" 
                       value="<?= htmlspecialchars($settings['logo_name'] ?? '') ?>"
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
                           value="<?= htmlspecialchars($settings['footer_phone'] ?? '') ?>"
                           placeholder="(555) 123-4567"
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

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Save Changes</button>
        </form>
    </div>

    <!-- SMTP Settings -->
    <div x-show="activeTab === 'smtp'" class="bg-white rounded-lg shadow p-6" style="display: none;">
        <form method="POST">
            <input type="hidden" name="action" value="smtp">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Host</label>
                <input type="text" name="smtp_host" 
                       value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Port</label>
                <input type="number" name="smtp_port" 
                       value="<?= htmlspecialchars($settings['smtp_port'] ?? '587') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Username</label>
                <input type="text" name="smtp_user" 
                       value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Password</label>
                <input type="password" name="smtp_pass" placeholder="Leave blank to keep current"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">From Email</label>
                <input type="email" name="smtp_from" 
                       value="<?= htmlspecialchars($settings['smtp_from'] ?? '') ?>"
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
                <p class="text-xs text-gray-500 mt-1">Delay in seconds between each email (0 = no delay, helps avoid rate limits)</p>
            </div>

            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Save SMTP Settings</button>
        </form>
    </div>

    <!-- Security Settings -->
    <div x-show="activeTab === 'security'" class="space-y-6" style="display: none;">
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

                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Change Password</button>
            </form>
        </div>

        <!-- Two-Factor Authentication -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Two-Factor Authentication</h3>
            <?php if ($currentUser['totp_enabled']): ?>
                <div class="flex items-center text-green-600 mb-4">
                    <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-medium">2FA is currently enabled</span>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="disable_2fa">
                    <button type="submit" 
                            onclick="return confirm('Are you sure you want to disable 2FA?')"
                            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                        Disable 2FA
                    </button>
                </form>
            <?php else: ?>
                <?php if (isset($_SESSION['temp_totp_secret'])): ?>
                    <div id="qrcode" class="mb-4"></div>
                    <p class="text-sm text-gray-600 mb-4">Secret: <code class="bg-gray-100 px-2 py-1 rounded"><?= htmlspecialchars($_SESSION['temp_totp_secret']) ?></code></p>
                    <form method="POST">
                        <input type="hidden" name="action" value="verify_2fa">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Enter 6-digit code</label>
                            <input type="text" name="totp_code" placeholder="000000" maxlength="6" required
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Verify & Enable</button>
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
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Setup 2FA</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
