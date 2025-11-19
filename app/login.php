<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf-helper.php';

$auth = new Auth();

if ($auth->isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Security token invalid. Please try again.';
    } else
        if (isset($_POST['totp_code'])) {
            // Verify 2FA
            if ($auth->verify2FA($_POST['totp_code'])) {
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Invalid 2FA code';
            }
        } else {
            // Regular login
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            $result = $auth->login($username, $password);

            if ($result['success']) {
                if ($result['requires_2fa']) {
                    // Show 2FA form
                } else {
                    header('Location: dashboard.php');
                    exit;
                }
            } else {
                $error = $result['error'];
            }
        }
}

$show2FA = isset($_SESSION['pending_2fa']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - WharfList</title>
    <script src="https://unpkg.com/tailwindcss-jit-cdn"></script>
</head>

<body class="bg-gradient-to-br from-blue-50 to-indigo-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo & Title -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-600 rounded-2xl mb-4">
                <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                    </path>
                </svg>
            </div>
            <h1 class="text-3xl font-bold text-gray-900">WharfList</h1>
            <p class="text-gray-600 mt-2">Sign in to your account</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <?php if ($error): ?>
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <p class="text-sm text-red-800"><?= htmlspecialchars($error) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($show2FA): ?>
                <!-- 2FA Form -->
                <form method="POST" class="space-y-6">
                    <?= getCSRFTokenField() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            2FA Code or Backup Code
                        </label>
                        <input type="text" name="totp_code" maxlength="8" placeholder="000000" required autofocus
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent text-center text-2xl tracking-widest">
                        <p class="mt-2 text-sm text-gray-500">Enter the 6-digit code from your app or an 8-character backup
                            code</p>
                    </div>

                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Verify Code
                    </button>
                </form>
            <?php else: ?>
                <!-- Login Form -->
                <form method="POST" class="space-y-6">
                    <?= getCSRFTokenField() ?>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Username or Email
                        </label>
                        <input type="text" name="username" required autofocus
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Password
                        </label>
                        <input type="password" name="password" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-4 rounded-lg transition duration-200 flex items-center justify-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1">
                            </path>
                        </svg>
                        Sign In
                    </button>
                </form>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <p class="text-center text-sm text-gray-600 mt-8">
            WharfList Email Collection System
        </p>
    </div>
</body>

</html>