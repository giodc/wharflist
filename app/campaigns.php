<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();

// Create lookup array for list names
$stmt = $db->query("SELECT l.*, 
                    (SELECT COUNT(DISTINCT s.id) 
                     FROM subscribers s 
                     INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id 
                     WHERE sl.list_id = l.id 
                     AND s.verified = 1 
                     AND s.unsubscribed = 0 
                     AND sl.unsubscribed = 0) as subscriber_count
                    FROM lists l ORDER BY l.is_default DESC, l.name");
$lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

$allLists = [];
foreach ($lists as $l) {
    $allLists[$l['id']] = $l['name'];
}

// Get all campaigns (no limit)
$stmt = $db->query("SELECT ec.*, l.name as list_name, 
                    qj.id as job_id, qj.status as job_status, qj.progress, qj.total, qj.scheduled_at
                    FROM email_campaigns ec 
                    LEFT JOIN lists l ON ec.list_id = l.id 
                    LEFT JOIN queue_jobs qj ON qj.id = (
                        SELECT id FROM queue_jobs WHERE campaign_id = ec.id ORDER BY id DESC LIMIT 1
                    )
                    WHERE ec.status IN ('draft', 'sent', 'scheduled')
                    ORDER BY COALESCE(ec.sent_at, ec.created_at) DESC");
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $auth->getCurrentUser();
$pageTitle = 'Campaigns';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-gray-900">All Campaigns</h1>
        <a href="compose.php"
            class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            New Campaign
        </a>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <?php if (empty($campaigns)): ?>
            <p class="text-gray-500 text-sm">No campaigns yet</p>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($campaigns as $campaign): ?>
                    <div class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                        <div class="flex items-start justify-between mb-2">
                            <div class="font-semibold text-gray-900 text-sm flex-1">
                                <?= htmlspecialchars($campaign['subject']) ?>
                            </div>
                            <?php if ($campaign['status'] === 'draft'): ?>
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-800 text-xs rounded-full">Draft</span>
                            <?php elseif ($campaign['status'] === 'scheduled'): ?>
                                <span class="px-2 py-0.5 bg-purple-100 text-purple-800 text-xs rounded-full">Scheduled</span>
                            <?php elseif ($campaign['job_status']): ?>
                                <?php if ($campaign['job_status'] === 'pending'): ?>
                                    <span class="px-2 py-0.5 bg-yellow-100 text-yellow-800 text-xs rounded-full">Queued</span>
                                <?php elseif ($campaign['job_status'] === 'processing'): ?>
                                    <span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">Sending</span>
                                <?php elseif ($campaign['job_status'] === 'completed'): ?>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-800 text-xs rounded-full">Sent</span>
                                <?php elseif ($campaign['job_status'] === 'failed'): ?>
                                    <span class="px-2 py-0.5 bg-red-100 text-red-800 text-xs rounded-full">Failed</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-gray-500 space-y-1">
                            <div>
                                <?php
                                if (!empty($campaign['list_ids'])) {
                                    $ids = explode(',', $campaign['list_ids']);
                                    $names = [];
                                    foreach ($ids as $id) {
                                        if (isset($allLists[$id])) {
                                            $names[] = $allLists[$id];
                                        }
                                    }
                                    echo htmlspecialchars(implode(', ', $names));
                                } else {
                                    echo htmlspecialchars($campaign['list_name']);
                                }
                                ?>
                            </div>
                            <?php if ($campaign['status'] === 'draft'): ?>
                                <div>Created: <?= date('M d, Y H:i', strtotime($campaign['created_at'])) ?></div>
                            <?php elseif ($campaign['status'] === 'scheduled'): ?>
                                <div>Scheduled for: 
                                    <?php 
                                    if ($campaign['scheduled_at']) {
                                        $dt = new DateTime($campaign['scheduled_at'], new DateTimeZone('UTC'));
                                        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                                        echo $dt->format('M d, Y H:i');
                                    } else {
                                        echo 'Pending...';
                                    }
                                    ?>
                                </div>
                            <?php elseif ($campaign['job_status'] === 'processing' && $campaign['total'] > 0): ?>
                                <div><?= $campaign['progress'] ?> / <?= $campaign['total'] ?> sent</div>
                                <div><?= date('M d, Y H:i', strtotime($campaign['sent_at'])) ?></div>
                            <?php else: ?>
                                <div>Sent to <?= $campaign['sent_count'] ?> subscribers</div>
                                <div><?= date('M d, Y H:i', strtotime($campaign['sent_at'])) ?></div>
                            <?php endif; ?>
                            <div class="mt-2 flex gap-2">
                                <?php if ($campaign['status'] === 'draft'): ?>
                                    <a href="compose.php?edit=<?= $campaign['id'] ?>"
                                        class="inline-flex items-center text-xs text-blue-600 hover:text-blue-800">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z">
                                            </path>
                                        </svg>
                                        Edit Draft
                                    </a>
                                <?php else: ?>
                                    <?php if ($campaign['job_id']): ?>
                                        <a href="campaign-status.php?job_id=<?= $campaign['job_id'] ?>"
                                            class="inline-flex items-center text-xs text-blue-600 hover:text-blue-800">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                                </path>
                                            </svg>
                                            View Status
                                        </a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>