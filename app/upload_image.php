<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No image file provided']);
    exit;
}

$file = $_FILES['image'];
$uploadDir = __DIR__ . '/uploads/';
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$maxSize = 5 * 1024 * 1024; // 5MB

// Validate file type
if (!in_array($file['type'], $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
    exit;
}

// Validate file size
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large. Maximum size is 5MB.']);
    exit;
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid('img_', true) . '.' . $extension;
$targetPath = $uploadDir . $filename;

// Create uploads directory if it doesn't exist
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    $url = BASE_URL . '/app/uploads/' . $filename;
    echo json_encode(['url' => $url]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload file']);
}
