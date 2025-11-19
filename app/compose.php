<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

$campaignId = $_GET['edit'] ?? null;
$previewData = null;
$draftData = null;

// Handle success messages from redirects
if (isset($_GET['success'])) {
    if ($_GET['success'] === 'updated') {
        $success = 'Draft updated successfully';
    } elseif ($_GET['success'] === 'saved') {
        $success = 'Draft saved successfully';
    }
}

// Load draft for editing
if ($campaignId) {
    $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE id = ? AND status = 'draft'");
    $stmt->execute([$campaignId]);
    $draftData = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$draftData) {
        $error = 'Draft not found';
        $campaignId = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'send';
    $listId = $_POST['list_id'] ?? 0;
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $editId = $_POST['edit_id'] ?? null;

    if (empty($listId) || empty($subject) || empty($body)) {
        $error = 'All fields are required';
    } else {
        if ($action === 'preview') {
            // Store preview data
            $previewData = [
                'list_id' => $listId,
                'subject' => $subject,
                'body' => $body
            ];
        } elseif ($action === 'draft') {
            // Save as draft
            if ($editId) {
                $stmt = $db->prepare("UPDATE email_campaigns SET list_id = ?, subject = ?, body = ? WHERE id = ? AND status = 'draft'");
                $stmt->execute([$listId, $subject, $body, $editId]);
                // Redirect back to edit page
                header("Location: compose.php?edit=" . $editId . "&success=updated");
                exit;
            } else {
                $stmt = $db->prepare("INSERT INTO email_campaigns (list_id, subject, body, status, created_at) VALUES (?, ?, ?, 'draft', CURRENT_TIMESTAMP)");
                $stmt->execute([$listId, $subject, $body]);
                $draftId = $db->lastInsertId();
                // Redirect to edit page for new draft
                header("Location: compose.php?edit=" . $draftId . "&success=saved");
                exit;
            }
        } elseif ($action === 'send') {
            // Get verified subscribers count
            $stmt = $db->prepare("SELECT COUNT(DISTINCT s.id) as count 
                                  FROM subscribers s 
                                  INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id 
                                  WHERE sl.list_id = ? AND s.verified = 1 AND s.unsubscribed = 0 AND sl.unsubscribed = 0");
            $stmt->execute([$listId]);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($count == 0) {
                $error = 'No verified subscribers in this list';
            } else {
                // Create or update campaign
                if ($editId) {
                    $stmt = $db->prepare("UPDATE email_campaigns SET list_id = ?, subject = ?, body = ?, status = 'sent', sent_count = 0, sent_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$listId, $subject, $body, $editId]);
                    $campaignId = $editId;
                } else {
                    $stmt = $db->prepare("INSERT INTO email_campaigns (list_id, subject, body, status, sent_count, sent_at) VALUES (?, ?, ?, 'sent', 0, CURRENT_TIMESTAMP)");
                    $stmt->execute([$listId, $subject, $body]);
                    $campaignId = $db->lastInsertId();
                }
                
                // Create queue job
                $stmt = $db->prepare("INSERT INTO queue_jobs (campaign_id) VALUES (?)");
                $stmt->execute([$campaignId]);
                $jobId = $db->lastInsertId();
                
                // Trigger worker
                $workerCmd = "php " . __DIR__ . "/worker.php > /dev/null 2>&1 &";
                exec($workerCmd);
                
                // Redirect to status
                header("Location: campaign-status.php?job_id=" . $jobId);
                exit;
            }
        }
    }
}

// Get all lists with subscriber counts (using junction table, excluding globally and list-specific unsubscribes)
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

// Get recent campaigns (drafts and sent)
$stmt = $db->query("SELECT ec.*, l.name as list_name, 
                    qj.id as job_id, qj.status as job_status, qj.progress, qj.total
                    FROM email_campaigns ec 
                    LEFT JOIN lists l ON ec.list_id = l.id 
                    LEFT JOIN queue_jobs qj ON ec.id = qj.campaign_id
                    ORDER BY 
                        CASE WHEN ec.status = 'draft' THEN 0 ELSE 1 END,
                        COALESCE(ec.sent_at, ec.created_at) DESC 
                    LIMIT 10");
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $auth->getCurrentUser();
$pageTitle = 'Compose Email';
$additionalHead = '
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<style>
    .ql-editor p {
        margin-bottom: 1em;
    }
    .ql-editor p:last-child {
        margin-bottom: 0;
    }
    /* Add spacing for lists */
    .ql-editor ul, .ql-editor ol {
        margin-bottom: 1em;
    }
    .ql-editor li {
        margin-bottom: 0.5em;
    }
    /* Headings spacing */
    .ql-editor h1, .ql-editor h2, .ql-editor h3 {
        margin-top: 1.5em;
        margin-bottom: 0.75em;
    }
    .ql-editor h1:first-child, .ql-editor h2:first-child, .ql-editor h3:first-child {
        margin-top: 0;
    }
</style>
';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<h1 class="text-3xl font-bold text-gray-900 mb-6">Compose Email</h1>

<!-- Alerts -->
<?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg mb-4">
        <p><?= htmlspecialchars($error) ?></p>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg mb-4">
        <p><?= htmlspecialchars($success) ?></p>
    </div>
<?php endif; ?>

<div class="bg-white rounded-lg shadow p-6 mb-6">
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
        <!-- Form Fields (Left) -->
        <div class="lg:col-span-3">
            <form method="POST" id="compose-form">
                <input type="hidden" name="action" id="form-action" value="send">
                <?php if ($campaignId): ?>
                <input type="hidden" name="edit_id" value="<?= $campaignId ?>">
                <?php endif; ?>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select List</label>
                    <select name="list_id" id="list_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <option value="">Choose a list</option>
                        <?php foreach ($lists as $list): ?>
                        <option value="<?= $list['id'] ?>" <?= ($draftData && $draftData['list_id'] == $list['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($list['name']) ?> (<?= $list['subscriber_count'] ?> subscribers)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                    <input type="text" name="subject" id="subject" required 
                           value="<?= $draftData ? htmlspecialchars($draftData['subject']) : '' ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <div class="mb-4 lg:mb-0">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Message</label>
                    <div id="editor" style="height: 300px; background: white;" class="border border-gray-300 rounded-lg"></div>
                    <input type="hidden" name="body" id="body-content" required>
                    <p class="text-xs text-gray-500 mt-1">Use the toolbar to format your message</p>
                </div>
            </form>
        </div>

        <!-- Action Buttons Sidebar (Right) -->
        <div class="lg:col-span-1">
            <div class="lg:sticky lg:top-6">
                <h3 class="text-sm font-semibold text-gray-700 mb-3">Actions</h3>
                <div class="flex flex-col gap-3">
                    <button type="button" onclick="sendEmail()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        Send Email
                    </button>
                    
                    <button type="button" onclick="saveDraft()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"></path>
                        </svg>
                        Save Draft
                    </button>
                    
                    <button type="button" onclick="showPreview()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        Preview
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Campaigns -->
<div class="bg-white rounded-lg shadow p-6">
    <h3 class="text-lg font-semibold text-gray-900 mb-4">Recent Campaigns</h3>
            <?php if (empty($campaigns)): ?>
                <p class="text-gray-500 text-sm">No campaigns sent yet</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($campaigns as $campaign): ?>
                    <div class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                        <div class="flex items-start justify-between mb-2">
                            <div class="font-semibold text-gray-900 text-sm flex-1"><?= htmlspecialchars($campaign['subject']) ?></div>
                            <?php if ($campaign['status'] === 'draft'): ?>
                                <span class="px-2 py-0.5 bg-gray-100 text-gray-800 text-xs rounded-full">Draft</span>
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
                            <div><?= htmlspecialchars($campaign['list_name']) ?></div>
                            <?php if ($campaign['status'] === 'draft'): ?>
                                <div>Created: <?= date('M d, Y H:i', strtotime($campaign['created_at'])) ?></div>
                            <?php elseif ($campaign['job_status'] === 'processing' && $campaign['total'] > 0): ?>
                                <div><?= $campaign['progress'] ?> / <?= $campaign['total'] ?> sent</div>
                                <div><?= date('M d, Y H:i', strtotime($campaign['sent_at'])) ?></div>
                            <?php else: ?>
                                <div>Sent to <?= $campaign['sent_count'] ?> subscribers</div>
                                <div><?= date('M d, Y H:i', strtotime($campaign['sent_at'])) ?></div>
                            <?php endif; ?>
                            <div class="mt-2 flex gap-2">
                                <?php if ($campaign['status'] === 'draft'): ?>
                                    <a href="?edit=<?= $campaign['id'] ?>" 
                                       class="inline-flex items-center text-xs text-blue-600 hover:text-blue-800">
                                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                        </svg>
                                        Edit Draft
                                    </a>
                                <?php else: ?>
                                    <?php if ($campaign['job_id']): ?>
                                        <a href="campaign-status.php?job_id=<?= $campaign['job_id'] ?>" 
                                           class="inline-flex items-center text-xs text-blue-600 hover:text-blue-800">
                                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
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
</div>

<!-- Load Quill JS with error handling -->
<script>
console.log('Loading Quill library...');
var quillScript = document.createElement('script');
quillScript.src = 'https://cdn.quilljs.com/1.3.6/quill.js';
quillScript.onload = function() {
    console.log('Quill library loaded successfully!');
    console.log('Quill object:', typeof Quill !== 'undefined' ? Quill : 'NOT FOUND');
    initializeQuill();
};
quillScript.onerror = function() {
    console.error('Failed to load Quill library from CDN');
    alert('Failed to load editor. Please check your internet connection.');
};
document.head.appendChild(quillScript);
</script>

<script>
// Initialize Quill editor
var quill; // Make quill global

function initializeQuill() {
    console.log('initializeQuill called, Quill type:', typeof Quill);
    
    if (typeof Quill === 'undefined') {
        console.error('Quill is still not defined after load!');
        return;
    }
    
    if (document.getElementById('editor')) {
        quill = new Quill('#editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'align': [] }],
                    ['link'],
                    ['clean']
                ]
            },
            placeholder: 'Compose your email message...'
        });
        
        console.log('Quill editor initialized:', quill);
        
        // Load draft content if editing
        <?php if ($draftData): ?>
        quill.root.innerHTML = <?= json_encode($draftData['body']) ?>;
        <?php endif; ?>
    }
}

//Button action functions
function sendEmail() {
    if (!validateForm()) return;
    if (!confirm('Are you sure you want to send this email to all subscribers?')) return;
    
    document.getElementById('body-content').value = quill.root.innerHTML;
    document.getElementById('form-action').value = 'send';
    document.getElementById('compose-form').submit();
}

function saveDraft() {
    if (!validateForm()) return;
    
    document.getElementById('body-content').value = quill.root.innerHTML;
    document.getElementById('form-action').value = 'draft';
    document.getElementById('compose-form').submit();
}

function showPreview() {
    if (!validateForm()) return;
    
    const listId = document.getElementById('list_id').value;
    const subject = document.getElementById('subject').value;
    const body = quill.root.innerHTML;
    
    // Create preview modal
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4';
    modal.innerHTML = `
        <div class="bg-white rounded-lg max-w-2xl w-full max-h-[90vh] overflow-auto">
            <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold text-gray-900">Email Preview</h3>
                <button onclick="this.closest('.fixed').remove()" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
            <div class="p-6">
                <div class="mb-4 pb-4 border-b border-gray-200">
                    <div class="text-sm text-gray-600 mb-1">Subject:</div>
                    <div class="font-semibold text-gray-900">${subject}</div>
                </div>
                <div class="prose max-w-none">
                    ${body}
                </div>
            </div>
            <div class="p-6 border-t border-gray-200 bg-gray-50 flex justify-end gap-3">
                <button onclick="this.closest('.fixed').remove()" 
                        class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-100">
                    Close
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function validateForm() {
    const listId = document.getElementById('list_id').value;
    const subject = document.getElementById('subject').value;
    const text = quill.getText().trim();
    
    if (!listId) {
        alert('Please select a list');
        return false;
    }
    if (!subject.trim()) {
        alert('Please enter a subject');
        return false;
    }
    if (text.length === 0) {
        alert('Please enter a message');
        return false;
    }
    return true;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
