<?php
session_start();

define('DB_PATH', __DIR__ . '/app/data/wharflist.db');
define('BASE_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]");

// Check if already installed
if (file_exists(__DIR__ . '/.installed')) {
    header('Location: /app/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = trim($_POST['smtp_port'] ?? '');
    $smtp_user = trim($_POST['smtp_user'] ?? '');
    $smtp_pass = $_POST['smtp_pass'] ?? '';
    $smtp_from = trim($_POST['smtp_from'] ?? '');
    $timezone = $_POST['timezone'] ?? 'UTC';

    // Validation
    if (empty($username) || empty($email) || empty($password)) {
        $error = 'All fields are required';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address';
    } else {
        try {
            // Create data directory with secure permissions
            if (!is_dir(__DIR__ . '/app/data')) {
                mkdir(__DIR__ . '/app/data', 0700, true);
            }

            // Initialize database
            require_once __DIR__ . '/app/database.php';
            $db = Database::getInstance();
            $db->initDatabase();
            $conn = $db->getConnection();

            // Set secure file permissions on database
            if (file_exists(DB_PATH)) {
                chmod(DB_PATH, 0600);
            }

            // Run initial migrations (ensures schema is up to date)
            require_once __DIR__ . '/app/migrations.php';
            runMigrations($conn);

            // Insert admin user
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashedPassword]);

            // Create default list
            $stmt = $conn->prepare("INSERT INTO lists (name, description, is_default) VALUES (?, ?, 1)");
            $stmt->execute(['Default List', 'Default subscriber list']);

            // Save settings
            $settings = [
                'smtp_host' => $smtp_host,
                'smtp_port' => $smtp_port,
                'smtp_user' => $smtp_user,
                'smtp_pass' => $smtp_pass,
                'smtp_from' => $smtp_from,
                'timezone' => $timezone,
                'site_name' => 'WharfList'
            ];

            $stmt = $conn->prepare("INSERT INTO settings (key, value) VALUES (?, ?)");
            foreach ($settings as $key => $value) {
                $stmt->execute([$key, $value]);
            }

            // Mark as installed
            file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));

            // Redirect to login
            header('Location: /app/login.php');
            exit;
        } catch (Exception $e) {
            $error = 'Setup failed: ' . $e->getMessage();
        }
    }
}
$pageTitle = 'Setup';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - WharfList</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-50 min-h-screen">
    <div class="min-h-screen flex items-center justify-center p-4">
        <div class="max-w-2xl w-full bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
                    <svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                        </path>
                    </svg>
                </div>
                <h1 class="text-3xl font-bold text-gray-900">WharfList Setup</h1>
                <p class="text-gray-600 mt-2">Configure your email collection system</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-6">
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-8">
                <!-- Admin Account -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">Admin Account
                    </h2>

                    <div class="space-y-4">
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
                            <input type="password" name="password" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                            <input type="password" name="confirm_password" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                </div>

                <!-- SMTP Configuration -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">SMTP
                        Configuration</h2>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Host</label>
                            <input type="text" name="smtp_host" placeholder="smtp.example.com"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Port</label>
                            <input type="number" name="smtp_port" placeholder="587" value="587"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Username</label>
                            <input type="text" name="smtp_user"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">SMTP Password</label>
                            <input type="password" name="smtp_pass"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">From Email</label>
                            <input type="email" name="smtp_from" placeholder="noreply@example.com"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                    </div>
                </div>

                <!-- General Settings -->
                <div>
                    <h2 class="text-xl font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">General Settings
                    </h2>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Timezone</label>
                        <select name="timezone"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <option value="UTC">UTC</option>
                            <option value="America/New_York">America/New_York</option>
                            <option value="America/Chicago">America/Chicago</option>
                            <option value="America/Denver">America/Denver</option>
                            <option value="America/Los_Angeles">America/Los_Angeles</option>
                            <option value="Europe/London">Europe/London</option>
                            <option value="Europe/Paris">Europe/Paris</option>
                            <option value="Asia/Tokyo">Asia/Tokyo</option>
                        </select>
                    </div>
                </div>

                <button type="submit"
                    class="w-full px-6 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition">
                    Complete Setup
                </button>
            </form>
        </div>
    </div>
</body>

</html>