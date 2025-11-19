<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf-helper.php';

// Require POST request with CSRF token for logout
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    header('Location: dashboard.php');
    exit;
}

$auth = new Auth();
$auth->logout();
