<?php
// Direct worker execution - called via browser/AJAX
// This bypasses exec() issues by running worker directly in the request

require_once __DIR__ . '/../config.php';

// Only allow execution if called properly (not direct browser access without auth)
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) && empty($_GET['direct'])) {
    require_once __DIR__ . '/auth.php';
    $auth = new Auth();
    $auth->requireLogin();
}

// Set longer timeout for worker
set_time_limit(300);
ignore_user_abort(true);

// Output immediately and close connection so user doesn't wait
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . ob_get_length());
    echo json_encode(['success' => true, 'message' => 'Worker started']);
    
    // Flush and close connection
    @ob_end_flush();
    @flush();
    
    // Close session to allow other requests
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

// Now run the worker
require __DIR__ . '/worker.php';
