<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle AJAX manual verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify') {
    header('Content-Type: application/json');
    
    $subscriberId = $_POST['subscriber_id'] ?? 0;
    
    if ($subscriberId) {
        $stmt = $db->prepare("UPDATE subscribers SET verified = 1, verified_at = CURRENT_TIMESTAMP WHERE id = ?");
        if ($stmt->execute([$subscriberId])) {
            echo json_encode(['success' => true, 'message' => 'Subscriber verified']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to verify']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid subscriber ID']);
    }
    exit;
}

// Handle resend verification email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_verification') {
    @ob_clean(); // Clean any output before this
    header('Content-Type: application/json');
    
    try {
        $subscriberId = $_POST['subscriber_id'] ?? 0;
        
        if ($subscriberId) {
            $stmt = $db->prepare("SELECT * FROM subscribers WHERE id = ?");
            $stmt->execute([$subscriberId]);
            $subscriber = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($subscriber) {
                require_once __DIR__ . '/email-helper.php';
                if (sendVerificationEmail($subscriber['email'], $subscriber['verification_token'], $db)) {
                    echo json_encode(['success' => true, 'message' => 'Verification email sent']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to send email. Check SMTP settings.']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Subscriber not found']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid subscriber ID']);
        }
    } catch (Exception $e) {
        error_log("Resend verification error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle bulk resend verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_resend') {
    header('Content-Type: application/json');
    
    $subscriberIds = $_POST['subscriber_ids'] ?? [];
    
    if (!empty($subscriberIds) && is_array($subscriberIds)) {
        $placeholders = str_repeat('?,', count($subscriberIds) - 1) . '?';
        $stmt = $db->prepare("SELECT * FROM subscribers WHERE id IN ($placeholders) AND verified = 0");
        $stmt->execute($subscriberIds);
        $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        require_once __DIR__ . '/email-helper.php';
        $sentCount = 0;
        foreach ($subscribers as $subscriber) {
            if (sendVerificationEmail($subscriber['email'], $subscriber['verification_token'], $db)) {
                $sentCount++;
            }
        }
        
        echo json_encode(['success' => true, 'message' => "Verification emails sent to $sentCount subscriber(s)"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No subscribers selected']);
    }
    exit;
}

// Handle change subscriber lists
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_lists') {
    @ob_clean();
    header('Content-Type: application/json');
    
    try {
        $subscriberId = $_POST['subscriber_id'] ?? 0;
        $rawListIds = $_POST['list_ids'] ?? '[]';
        
        // Parse JSON if it's a string
        $listIds = is_string($rawListIds) ? json_decode($rawListIds, true) : $rawListIds;
        
        error_log("Change lists - subscriber: $subscriberId, lists: " . print_r($listIds, true));
        
        if ($subscriberId && is_array($listIds)) {
            // Remove all existing list associations
            $stmt = $db->prepare("DELETE FROM subscriber_lists WHERE subscriber_id = ?");
            $stmt->execute([$subscriberId]);
            
            // Add new associations
            if (!empty($listIds)) {
                $stmt = $db->prepare("INSERT INTO subscriber_lists (subscriber_id, list_id) VALUES (?, ?)");
                foreach ($listIds as $listId) {
                    $stmt->execute([$subscriberId, $listId]);
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Lists updated successfully']);
        } else {
            error_log("Invalid params - subscriberId: $subscriberId, listIds type: " . gettype($listIds));
            echo json_encode(['success' => false, 'message' => 'Invalid parameters', 'debug' => ['subscriber_id' => $subscriberId, 'list_ids' => $listIds]]);
        }
    } catch (Exception $e) {
        error_log("Error changing lists: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// Handle bulk add to list
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_add_to_list') {
    header('Content-Type: application/json');
    
    $subscriberIds = $_POST['subscriber_ids'] ?? [];
    $listId = $_POST['list_id'] ?? 0;
    
    if (!empty($subscriberIds) && is_array($subscriberIds) && $listId) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO subscriber_lists (subscriber_id, list_id) VALUES (?, ?)");
        $added = 0;
        foreach ($subscriberIds as $subscriberId) {
            if ($stmt->execute([$subscriberId, $listId])) {
                $added++;
            }
        }
        echo json_encode(['success' => true, 'message' => "$added subscriber(s) added to list"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    }
    exit;
}

// Handle bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete') {
    @ob_clean();
    header('Content-Type: application/json');
    
    try {
        $rawIds = $_POST['subscriber_ids'] ?? '[]';
        // Parse JSON if it's a string
        $subscriberIds = is_string($rawIds) ? json_decode($rawIds, true) : $rawIds;
        
        error_log("Bulk delete - subscriber IDs: " . print_r($subscriberIds, true));
        
        if (!empty($subscriberIds) && is_array($subscriberIds)) {
            // Enable foreign keys for CASCADE delete
            $db->exec("PRAGMA foreign_keys = ON");
            
            // Delete junction table entries first (just in case)
            $placeholders = str_repeat('?,', count($subscriberIds) - 1) . '?';
            $stmt = $db->prepare("DELETE FROM subscriber_lists WHERE subscriber_id IN ($placeholders)");
            $stmt->execute($subscriberIds);
            
            error_log("Deleted from subscriber_lists");
            
            // Delete subscribers
            $stmt = $db->prepare("DELETE FROM subscribers WHERE id IN ($placeholders)");
            if ($stmt->execute($subscriberIds)) {
                error_log("Deleted " . count($subscriberIds) . " subscribers");
                echo json_encode(['success' => true, 'message' => count($subscriberIds) . ' subscriber(s) deleted']);
            } else {
                error_log("Failed to delete from subscribers table");
                echo json_encode(['success' => false, 'message' => 'Failed to delete subscribers']);
            }
        } else {
            error_log("No subscribers selected or invalid format. Type: " . gettype($subscriberIds));
            echo json_encode(['success' => false, 'message' => 'No subscribers selected', 'debug' => ['raw' => $rawIds, 'parsed' => $subscriberIds]]);
        }
    } catch (Exception $e) {
        error_log("Delete error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

$listId = $_GET['list_id'] ?? null;
$status = $_GET['status'] ?? 'all';

// Get subscribers with their lists (many-to-many)
$query = "SELECT DISTINCT s.*, si.name as site_name,
          GROUP_CONCAT(l.name, ', ') as list_names,
          GROUP_CONCAT(l.id) as list_ids,
          GROUP_CONCAT(sl.unsubscribed) as list_unsubscribed_flags
          FROM subscribers s
          LEFT JOIN subscriber_lists sl ON s.id = sl.subscriber_id
          LEFT JOIN lists l ON sl.list_id = l.id
          LEFT JOIN sites si ON s.site_id = si.id
          WHERE 1=1";
$params = [];

if ($listId) {
    $query .= " AND EXISTS (SELECT 1 FROM subscriber_lists WHERE subscriber_id = s.id AND list_id = ?)";
    $params[] = $listId;
}

if ($status === 'verified') {
    $query .= " AND s.verified = 1 AND s.unsubscribed = 0";
} elseif ($status === 'pending') {
    $query .= " AND s.verified = 0 AND s.unsubscribed = 0";
} elseif ($status === 'unsubscribed') {
    $query .= " AND s.unsubscribed = 1";
}

$query .= " GROUP BY s.id ORDER BY s.subscribed_at DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get lists for filter
$stmt = $db->query("SELECT * FROM lists ORDER BY is_default DESC, name");
$lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $auth->getCurrentUser();
$pageTitle = 'Subscribers';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div x-data="{ selectedIds: [], showVerifyConfirm: false, verifyingId: null, verifyingEmail: '', showChangeListModal: false, changeListId: null, isBulkChange: false, showDeleteConfirm: false }">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center space-x-4">
            <h1 class="text-3xl font-bold text-gray-900">Subscribers</h1>
            <div x-show="selectedIds.length > 0" x-transition class="flex items-center space-x-2">
                <span class="text-sm text-gray-600" x-text="selectedIds.length + ' selected'"></span>
                <button @click="isBulkChange = true; showChangeListModal = true; setTimeout(() => document.querySelectorAll('.list-checkbox').forEach(cb => cb.checked = false), 100)" class="px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 transition text-sm">
                    Add to List
                </button>
                <button @click="bulkResend()" class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-sm">
                    Resend Verification
                </button>
                <button @click="showDeleteConfirm = true" class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 transition text-sm">
                    Delete Selected
                </button>
            </div>
        </div>
        <a href="import.php" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
            </svg>
            Import Subscribers
        </a>
    </div>

<!-- Filters -->
<div class="bg-white rounded-lg shadow p-6 mb-6">
    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by List</label>
            <select name="list_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="">All Lists</option>
                <?php foreach ($lists as $list): ?>
                    <option value="<?= $list['id'] ?>" <?= $listId == $list['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($list['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Filter by Status</label>
            <select name="status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="verified" <?= $status === 'verified' ? 'selected' : '' ?>>Verified</option>
                <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>Pending</option>
                <option value="unsubscribed" <?= $status === 'unsubscribed' ? 'selected' : '' ?>>Unsubscribed</option>
            </select>
        </div>
        <div class="flex items-end">
            <button type="submit" class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                Apply Filters
            </button>
        </div>
    </form>
</div>

<!-- Subscribers Table -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left">
                        <input type="checkbox" @change="toggleAll($event)" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">List</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Site</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subscribed At</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Verified At</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            <?php foreach ($subscribers as $sub): ?>
            <tr id="subscriber-<?= $sub['id'] ?>" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                    <input type="checkbox" 
                           :checked="selectedIds.includes(<?= $sub['id'] ?>)"
                           @change="toggleSelect(<?= $sub['id'] ?>)"
                           class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars($sub['email'] ?? '') ?></td>
                <td class="px-6 py-4 text-sm text-gray-600">
                    <?php if (!empty($sub['list_names'])): ?>
                        <?php 
                        $lists_array = explode(', ', $sub['list_names']); 
                        $unsub_flags = !empty($sub['list_unsubscribed_flags']) ? explode(',', $sub['list_unsubscribed_flags']) : [];
                        ?>
                        <?php foreach ($lists_array as $index => $list): ?>
                            <?php 
                            $isListUnsubscribed = isset($unsub_flags[$index]) && $unsub_flags[$index] == '1';
                            ?>
                            <?php if ($sub['unsubscribed']): ?>
                                <!-- Globally unsubscribed - show grayed out -->
                                <span class="inline-block px-2 py-1 bg-gray-100 text-gray-500 text-xs rounded mr-1 mb-1 line-through"><?= htmlspecialchars($list) ?></span>
                            <?php elseif ($isListUnsubscribed): ?>
                                <!-- Unsubscribed from this specific list - show red -->
                                <span class="inline-block px-2 py-1 bg-red-100 text-red-700 text-xs rounded mr-1 mb-1">
                                    <?= htmlspecialchars($list) ?>
                                    <span class="text-red-500">✕</span>
                                </span>
                            <?php else: ?>
                                <!-- Active subscription -->
                                <span class="inline-block px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded mr-1 mb-1"><?= htmlspecialchars($list) ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php if ($sub['unsubscribed']): ?>
                            <span class="text-gray-400">Unsubscribed from all lists</span>
                        <?php else: ?>
                            <span class="text-gray-400">No lists</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= htmlspecialchars($sub['site_name'] ?? 'N/A') ?></td>
                <td class="px-6 py-4 whitespace-nowrap status-cell">
                    <?php if ($sub['unsubscribed']): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">Unsubscribed</span>
                    <?php elseif ($sub['verified']): ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Verified</span>
                    <?php else: ?>
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                    <?php endif; ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= date('Y-m-d H:i', strtotime($sub['subscribed_at'])) ?></td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 verified-at-cell">
                    <?= $sub['verified_at'] ? date('Y-m-d H:i', strtotime($sub['verified_at'])) : '-' ?>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <div class="flex space-x-2">
                        <?php if (!$sub['verified']): ?>
                            <button @click="showVerifyConfirm = true; verifyingId = <?= $sub['id'] ?>; verifyingEmail = '<?= htmlspecialchars($sub['email'], ENT_QUOTES) ?>'"
                                    class="px-3 py-1 bg-blue-600 text-white rounded hover:bg-blue-700 transition text-xs">
                                Verify
                            </button>
                            <button @click="resendVerification(<?= $sub['id'] ?>, '<?= htmlspecialchars($sub['email'], ENT_QUOTES) ?>')"
                                    class="px-3 py-1 bg-gray-600 text-white rounded hover:bg-gray-700 transition text-xs">
                                Resend
                            </button>
                        <?php endif; ?>
                        <button @click="$openListModal(<?= $sub['id'] ?>, $event)"
                                class="px-3 py-1 bg-purple-600 text-white rounded hover:bg-purple-700 transition text-xs"
                                data-list-ids="<?= htmlspecialchars($sub['list_ids'] ?? '') ?>">
                            Manage Lists
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Verify Confirmation Modal -->
<div x-show="showVerifyConfirm" 
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 overflow-y-auto" 
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div @click="showVerifyConfirm = false" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Verify Subscriber</h3>
            <p class="text-gray-600 mb-6">Are you sure you want to manually verify <strong x-text="verifyingEmail"></strong>?</p>
            <div class="flex justify-end space-x-3">
                <button @click="showVerifyConfirm = false" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button @click="verifySubscriber()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Verify</button>
            </div>
        </div>
    </div>
</div>

<!-- Change List Modal -->
<div x-show="showChangeListModal" 
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 overflow-y-auto" 
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div @click="showChangeListModal = false" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4" x-text="isBulkChange ? 'Add to List' : 'Manage Lists'"></h3>
            <p class="text-gray-600 mb-4" x-text="isBulkChange ? 'Add ' + selectedIds.length + ' subscriber(s) to:' : 'Select which lists this subscriber belongs to:'"></p>
            <div class="mb-6 max-h-60 overflow-y-auto border border-gray-200 rounded-lg p-3">
                <?php foreach ($lists as $list): ?>
                <label class="flex items-center py-2 hover:bg-gray-50 px-2 rounded cursor-pointer">
                    <input type="checkbox" 
                           value="<?= $list['id'] ?>" 
                           class="list-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 mr-3">
                    <span class="text-sm text-gray-900"><?= htmlspecialchars($list['name']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="flex justify-end space-x-3">
                <button @click="showChangeListModal = false" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button @click="saveLists()" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition" x-text="isBulkChange ? 'Add to List' : 'Save Lists'"></button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div x-show="showDeleteConfirm" 
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 overflow-y-auto" 
     style="display: none;">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div @click="showDeleteConfirm = false" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
        <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
            <div class="flex items-center justify-center w-12 h-12 mx-auto mb-4 bg-red-100 rounded-full">
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                </svg>
            </div>
            <h3 class="text-lg font-semibold text-gray-900 text-center mb-2">Delete Subscribers</h3>
            <p class="text-gray-600 text-center mb-6">
                Are you sure you want to delete <strong x-text="selectedIds.length"></strong> subscriber(s)? 
                <br><span class="text-sm text-red-600">This action cannot be undone.</span>
            </p>
            <div class="flex justify-end space-x-3">
                <button @click="showDeleteConfirm = false" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                <button @click="confirmDelete()" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">Delete</button>
            </div>
        </div>
    </div>
</div>

</div><!-- Close Alpine.js wrapper -->

<script>
function toggleAll(event) {
    const component = Alpine.$data(event.target.closest('[x-data]'));
    const checkboxes = document.querySelectorAll('tbody input[type="checkbox"]');
    if (event.target.checked) {
        component.selectedIds = Array.from(checkboxes).map(cb => parseInt(cb.closest('tr').id.replace('subscriber-', '')));
    } else {
        component.selectedIds = [];
    }
}

function toggleSelect(id) {
    return function(event) {
        const component = Alpine.$data(event.target.closest('[x-data]'));
        if (event.target.checked) {
            if (!component.selectedIds.includes(id)) {
                component.selectedIds.push(id);
            }
        } else {
            component.selectedIds = component.selectedIds.filter(i => i !== id);
        }
    };
}

function verifySubscriber() {
    return function() {
        const component = Alpine.$data(this.$el.closest('[x-data]'));
        const subscriberId = component.verifyingId;
        
        component.showVerifyConfirm = false;
        
        fetch('subscribers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=verify&subscriber_id=' + subscriberId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const row = document.getElementById('subscriber-' + subscriberId);
                const statusCell = row.querySelector('.status-cell');
                const verifiedAtCell = row.querySelector('.verified-at-cell');
                const actionCell = row.querySelector('td:last-child');
                
                statusCell.innerHTML = '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Verified</span>';
                
                const now = new Date();
                const timestamp = now.getFullYear() + '-' + 
                    String(now.getMonth() + 1).padStart(2, '0') + '-' + 
                    String(now.getDate()).padStart(2, '0') + ' ' +
                    String(now.getHours()).padStart(2, '0') + ':' + 
                    String(now.getMinutes()).padStart(2, '0');
                verifiedAtCell.textContent = timestamp;
                actionCell.innerHTML = '<span class="text-gray-400">-</span>';
                
                // Show success message
                const alert = document.createElement('div');
                alert.className = 'fixed top-4 right-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-lg';
                alert.textContent = 'Subscriber verified successfully!';
                document.body.appendChild(alert);
                setTimeout(() => alert.remove(), 3000);
            } else {
                alert(data.message || 'Failed to verify');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    };
}

function resendVerification(subscriberId, email) {
    if (!confirm('Resend verification email to ' + email + '?')) return;
    
    fetch('subscribers.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=resend_verification&subscriber_id=' + subscriberId
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }
        return response.text();
    })
    .then(text => {
        console.log('Response text:', text);
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('JSON parse error:', e);
            throw new Error('Invalid JSON response');
        }
    })
    .then(data => {
        console.log('Parsed data:', data);
        const alertDiv = document.createElement('div');
        if (data.success) {
            alertDiv.className = 'fixed top-4 right-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-lg';
        } else {
            alertDiv.className = 'fixed top-4 right-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-lg';
        }
        alertDiv.textContent = data.message || 'Unknown response';
        document.body.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 3000);
    })
    .catch(error => {
        console.error('Fetch error:', error);
        const alertDiv = document.createElement('div');
        alertDiv.className = 'fixed top-4 right-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-lg';
        alertDiv.textContent = 'Error: ' + error.message;
        document.body.appendChild(alertDiv);
        setTimeout(() => alertDiv.remove(), 3000);
    });
}

function bulkResend() {
    return function() {
        const component = Alpine.$data(this.$el.closest('[x-data]'));
        if (component.selectedIds.length === 0) return;
        
        if (!confirm('Resend verification emails to ' + component.selectedIds.length + ' subscriber(s)?')) return;
        
        fetch('subscribers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=bulk_resend&subscriber_ids=' + JSON.stringify(component.selectedIds)
        })
        .then(response => response.json())
        .then(data => {
            component.selectedIds = [];
            
            const alert = document.createElement('div');
            if (data.success) {
                alert.className = 'fixed top-4 right-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-lg';
            } else {
                alert.className = 'fixed top-4 right-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-lg';
            }
            alert.textContent = data.message;
            document.body.appendChild(alert);
            setTimeout(() => alert.remove(), 3000);
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred. Please try again.');
        });
    };
}

function confirmDelete() {
    return function() {
        const component = Alpine.$data(this.$el.closest('[x-data]'));
        if (component.selectedIds.length === 0) return;
        
        console.log('Deleting subscriber IDs:', component.selectedIds);
        
        // Close modal
        component.showDeleteConfirm = false;
        
        fetch('subscribers.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=bulk_delete&subscriber_ids=' + JSON.stringify(component.selectedIds)
        })
        .then(response => {
            console.log('Delete response status:', response.status);
            return response.text();
        })
        .then(text => {
            console.log('Delete response text:', text);
            return JSON.parse(text);
        })
        .then(data => {
            console.log('Delete parsed response:', data);
            if (data.success) {
                // Remove deleted rows from DOM
                component.selectedIds.forEach(id => {
                    document.getElementById('subscriber-' + id)?.remove();
                });
                component.selectedIds = [];
                
                // Show success message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'fixed top-4 right-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-lg z-50';
                alertDiv.textContent = data.message;
                document.body.appendChild(alertDiv);
                setTimeout(() => alertDiv.remove(), 3000);
            } else {
                // Show error message
                const alertDiv = document.createElement('div');
                alertDiv.className = 'fixed top-4 right-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-lg z-50';
                alertDiv.textContent = data.message || 'Failed to delete subscribers';
                document.body.appendChild(alertDiv);
                setTimeout(() => alertDiv.remove(), 3000);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const alertDiv = document.createElement('div');
            alertDiv.className = 'fixed top-4 right-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-lg z-50';
            alertDiv.textContent = 'An error occurred. Please try again.';
            document.body.appendChild(alertDiv);
            setTimeout(() => alertDiv.remove(), 3000);
        });
    };
}

// Pre-populate checkboxes when modal opens
document.addEventListener('alpine:init', () => {
    Alpine.magic('openListModal', () => {
        return (subscriberId, event) => {
            const component = Alpine.$data(event.target.closest('[x-data]'));
            component.changeListId = subscriberId;
            component.isBulkChange = false;
            component.showChangeListModal = true;
            
            // Get current list IDs from data attribute
            setTimeout(() => {
                const listIds = event.target.dataset.listIds ? event.target.dataset.listIds.split(',') : [];
                document.querySelectorAll('.list-checkbox').forEach(checkbox => {
                    checkbox.checked = listIds.includes(checkbox.value);
                });
            }, 100);
        };
    });
});

function saveLists() {
    return function() {
        const component = Alpine.$data(this.$el.closest('[x-data]'));
        const selectedListIds = Array.from(document.querySelectorAll('.list-checkbox:checked')).map(cb => cb.value);
        
        if (component.isBulkChange) {
            // Bulk add to lists
            if (component.selectedIds.length === 0) return;
            if (selectedListIds.length === 0) {
                alert('Please select at least one list');
                return;
            }
            
            // Add to each selected list
            let completed = 0;
            const total = selectedListIds.length;
            
            selectedListIds.forEach(listId => {
                fetch('subscribers.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=bulk_add_to_list&list_id=' + listId + '&subscriber_ids=' + JSON.stringify(component.selectedIds)
                })
                .then(response => response.json())
                .then(data => {
                    completed++;
                    if (completed === total) {
                        component.showChangeListModal = false;
                        component.selectedIds = [];
                        
                        const alertDiv = document.createElement('div');
                        alertDiv.className = 'fixed top-4 right-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-lg';
                        alertDiv.textContent = 'Subscribers added to ' + total + ' list(s)';
                        document.body.appendChild(alertDiv);
                        
                        // Reload after brief delay
                        setTimeout(() => window.location.reload(), 800);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                });
            });
        } else {
            // Individual subscriber - update all lists
            console.log('Saving lists for subscriber:', component.changeListId);
            console.log('Selected list IDs:', selectedListIds);
            
            const formData = new URLSearchParams();
            formData.append('action', 'change_lists');
            formData.append('subscriber_id', component.changeListId);
            formData.append('list_ids', JSON.stringify(selectedListIds));
            
            console.log('Form data:', formData.toString());
            
            fetch('subscribers.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(response => {
                console.log('Response status:', response.status);
                return response.text();
            })
            .then(text => {
                console.log('Response text:', text);
                return JSON.parse(text);
            })
            .then(data => {
                console.log('Parsed response:', data);
                component.showChangeListModal = false;
                
                if (data.success) {
                    // Show success message and reload immediately
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'fixed top-4 right-4 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg shadow-lg';
                    alertDiv.textContent = data.message || 'Lists updated successfully';
                    document.body.appendChild(alertDiv);
                    
                    // Reload page after brief delay to show message
                    setTimeout(() => window.location.reload(), 800);
                } else {
                    // Show error message
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'fixed top-4 right-4 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-lg shadow-lg';
                    alertDiv.textContent = data.message || 'Failed to update lists';
                    document.body.appendChild(alertDiv);
                    setTimeout(() => alertDiv.remove(), 3000);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    };
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
