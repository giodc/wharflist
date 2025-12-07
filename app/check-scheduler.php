<?php
// Check for due scheduled jobs and trigger worker
// This is a "lazy cron" implementation run on page loads

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Avoid checking too frequently (e.g., once every 30 seconds per session)
if (isset($_SESSION['last_schedule_check']) && (time() - $_SESSION['last_schedule_check'] < 30)) {
    return;
}

$_SESSION['last_schedule_check'] = time();

require_once __DIR__ . '/../config.php';
// Only run if installed
if (!isInstalled()) {
    return;
}

try {
    require_once __DIR__ . '/database.php';
    $db = Database::getInstance()->getConnection();

    // Check if there are any scheduled jobs that are due
    // We also check for pending jobs that haven't been picked up (stuck?)
    $stmt = $db->query("SELECT COUNT(*) as count FROM queue_jobs 
                        WHERE (status = 'scheduled' AND scheduled_at <= CURRENT_TIMESTAMP)
                        OR (status = 'pending')");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result && $result['count'] > 0) {
        // Trigger worker in background
        $workerPath = __DIR__ . '/worker.php';
        $phpBinary = PHP_BINARY; // Use the current PHP executable
        
        // Log output to file for debugging (uncomment if needed)
        // $logFile = __DIR__ . '/../scheduler_exec.log';
        // $command = "nohup " . escapeshellarg($phpBinary) . " " . escapeshellarg($workerPath) . " >> " . escapeshellarg($logFile) . " 2>&1 &";
        
        // Production: discarded output
        $command = "nohup " . escapeshellarg($phpBinary) . " " . escapeshellarg($workerPath) . " < /dev/null > /dev/null 2>&1 &";
        
        exec($command);
    }
} catch (Exception $e) {
    // Silently fail to avoid disrupting the user experience
    error_log("Scheduler check failed: " . $e->getMessage());
}
