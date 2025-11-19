<?php
// Manual Worker Trigger - Use this if automatic trigger fails
// Access: /trigger-worker.php or /trigger-worker/

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

// Check for stuck jobs (processing for more than 5 minutes)
$stmt = $db->query("SELECT COUNT(*) as count FROM queue_jobs 
                    WHERE status = 'processing' 
                    AND datetime(started_at, '+5 minutes') < datetime('now')");
$stuckCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Reset stuck jobs
if ($stuckCount > 0) {
    $db->exec("UPDATE queue_jobs 
               SET status = 'pending', started_at = NULL 
               WHERE status = 'processing' 
               AND datetime(started_at, '+5 minutes') < datetime('now')");
}

// Get pending jobs count
$stmt = $db->query("SELECT COUNT(*) as count FROM queue_jobs WHERE status = 'pending'");
$pendingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$message = '';
$error = '';
$returnTo = $_GET['return'] ?? null;
$jobId = $_GET['job_id'] ?? null;

// Auto-trigger on GET request (for quick link from campaign-status)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $returnTo === 'campaign-status') {
    // Trigger worker
    $workerPath = __DIR__ . '/worker.php';
    $command = "php " . escapeshellarg($workerPath) . " > /dev/null 2>&1 &";
    exec($command);
    
    // If AJAX request, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => 'Worker triggered']);
        exit;
    }
    
    // Redirect back to campaign status
    if ($jobId) {
        header("Location: campaign-status.php?job_id=" . $jobId);
    } else {
        header("Location: compose.php");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Trigger worker
    $workerPath = __DIR__ . '/worker.php';
    $command = "php " . escapeshellarg($workerPath) . " > /dev/null 2>&1 &";
    
    exec($command, $output, $return_var);
    
    if ($return_var === 0) {
        $message = "Worker triggered successfully! Check campaign status page for progress.";
    } else {
        $error = "Failed to trigger worker. Return code: " . $return_var;
    }
    
    // Refresh counts
    $stmt = $db->query("SELECT COUNT(*) as count FROM queue_jobs WHERE status = 'pending'");
    $pendingCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

$user = $auth->getCurrentUser();
$pageTitle = 'Trigger Worker';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="max-w-2xl mx-auto">
    <h1 class="text-3xl font-bold text-gray-900 mb-6">Manual Worker Trigger</h1>
    
    <?php if ($message): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Queue Status</h2>
        
        <div class="space-y-3 mb-6">
            <div class="flex justify-between">
                <span class="text-gray-600">Pending Jobs:</span>
                <strong class="text-gray-900"><?= $pendingCount ?></strong>
            </div>
            <?php if ($stuckCount > 0): ?>
            <div class="flex justify-between text-yellow-600">
                <span>Stuck Jobs (Reset):</span>
                <strong><?= $stuckCount ?></strong>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($pendingCount > 0): ?>
        <form method="POST">
            <button type="submit" class="w-full px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition font-semibold">
                🚀 Trigger Worker Now
            </button>
        </form>
        <p class="text-sm text-gray-500 mt-3 text-center">
            This will start processing queued campaigns
        </p>
        <?php else: ?>
        <div class="text-center text-gray-500 py-4">
            ✓ No pending jobs in queue
        </div>
        <?php endif; ?>
    </div>
    
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <h3 class="font-semibold text-blue-900 mb-2">About Worker</h3>
        <p class="text-sm text-blue-800 mb-2">
            The worker processes email campaigns in the background. It should start automatically when you send a campaign.
        </p>
        <p class="text-sm text-blue-800">
            Use this page if:
        </p>
        <ul class="text-sm text-blue-800 list-disc list-inside mt-2 space-y-1">
            <li>Campaigns are stuck in "Queued" status</li>
            <li>Automatic trigger didn't work</li>
            <li>You want to manually start processing</li>
        </ul>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
