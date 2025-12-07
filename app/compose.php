<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf-helper.php';

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
    requireCSRFToken();
    $action = $_POST['action'] ?? 'send';
    $listIds = $_POST['list_ids'] ?? [];
    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $editId = $_POST['edit_id'] ?? null;
    $scheduledAt = $_POST['scheduled_at'] ?? null;

    if (empty($listIds) || empty($subject) || empty($body)) {
        $error = 'All fields are required (including at least one list)';
    } else {
        if ($action === 'preview') {
            // Store preview data (use first list ID)
            $firstListId = is_array($listIds) ? $listIds[0] : $listIds;
            $previewData = [
                'list_id' => $firstListId,
                'subject' => $subject,
                'body' => $body
            ];
        } elseif ($action === 'draft') {
            // Save as draft - store first list ID for backwards compatibility
            $firstListId = is_array($listIds) ? $listIds[0] : $listIds;
            $listIdsString = is_array($listIds) ? implode(',', $listIds) : $listIds;

            if ($editId) {
                $stmt = $db->prepare("UPDATE email_campaigns SET list_id = ?, list_ids = ?, subject = ?, body = ? WHERE id = ? AND status = 'draft'");
                $stmt->execute([$firstListId, $listIdsString, $subject, $body, $editId]);
                // Redirect back to edit page
                header("Location: compose.php?edit=" . $editId . "&success=updated");
                exit;
            } else {
                $stmt = $db->prepare("INSERT INTO email_campaigns (list_id, list_ids, subject, body, status, created_at) VALUES (?, ?, ?, ?, 'draft', CURRENT_TIMESTAMP)");
                $stmt->execute([$firstListId, $listIdsString, $subject, $body]);
                $draftId = $db->lastInsertId();
                // Redirect to edit page for new draft
                header("Location: compose.php?edit=" . $draftId . "&success=saved");
                exit;
            }
        } elseif ($action === 'send' || $action === 'schedule') {
            // Get verified subscribers count from all selected lists
            $listIdsArray = is_array($listIds) ? $listIds : [$listIds];
            $placeholders = str_repeat('?,', count($listIdsArray) - 1) . '?';
            $stmt = $db->prepare("SELECT COUNT(DISTINCT s.id) as count 
                                  FROM subscribers s 
                                  INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id 
                                  WHERE sl.list_id IN ($placeholders) AND s.verified = 1 AND s.unsubscribed = 0 AND sl.unsubscribed = 0");
            $stmt->execute($listIdsArray);
            $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            if ($count == 0) {
                $error = 'No verified subscribers in the selected list(s)';
            } else {
                // Create or update campaign - store first list ID for backwards compatibility
                $firstListId = $listIdsArray[0];
                $status = $action === 'schedule' ? 'scheduled' : 'sent';
                
                // Format scheduled date if present
                $formattedScheduledAt = null;
                if ($action === 'schedule' && $scheduledAt) {
                    // Convert input time (which is in site timezone) to UTC for storage
                    // This ensures consistent comparison with CURRENT_TIMESTAMP (UTC) in worker
                    try {
                        // Create DateTime in the configured timezone
                        $siteTimezone = date_default_timezone_get();
                        $dt = new DateTime($scheduledAt, new DateTimeZone($siteTimezone));
                        
                        // Convert to UTC
                        $dt->setTimezone(new DateTimeZone('UTC'));
                        $formattedScheduledAt = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) {
                        // Fallback if date parsing fails
                        $formattedScheduledAt = date('Y-m-d H:i:s', strtotime($scheduledAt)); 
                    }
                }

                if ($editId) {
                    $stmt = $db->prepare("UPDATE email_campaigns SET list_id = ?, subject = ?, body = ?, status = ?, sent_count = 0, sent_at = NULL WHERE id = ?");
                    $stmt->execute([$firstListId, $subject, $body, $status, $editId]);
                    $campaignId = $editId;
                } else {
                    $stmt = $db->prepare("INSERT INTO email_campaigns (list_id, subject, body, status, sent_count, sent_at) VALUES (?, ?, ?, ?, 0, NULL)");
                    $stmt->execute([$firstListId, $subject, $body, $status]);
                    $campaignId = $db->lastInsertId();
                }

                // Create queue job with all selected list IDs (stored as comma-separated for worker)
                $listIdsString = implode(',', $listIdsArray);
                
                if ($action === 'schedule' && $formattedScheduledAt) {
                    $stmt = $db->prepare("INSERT INTO queue_jobs (campaign_id, list_ids, status, scheduled_at) VALUES (?, ?, 'scheduled', ?)");
                    $stmt->execute([$campaignId, $listIdsString, $formattedScheduledAt]);
                    $jobId = $db->lastInsertId();
                    
                    // Calculate delay for background waiter (if within 24 hours)
                    $delay = strtotime($formattedScheduledAt) - time();
                    if ($delay > 0 && $delay < 86400) {
                        // Spawn a sleeping process that will wake up and trigger the worker
                        // Use robust argument passing to sh to handle paths with spaces (e.g. Herd)
                        $workerPath = __DIR__ . "/worker.php";
                        $phpBinary = PHP_BINARY;
                        
                        $cmd = "nohup sh -c 'sleep \"$0\" && exec \"$1\" \"$2\"' " . 
                               (int)$delay . " " . 
                               escapeshellarg($phpBinary) . " " . 
                               escapeshellarg($workerPath) . 
                               " < /dev/null > /dev/null 2>&1 &";
                               
                        exec($cmd);
                    }
                } else {
                    $stmt = $db->prepare("INSERT INTO queue_jobs (campaign_id, list_ids) VALUES (?, ?)");
                    $stmt->execute([$campaignId, $listIdsString]);
                    $jobId = $db->lastInsertId();

                    // Trigger worker immediately
                    $workerPath = __DIR__ . "/worker.php";
                    $phpBinary = PHP_BINARY;
                    $workerCmd = "nohup " . escapeshellarg($phpBinary) . " " . escapeshellarg($workerPath) . " < /dev/null > /dev/null 2>&1 &";
                    exec($workerCmd);
                }

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

// Create lookup array for list names
$allLists = [];
foreach ($lists as $l) {
    $allLists[$l['id']] = $l['name'];
}

// Get recent campaigns (drafts and sent)
$stmt = $db->query("SELECT ec.*, l.name as list_name, 
                    qj.id as job_id, qj.status as job_status, qj.progress, qj.total
                    FROM email_campaigns ec 
                    LEFT JOIN lists l ON ec.list_id = l.id 
                    LEFT JOIN queue_jobs qj ON ec.id = qj.campaign_id
                    ORDER BY 
                        CASE WHEN ec.status = 'draft' THEN 0 ELSE 1 END,
                        COALESCE(ec.sent_at, ec.created_at) DESC 
                    LIMIT 5");
$campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $auth->getCurrentUser();
$pageTitle = 'Compose Email';
$additionalHead = '
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<style>
    .ql-editor {
    min-height: 500px;
    }

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

<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Compose Email</h1>
    </div>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?= htmlspecialchars($success) ?></span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8" x-data="listSelector(
        <?= htmlspecialchars(json_encode(array_map(function ($l) {
            return [
                'id' => $l['id'],
                'name' => $l['name'],
                'count' => $l['subscriber_count']
            ];
        }, $lists))) ?>,
        <?= htmlspecialchars(json_encode($draftData ?
            (!empty($draftData['list_ids']) ? explode(',', $draftData['list_ids']) : [$draftData['list_id']])
            : [])) ?>
    )">
        <!-- Main Form (Left) -->
        <div class="lg:col-span-2">
            <form id="compose-form" method="POST" class="bg-white rounded-lg shadow p-6">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" id="form-action" value="send">
                <input type="hidden" name="body" id="body-content">
                <input type="hidden" name="scheduled_at" id="scheduled-at-input">
                <?php if ($campaignId): ?>
                    <input type="hidden" name="edit_id" value="<?= $campaignId ?>">
                <?php endif; ?>

                <!-- Hidden Inputs for List Selection -->
                <template x-for="id in selected" :key="id">
                    <input type="hidden" name="list_ids[]" :value="id">
                </template>

                <div class="mb-6">
                    <label for="subject" class="block text-sm font-medium text-gray-700 mb-2">Subject Line</label>
                    <input type="text" name="subject" id="subject" required
                        class="w-full rounded-lg border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                        value="<?= htmlspecialchars($draftData['subject'] ?? '') ?>"
                        placeholder="Enter email subject...">
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Email Content</label>
                    <div id="editor" class="h-[500px] mb-4"></div>
                </div>
            </form>
        </div>

        <!-- Action Buttons Sidebar (Right) -->
        <div class="lg:col-span-1">
            <div class="lg:sticky lg:top-6 z-50">
                <div class="mb-6 relative z-50">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Select Lists</label>
                    <?php if (empty($lists)): ?>
                        <p class="text-gray-500 text-sm p-4 bg-gray-50 rounded-lg">No lists available</p>
                    <?php else: ?>
                        <div class="relative z-50">

                            <!-- Dropdown Button -->
                            <button type="button" @click="open = !open" @click.outside="open = false"
                                class="w-full bg-white border border-gray-300 rounded-lg px-4 py-2 text-left focus:ring-2 focus:ring-blue-500 focus:border-blue-500 flex justify-between items-center">
                                <span x-text="selectedSummary" class="block truncate"></span>
                                <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7"></path>
                                </svg>
                            </button>

                            <!-- Dropdown Menu -->
                            <div x-show="open" x-transition:enter="transition ease-out duration-100"
                                x-transition:enter-start="transform opacity-0 scale-95"
                                x-transition:enter-end="transform opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-75"
                                x-transition:leave-start="transform opacity-100 scale-100"
                                x-transition:leave-end="transform opacity-0 scale-95"
                                class="absolute z-[9999] mt-1 w-full bg-white shadow-lg max-h-60 rounded-md py-1 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm"
                                style="display: none;">
                                <template x-for="list in lists" :key="list.id">
                                    <div @click="toggle(list.id)"
                                        class="cursor-pointer select-none relative py-2 pl-3 pr-9 hover:bg-blue-50 transition-colors">
                                        <div class="flex items-center">
                                            <input type="checkbox" :checked="isSelected(list.id)"
                                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded mr-3 pointer-events-none">
                                            <span class="font-normal block truncate"
                                                :class="{ 'font-semibold': isSelected(list.id) }">
                                                <span x-text="list.name"></span>
                                                <span x-text="'(' + list.count + ' verified)'"
                                                    class="text-gray-500 text-xs ml-1"></span>
                                            </span>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <!-- Summary Stats -->
                            <p class="text-xs text-gray-600 mt-2 font-medium">
                                <span x-text="selected.length"></span> list(s) selected •
                                <span x-text="totalSubscribers"></span> unique subscribers
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="relative z-0">
                    <h3 class="text-sm font-semibold text-gray-700 mb-3">Actions</h3>
                    <div class="flex flex-col gap-3">
                        <button type="button" onclick="sendEmail()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z">
                                </path>
                            </svg>
                            Send Now
                        </button>

                        <button type="button" onclick="showScheduleModal()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z">
                                </path>
                            </svg>
                            Schedule
                        </button>

                        <button type="button" onclick="saveDraft()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4">
                                </path>
                            </svg>
                            Save Draft
                        </button>

                        <button type="button" onclick="showPreview()"
                            class="w-full inline-flex items-center justify-center px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z">
                                </path>
                            </svg>
                            Preview
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Schedule Modal -->
        <template x-teleport="body">
            <div x-show="showSchedule" x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4"
                style="display: none;">
                <div @click.away="showSchedule = false" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform scale-95"
                    x-transition:enter-end="opacity-100 transform scale-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 transform scale-100"
                    x-transition:leave-end="opacity-0 transform scale-95"
                    class="bg-white rounded-lg max-w-md w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Schedule Campaign</h3>
                    <p class="text-gray-600 mb-4">Choose when to send this campaign:</p>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Date & Time</label>
                        <input type="datetime-local" id="schedule-datetime" 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-2">
                            <span class="text-yellow-600 font-medium">Note:</span> Without a system cron job, the email will be sent when the next visitor accesses the site after this time.
                        </p>
                    </div>

                    <div class="flex gap-3 justify-end">
                        <button @click="showSchedule = false"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                            Cancel
                        </button>
                        <button @click="showSchedule = false; confirmSchedule()"
                            class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition">
                            Schedule
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Send Confirmation Modal -->
        <template x-teleport="body">
            <div x-show="showSendConfirm" x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4"
                style="display: none;">
                <div @click.away="showSendConfirm = false" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform scale-95"
                    x-transition:enter-end="opacity-100 transform scale-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 transform scale-100"
                    x-transition:leave-end="opacity-0 transform scale-95"
                    class="bg-white rounded-lg max-w-md w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Confirm Send</h3>
                    <p class="text-gray-600 mb-6">Are you sure you want to send this email to all subscribers in the
                        selected lists?</p>
                    <div class="flex gap-3 justify-end">
                        <button @click="showSendConfirm = false"
                            class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                            Cancel
                        </button>
                        <button @click="showSendConfirm = false; confirmSend()"
                            class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            Send Now
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <!-- Error Modal -->
        <template x-teleport="body">
            <div x-show="showError" x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4"
                style="display: none;">
                <div @click.away="showError = false" x-transition:enter="transition ease-out duration-300"
                    x-transition:enter-start="opacity-0 transform scale-95"
                    x-transition:enter-end="opacity-100 transform scale-100"
                    x-transition:leave="transition ease-in duration-200"
                    x-transition:leave-start="opacity-100 transform scale-100"
                    x-transition:leave-end="opacity-0 transform scale-95"
                    class="bg-white rounded-lg max-w-md w-full p-6 shadow-xl">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Error</h3>
                    <p class="text-gray-600 mb-6" x-text="errorMessage"></p>
                    <div class="flex justify-end">
                        <button @click="showError = false"
                            class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                            OK
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>

<!-- Load Quill JS with error handling -->
<script>
    console.log('Loading Quill library...');
    var quillScript = document.createElement('script');
    quillScript.src = 'https://cdn.quilljs.com/1.3.6/quill.js';
    quillScript.onload = function () {
        console.log('Quill library loaded successfully!');
        console.log('Quill object:', typeof Quill !== 'undefined' ? Quill : 'NOT FOUND');
        initializeQuill();
    };
    quillScript.onerror = function () {
        console.error('Failed to load Quill library from CDN');
        alert('Failed to load editor. Please check your internet connection.');
    };
    document.head.appendChild(quillScript);
</script>

<script>
    // Initialize Quill editor
    var quill; // Make quill global

    document.addEventListener('alpine:init', () => {
        Alpine.data('listSelector', (initialLists, initialSelected) => ({
            open: false,
            lists: initialLists,
            selected: initialSelected.map(id => parseInt(id)).filter(id => !isNaN(id)),
            showSendConfirm: false,
            showSchedule: false,
            showError: false,
            errorMessage: '',

            toggle(id) {
                id = parseInt(id);
                if (this.selected.includes(id)) {
                    this.selected = this.selected.filter(item => item !== id);
                } else {
                    this.selected.push(id);
                }
            },

            isSelected(id) {
                return this.selected.includes(parseInt(id));
            },

            get selectedSummary() {
                if (this.selected.length === 0) return 'Select lists...';
                if (this.selected.length === 1) {
                    const list = this.lists.find(l => l.id === this.selected[0]);
                    return list ? list.name : 'Unknown List';
                }
                return this.selected.length + ' lists selected';
            },

            get totalSubscribers() {
                return this.lists
                    .filter(l => this.selected.includes(l.id))
                    .reduce((sum, l) => sum + parseInt(l.count), 0);
            }
        }));
    });

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
                    toolbar: {
                        container: [
                            [{ 'header': [1, 2, 3, false] }],
                            ['bold', 'italic', 'underline'],
                            [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                            [{ 'align': [] }],
                            ['link', 'image'],
                            ['clean']
                        ],
                        handlers: {
                            image: imageHandler
                        }
                    }
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

    function imageHandler() {
        const input = document.createElement('input');
        input.setAttribute('type', 'file');
        input.setAttribute('accept', 'image/*');
        input.click();

        input.onchange = async () => {
            const file = input.files[0];
            if (!file) return;

            const formData = new FormData();
            formData.append('image', file);

            try {
                const range = quill.getSelection(true);

                // Show loading placeholder
                quill.insertText(range.index, 'Uploading image...', 'bold', true);

                const response = await fetch('upload_image.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error('Upload failed');
                }

                const data = await response.json();

                // Remove placeholder
                quill.deleteText(range.index, 16);

                // Insert image
                quill.insertEmbed(range.index, 'image', data.url);

            } catch (error) {
                console.error('Error uploading image:', error);
                alert('Failed to upload image');
                // Remove placeholder on error
                const range = quill.getSelection(true);
                quill.deleteText(range.index, 16);
            }
        };
    }

    //Button action functions
    function sendEmail() {
        if (!validateForm()) return;

        // Get Alpine data from the grid container
        const gridContainer = document.querySelector('.grid.grid-cols-1.lg\\:grid-cols-3');
        if (gridContainer && typeof Alpine !== 'undefined') {
            const alpineData = Alpine.$data(gridContainer);
            alpineData.showSendConfirm = true;
        } else {
            console.error('Could not access Alpine data');
        }
    }

    function showScheduleModal() {
        if (!validateForm()) return;

        const gridContainer = document.querySelector('.grid.grid-cols-1.lg\\:grid-cols-3');
        if (gridContainer && typeof Alpine !== 'undefined') {
            const alpineData = Alpine.$data(gridContainer);
            alpineData.showSchedule = true;
            
            // Set default time to tomorrow same time
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            tomorrow.setMinutes(tomorrow.getMinutes() - tomorrow.getTimezoneOffset());
            
            // Wait for modal to open
            setTimeout(() => {
                document.getElementById('schedule-datetime').value = tomorrow.toISOString().slice(0, 16);
            }, 100);
        }
    }

    function confirmSchedule() {
        const scheduleTime = document.getElementById('schedule-datetime').value;
        if (!scheduleTime) {
            alert('Please select a time');
            return;
        }
        
        document.getElementById('body-content').value = quill.root.innerHTML;
        document.getElementById('form-action').value = 'schedule';
        document.getElementById('scheduled-at-input').value = scheduleTime;
        document.getElementById('compose-form').submit();
    }

    function confirmSend() {
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
        const listInputs = document.querySelectorAll('input[name="list_ids[]"]');
        const subject = document.getElementById('subject').value;
        const text = quill.getText().trim();

        // Get Alpine data from the grid container
        const gridContainer = document.querySelector('.grid.grid-cols-1.lg\\:grid-cols-3');
        const alpineData = gridContainer && typeof Alpine !== 'undefined' ? Alpine.$data(gridContainer) : null;

        if (listInputs.length === 0) {
            if (alpineData) {
                alpineData.errorMessage = 'Please select at least one list';
                alpineData.showError = true;
            } else {
                alert('Please select at least one list');
            }
            return false;
        }
        if (!subject.trim()) {
            if (alpineData) {
                alpineData.errorMessage = 'Please enter a subject';
                alpineData.showError = true;
            } else {
                alert('Please enter a subject');
            }
            return false;
        }
        if (text.length === 0) {
            if (alpineData) {
                alpineData.errorMessage = 'Please enter a message';
                alpineData.showError = true;
            } else {
                alert('Please enter a message');
            }
            return false;
        }
        return true;
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
```