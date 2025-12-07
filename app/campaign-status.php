<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

// Handle Cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_job') {
    $jobId = $_POST['job_id'] ?? 0;
    $campaignId = $_POST['campaign_id'] ?? 0;

    if ($jobId && $campaignId) {
        // 1. Update Job status to cancelled
        $stmt = $db->prepare("UPDATE queue_jobs SET status = 'cancelled', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$jobId]);

        // 2. Update Campaign status back to draft
        $stmt = $db->prepare("UPDATE email_campaigns SET status = 'draft' WHERE id = ?");
        $stmt->execute([$campaignId]);

        // 3. Redirect to compose page
        header("Location: compose.php?edit=" . $campaignId . "&success=updated");
        exit;
    }
}

$jobId = $_GET['job_id'] ?? null;
if (!$jobId) {
    header('Location: compose.php');
    exit;
}

// Get job details
$stmt = $db->prepare("SELECT qj.*, ec.subject, ec.list_id, l.name as list_name
                      FROM queue_jobs qj
                      INNER JOIN email_campaigns ec ON qj.campaign_id = ec.id
                      LEFT JOIN lists l ON ec.list_id = l.id
                      WHERE qj.id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    header('Location: compose.php');
    exit;
}

$user = $auth->getCurrentUser();
$pageTitle = 'Campaign Status';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="max-w-2xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Campaign Status</h1>
        <a href="compose.php" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            Compose New
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4"><?= htmlspecialchars($job['subject']) ?></h2>
        <div class="mb-4">
            <span class="text-sm text-gray-600">List:</span>
            <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars($job['list_name']) ?></span>
        </div>

        <!-- Status Badge -->
        <div class="mb-6 flex items-center justify-between">
            <div>
                <?php if ($job['status'] === 'pending'): ?>
                    <span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-sm font-semibold rounded-full">⏳ Queued</span>
                <?php elseif ($job['status'] === 'scheduled'): ?>
                    <span class="px-3 py-1 bg-purple-100 text-purple-800 text-sm font-semibold rounded-full">📅 Scheduled</span>
                <?php elseif ($job['status'] === 'processing'): ?>
                    <span class="px-3 py-1 bg-blue-100 text-blue-800 text-sm font-semibold rounded-full">🔄 Sending...</span>
                <?php elseif ($job['status'] === 'completed'): ?>
                    <span class="px-3 py-1 bg-green-100 text-green-800 text-sm font-semibold rounded-full">✓ Completed</span>
                <?php elseif ($job['status'] === 'failed'): ?>
                    <span class="px-3 py-1 bg-red-100 text-red-800 text-sm font-semibold rounded-full">✗ Failed</span>
                <?php endif; ?>
            </div>
            
            <?php if ($job['status'] === 'pending' || $job['status'] === 'processing' || $job['status'] === 'scheduled'): ?>
            <div class="flex items-center gap-2">
                <?php if ($job['status'] !== 'scheduled'): ?>
                <a href="trigger-worker.php?return=campaign-status&job_id=<?= $jobId ?>" 
                   class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white text-sm rounded-lg transition">
                    🔄 Trigger Worker
                </a>
                <?php endif; ?>
                
                <form method="POST" onsubmit="return confirm('Are you sure? This will stop sending immediately and revert the campaign to draft mode.');">
                    <input type="hidden" name="action" value="cancel_job">
                    <input type="hidden" name="job_id" value="<?= $jobId ?>">
                    <input type="hidden" name="campaign_id" value="<?= $job['campaign_id'] ?>">
                    <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm rounded-lg transition">
                        ⛔ Stop & Revert to Draft
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Progress Bar -->
        <?php if ($job['total'] > 0): ?>
        <div class="mb-4">
            <div class="flex justify-between text-sm text-gray-600 mb-2">
                <span>Progress</span>
                <span><?= $job['progress'] ?> / <?= $job['total'] ?> emails</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                <?php $percentage = $job['total'] > 0 ? ($job['progress'] / $job['total']) * 100 : 0; ?>
                <div class="bg-blue-600 h-4 transition-all duration-300" style="width: <?= $percentage ?>%"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Times -->
        <div class="text-sm text-gray-600 space-y-2">
            <div><strong>Created:</strong> 
                <?php
                $dt = new DateTime($job['created_at'], new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                echo $dt->format('Y-m-d H:i:s');
                ?>
            </div>
            <?php if (isset($job['scheduled_at']) && $job['scheduled_at']): ?>
            <div class="text-purple-600"><strong>Scheduled for:</strong> 
                <?php
                $dt = new DateTime($job['scheduled_at'], new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                echo $dt->format('Y-m-d H:i:s');
                ?>
            </div>
            <?php endif; ?>
            <?php if ($job['started_at']): ?>
            <div><strong>Started:</strong> 
                <?php
                $dt = new DateTime($job['started_at'], new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                echo $dt->format('Y-m-d H:i:s');
                ?>
            </div>
            <?php endif; ?>
            <?php if ($job['completed_at']): ?>
            <div><strong>Completed:</strong> 
                <?php
                $dt = new DateTime($job['completed_at'], new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                echo $dt->format('Y-m-d H:i:s');
                ?>
            </div>
            <?php endif; ?>
            <?php if ($job['error']): ?>
            <div class="text-red-600"><strong>Error:</strong> <?= htmlspecialchars($job['error']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Auto-refresh for pending/processing jobs -->
    <?php if ($job['status'] === 'pending' || $job['status'] === 'processing'): ?>
    <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg">
        <p class="text-sm" id="status-message">⟳ This page will auto-refresh every 3 seconds until sending is complete.</p>
    </div>
    <script>
    // Auto-trigger worker on page load if status is pending
    <?php if ($job['status'] === 'pending'): ?>
    fetch('run-worker.php', {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(() => {
        document.getElementById('status-message').textContent = '✓ Worker started! Processing emails...';
    }).catch(err => {
        console.error('Worker trigger failed:', err);
    });
    <?php endif; ?>
    
    // Reload page to check progress
    setTimeout(function() {
        window.location.reload();
    }, 3000);
    </script>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
