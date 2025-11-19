<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/auth.php';

$auth = new Auth();

// If not logged in, redirect to login
if (!$auth->isLoggedIn()) {
    header('Location: /app/login.php');
    exit;
}

// If logged in, redirect to dashboard
header('Location: /app/dashboard.php');
exit;
