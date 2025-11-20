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
            $domain = trim($_POST['domain'] ?? '');
            $listId = $_POST['list_id'] ?? 0;
            $customFields = trim($_POST['custom_fields'] ?? '');

            if (empty($name) || empty($domain) || empty($listId)) {
                $_SESSION['error'] = 'All fields are required';
                header('Location: sites.php');
                exit;
            } else {
                // Generate unique API key
                $apiKey = bin2hex(random_bytes(32));

                $stmt = $db->prepare("INSERT INTO sites (name, domain, list_id, api_key, custom_fields) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$name, $domain, $listId, $apiKey, $customFields])) {
                    $_SESSION['success'] = 'Site created successfully';
                }
                header('Location: sites.php');
                exit;
            }
        } elseif ($_POST['action'] === 'delete') {
            $siteId = $_POST['site_id'] ?? 0;
            $stmt = $db->prepare("DELETE FROM sites WHERE id = ?");
            if ($stmt->execute([$siteId])) {
                $_SESSION['success'] = 'Site deleted successfully';
            }
            header('Location: sites.php');
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

// Get all sites
$stmt = $db->query("SELECT s.*, l.name as list_name,
                    (SELECT COUNT(*) FROM subscribers WHERE site_id = s.id) as subscriber_count
                    FROM sites s
                    LEFT JOIN lists l ON s.list_id = l.id
                    ORDER BY s.created_at DESC");
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all lists for dropdown
$stmt = $db->query("SELECT * FROM lists ORDER BY is_default DESC, name");
$lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $auth->getCurrentUser();
$pageTitle = 'Sites';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div x-data="{ showCreateModal: false, showEmbedModal: false, embedCode: '' }">
    <!-- Page Header -->
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-3xl font-bold text-gray-900">Sites</h1>
        <button @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Add Site
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

    <!-- Sites Table -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Domain
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">List</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        Subscribers</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($sites as $site): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?= htmlspecialchars($site['name']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?= htmlspecialchars($site['domain']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                            <?= htmlspecialchars($site['list_name']) ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600"><?= $site['subscriber_count'] ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm space-x-2">
                            <button
                                @click="embedCode = `<!-- WharfList Subscription Form -->\n<div id='wharflist-form'></div>\n<script>\n(function() {\n    var script = document.createElement('script');\n    script.src = '<?= BASE_URL ?>/widget.js';\n    script.setAttribute('data-api-key', '<?= htmlspecialchars($site['api_key'], ENT_QUOTES) ?>');\n    script.setAttribute('data-site-id', '<?= $site['id'] ?>');\n    document.head.appendChild(script);\n})();\n<\/script>`; showEmbedModal = true"
                                class="inline-flex items-center px-3 py-1 bg-gray-600 text-white rounded hover:bg-gray-700 transition">
                                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"></path>
                                </svg>
                                Embed Code
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this site?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="site_id" value="<?= $site['id'] ?>">
                                <button type="submit"
                                    class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 transition">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Create Modal -->
    <template x-teleport="body">
        <div x-show="showCreateModal" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="showCreateModal = false"
                    class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"></div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl font-bold text-gray-900">Add New Site</h2>
                        <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Site Name</label>
                            <input type="text" name="name" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Domain (without http://)</label>
                            <input type="text" name="domain" placeholder="example.com" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">List</label>
                            <select name="list_id" required
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <option value="">Select a list</option>
                                <?php foreach ($lists as $list): ?>
                                    <option value="<?= $list['id'] ?>"><?= htmlspecialchars($list['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Custom Fields (comma
                                separated)</label>
                            <input type="text" name="custom_fields" placeholder="name,phone"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <p class="text-xs text-gray-500 mt-1">Optional additional fields to collect</p>
                        </div>
                        <div class="flex justify-end space-x-3">
                            <button type="button" @click="showCreateModal = false"
                                class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">Cancel</button>
                            <button type="submit"
                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Create
                                Site</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <!-- Embed Code Modal -->
    <template x-teleport="body">
        <div x-show="showEmbedModal" x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div @click="showEmbedModal = false" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity">
                </div>
                <div class="relative bg-white rounded-lg shadow-xl max-w-2xl w-full p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-xl font-bold text-gray-900">Embed Code</h2>
                        <button @click="showEmbedModal = false" class="text-gray-400 hover:text-gray-600">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    <p class="text-gray-600 mb-4">Copy and paste this code into your website:</p>
                    <textarea x-model="embedCode" id="embed-code" readonly
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg font-mono text-sm bg-gray-50"
                        rows="10"></textarea>
                    <button @click="navigator.clipboard.writeText(embedCode).then(() => alert('Copied to clipboard!'))"
                        class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                        Copy to Clipboard
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
```