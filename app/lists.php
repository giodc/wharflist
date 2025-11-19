<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            
            if (empty($name)) {
                $_SESSION['error'] = 'List name is required';
            } else {
                $stmt = $db->prepare("INSERT INTO lists (name, description) VALUES (?, ?)");
                if ($stmt->execute([$name, $description])) {
                    $_SESSION['success'] = 'List created successfully';
                }
            }
            header('Location: lists.php');
            exit;
        } elseif ($_POST['action'] === 'delete') {
            $listId = $_POST['list_id'] ?? 0;
            
            // Check if it's the default list
            $stmt = $db->prepare("SELECT is_default FROM lists WHERE id = ?");
            $stmt->execute([$listId]);
            $list = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($list && !$list['is_default']) {
                $stmt = $db->prepare("DELETE FROM lists WHERE id = ?");
                if ($stmt->execute([$listId])) {
                    $_SESSION['success'] = 'List deleted successfully';
                }
            } else {
                $_SESSION['error'] = 'Cannot delete default list';
            }
            header('Location: lists.php');
            exit;
        }
    }
}

// Get messages from session
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}

// Get all lists with subscriber counts (using junction table)
$stmt = $db->query("SELECT l.* FROM lists l ORDER BY l.is_default DESC, l.created_at DESC");
$lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($lists as &$list) {
    // Count verified subscribers for each list (exclude globally and list-specific unsubscribes)
    $stmt = $db->prepare("SELECT COUNT(DISTINCT s.id) as count 
                          FROM subscribers s
                          INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id
                          WHERE sl.list_id = ? AND s.verified = 1 AND s.unsubscribed = 0 AND sl.unsubscribed = 0");
    $stmt->execute([$list['id']]);
    $list['subscriber_count'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

$user = $auth->getCurrentUser();
$pageTitle = 'Lists';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div x-data="{ showModal: false }">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Lists</h1>
        <button @click="showModal = true" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Create List
        </button>
    </div>

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

    <!-- Lists Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($lists as $list): ?>
        <div class="bg-white rounded-lg shadow p-6">
            <div class="flex items-start justify-between mb-3">
                <h3 class="text-lg font-semibold text-gray-900">
                    <?= htmlspecialchars($list['name']) ?>
                </h3>
                <?php if ($list['is_default']): ?>
                    <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Default</span>
                <?php endif; ?>
            </div>
            <p class="text-gray-600 text-sm mb-4"><?= htmlspecialchars($list['description']) ?></p>
            <div class="flex items-center text-gray-700 mb-4">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                </svg>
                <strong class="text-lg"><?= $list['subscriber_count'] ?></strong>
                <span class="ml-1 text-sm">subscribers</span>
            </div>
            <div class="flex items-center space-x-2">
                <a href="subscribers.php?list_id=<?= $list['id'] ?>" class="flex-1 px-3 py-2 bg-gray-100 text-gray-700 text-center rounded hover:bg-gray-200 transition text-sm">
                    View Subscribers
                </a>
                <?php if (!$list['is_default']): ?>
                <form method="POST" onsubmit="return confirm('Delete this list? Subscribers will remain.');" class="flex-1">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="list_id" value="<?= $list['id'] ?>">
                    <button type="submit" class="w-full px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition text-sm">Delete</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Create Modal -->
    <div x-show="showModal" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto" 
         style="display: none;">
        <div class="flex items-center justify-center min-h-screen px-4">
            <!-- Overlay -->
            <div @click="showModal = false" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
            
            <!-- Modal -->
            <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl font-bold text-gray-900">Create New List</h2>
                    <button @click="showModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">List Name</label>
                        <input type="text" name="name" required 
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea name="description" rows="3" 
                                  class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"></textarea>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" @click="showModal = false" 
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                            Cancel
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                            Create List
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
