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
            // Create data directory
            if (!is_dir(__DIR__ . '/app/data')) {
                mkdir(__DIR__ . '/app/data', 0755, true);
            }

            // Initialize database
            require_once __DIR__ . '/app/database.php';
            $db = Database::getInstance();
            $db->initDatabase();
            $conn = $db->getConnection();

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
    <script src="https://unpkg.com/tailwindcss-jit-cdn"></script>
    <style>body { background: #f8f9fa; min-height: 100vh; }</style>
</head>
<body>
<div class="uk-background-muted uk-height-viewport">
    <div class="uk-container uk-container-small uk-margin-large-top">
        <div class="uk-card uk-card-default uk-card-body">
            <h1 class="uk-card-title uk-text-center">
                <span uk-icon="icon: mail; ratio: 2" class="uk-margin-small-right"></span>
                WharfList Setup
            </h1>
            <p class="uk-text-center uk-text-muted">Configure your email collection system</p>

            <?php if ($error): ?>
                <div class="uk-alert-danger" uk-alert>
                    <a class="uk-alert-close" uk-close></a>
                    <p><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" class="uk-form-stacked">
                <fieldset class="uk-fieldset">
                    <legend class="uk-legend">Admin Account</legend>
                    
                    <div class="uk-margin">
                        <label class="uk-form-label">Username</label>
                        <input class="uk-input" type="text" name="username" required>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">Email</label>
                        <input class="uk-input" type="email" name="email" required>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">Password</label>
                        <input class="uk-input" type="password" name="password" required>
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">Confirm Password</label>
                        <input class="uk-input" type="password" name="confirm_password" required>
                    </div>
                </fieldset>

                <fieldset class="uk-fieldset uk-margin-top">
                    <legend class="uk-legend">SMTP Configuration</legend>
                    
                    <div class="uk-margin">
                        <label class="uk-form-label">SMTP Host</label>
                        <input class="uk-input" type="text" name="smtp_host" placeholder="smtp.example.com">
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">SMTP Port</label>
                        <input class="uk-input" type="number" name="smtp_port" placeholder="587" value="587">
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">SMTP Username</label>
                        <input class="uk-input" type="text" name="smtp_user">
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">SMTP Password</label>
                        <input class="uk-input" type="password" name="smtp_pass">
                    </div>

                    <div class="uk-margin">
                        <label class="uk-form-label">From Email</label>
                        <input class="uk-input" type="email" name="smtp_from" placeholder="noreply@example.com">
                    </div>
                </fieldset>

                <fieldset class="uk-fieldset uk-margin-top">
                    <legend class="uk-legend">General Settings</legend>
                    
                    <div class="uk-margin">
                        <label class="uk-form-label">Timezone</label>
                        <select class="uk-select" name="timezone">
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
                </fieldset>

                <div class="uk-margin-top">
                    <button class="uk-button uk-button-primary uk-width-1-1" type="submit">
                        Complete Setup
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
