<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$preview = [];
$importStats = null;

// Handle file upload and import
// Handle sample CSV download
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="wharflist-import-sample.csv"');
    echo "email,first_name,last_name,company\n";
    echo "john.doe@example.com,John,Doe,Acme Corp\n";
    echo "jane.smith@example.com,Jane,Smith,Tech Inc\n";
    echo "bob.johnson@example.com,Bob,Johnson,Design Co\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'preview' && isset($_FILES['import_file'])) {
            $listId = $_POST['list_id'] ?? 0;
            $file = $_FILES['import_file'];
            
            if (empty($listId)) {
                $error = 'Please select a list';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed';
            } else {
                $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                
                if ($extension === 'csv') {
                    $preview = parseCSV($file['tmp_name']);
                } elseif ($extension === 'xlsx') {
                    $preview = parseXLSX($file['tmp_name']);
                } else {
                    $error = 'Invalid file format. Only CSV and XLSX files are supported.';
                }
                
                if (!empty($preview)) {
                    $_SESSION['import_preview'] = $preview;
                    $_SESSION['import_list_id'] = $listId;
                    $_SESSION['import_file_path'] = $file['tmp_name'];
                    $_SESSION['import_file_extension'] = $extension;
                    
                    // Move uploaded file to temp location so it persists
                    $tempFile = sys_get_temp_dir() . '/wharflist_import_' . uniqid() . '.' . $extension;
                    move_uploaded_file($file['tmp_name'], $tempFile);
                    $_SESSION['import_file_path'] = $tempFile;
                }
            }
        } elseif ($_POST['action'] === 'import') {
            if (!isset($_SESSION['import_file_path'])) {
                $error = 'No file data found. Please upload a file first.';
            } else {
                $filePath = $_SESSION['import_file_path'];
                $extension = $_SESSION['import_file_extension'];
                $listId = $_SESSION['import_list_id'];
                $siteId = $_POST['site_id'] ?? 0;
                $skipVerification = isset($_POST['skip_verification']);
                
                error_log("Import: skip_verification checkbox = " . ($skipVerification ? 'CHECKED' : 'NOT CHECKED'));
                error_log("Import: POST data = " . json_encode($_POST));
                
                if (empty($siteId)) {
                    $error = 'Please select a site';
                } else {
                    // Parse full file (not just preview)
                    if ($extension === 'csv') {
                        $allRows = parseFullCSV($filePath);
                    } elseif ($extension === 'xlsx') {
                        $allRows = parseFullXLSX($filePath);
                    }
                    
                    $imported = 0;
                    $skipped = 0;
                    $errors = 0;
                    
                    foreach ($allRows as $row) {
                        $email = trim($row['email'] ?? '');
                        
                        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $errors++;
                            continue;
                        }
                        
                        // Check if subscriber exists
                        $stmt = $db->prepare("SELECT id FROM subscribers WHERE email = ?");
                        $stmt->execute([$email]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing) {
                            // Check if already in this list
                            $stmt = $db->prepare("SELECT * FROM subscriber_lists WHERE subscriber_id = ? AND list_id = ?");
                            $stmt->execute([$existing['id'], $listId]);
                            if ($stmt->fetch()) {
                                $skipped++;
                                continue;
                            }
                            
                            // Add existing subscriber to this list
                            $stmt = $db->prepare("INSERT INTO subscriber_lists (subscriber_id, list_id) VALUES (?, ?)");
                            if ($stmt->execute([$existing['id'], $listId])) {
                                $imported++;
                                
                                // Send verification email if not verified and not skipping
                                if (!$skipVerification && !$existing['verified']) {
                                    try {
                                        require_once __DIR__ . '/email-helper.php';
                                        $emailSent = sendVerificationEmail($email, $existing['verification_token'], $db);
                                        if (!$emailSent) {
                                            error_log("Failed to send verification email to existing subscriber: $email");
                                        }
                                    } catch (Exception $e) {
                                        error_log("Error sending verification email to existing subscriber $email: " . $e->getMessage());
                                    }
                                }
                            } else {
                                $errors++;
                            }
                        } else {
                            // Create new subscriber
                            $token = bin2hex(random_bytes(32));
                            $verified = $skipVerification ? 1 : 0;
                            $verifiedAt = $skipVerification ? date('Y-m-d H:i:s') : null;
                            
                            error_log("Creating subscriber: email=$email, verified=$verified, skipVerification=" . ($skipVerification ? 'true' : 'false'));
                            
                            $customData = [];
                            foreach ($row as $key => $value) {
                                if ($key !== 'email' && !empty($value)) {
                                    $customData[$key] = $value;
                                }
                            }
                            
                            $stmt = $db->prepare("INSERT INTO subscribers (email, site_id, verification_token, verified, verified_at, custom_data) VALUES (?, ?, ?, ?, ?, ?)");
                            
                            if ($stmt->execute([$email, $siteId, $token, $verified, $verifiedAt, json_encode($customData)])) {
                                $subscriberId = $db->lastInsertId();
                                
                                // Add to list via junction table
                                $stmt = $db->prepare("INSERT INTO subscriber_lists (subscriber_id, list_id) VALUES (?, ?)");
                                if ($stmt->execute([$subscriberId, $listId])) {
                                    $imported++;
                                    
                                    // Send verification email if not skipping
                                    if (!$skipVerification) {
                                        try {
                                            require_once __DIR__ . '/email-helper.php';
                                            $emailSent = sendVerificationEmail($email, $token, $db);
                                            if (!$emailSent) {
                                                error_log("Failed to send verification email to: $email");
                                            }
                                        } catch (Exception $e) {
                                            error_log("Error sending verification email to $email: " . $e->getMessage());
                                        }
                                    }
                                } else {
                                    $errors++;
                                }
                            } else {
                                $errors++;
                            }
                        }
                    }
                    
                    $importStats = [
                        'imported' => $imported,
                        'skipped' => $skipped,
                        'errors' => $errors
                    ];
                    
                    // Clean up temp file
                    if (file_exists($filePath)) {
                        unlink($filePath);
                    }
                    
                    unset($_SESSION['import_preview']);
                    unset($_SESSION['import_list_id']);
                    unset($_SESSION['import_file_path']);
                    unset($_SESSION['import_file_extension']);
                    
                    $success = "Import completed: $imported imported, $skipped skipped (duplicates), $errors errors";
                }
            }
        }
    }
}

function parseCSV($filePath) {
    $rows = [];
    $headers = [];
    
    if (($handle = fopen($filePath, 'r')) !== false) {
        // Read header row
        if (($data = fgetcsv($handle)) !== false) {
            $headers = array_map('trim', $data);
        }
        
        // Read data rows (limit preview to 10 rows)
        $count = 0;
        while (($data = fgetcsv($handle)) !== false && $count < 10) {
            $row = [];
            foreach ($data as $index => $value) {
                $header = $headers[$index] ?? "column_$index";
                $row[$header] = trim($value);
            }
            $rows[] = $row;
            $count++;
        }
        
        fclose($handle);
    }
    
    return $rows;
}

function parseFullCSV($filePath) {
    $rows = [];
    $headers = [];
    
    if (($handle = fopen($filePath, 'r')) !== false) {
        // Read header row
        if (($data = fgetcsv($handle)) !== false) {
            $headers = array_map('trim', $data);
        }
        
        // Read ALL data rows
        while (($data = fgetcsv($handle)) !== false) {
            $row = [];
            foreach ($data as $index => $value) {
                $header = $headers[$index] ?? "column_$index";
                $row[$header] = trim($value);
            }
            $rows[] = $row;
        }
        
        fclose($handle);
    }
    
    return $rows;
}

function parseXLSX($filePath) {
    // Simple XLSX parser using XML reading
    $rows = [];
    
    try {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            // Read shared strings
            $sharedStrings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)$si->t;
                }
            }
            
            // Read worksheet
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
            $headers = [];
            $rowIndex = 0;
            
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                $colIndex = 0;
                
                foreach ($row->c as $cell) {
                    $value = '';
                    
                    if (isset($cell->v)) {
                        if (isset($cell['t']) && (string)$cell['t'] === 's') {
                            // Shared string
                            $value = $sharedStrings[(int)$cell->v];
                        } else {
                            $value = (string)$cell->v;
                        }
                    }
                    
                    if ($rowIndex === 0) {
                        $headers[$colIndex] = $value;
                    } else {
                        $header = $headers[$colIndex] ?? "column_$colIndex";
                        $rowData[$header] = $value;
                    }
                    
                    $colIndex++;
                }
                
                if ($rowIndex > 0 && !empty($rowData)) {
                    $rows[] = $rowData;
                }
                
                $rowIndex++;
                if ($rowIndex > 10) break; // Limit preview to 10 rows
            }
            
            $zip->close();
        }
    } catch (Exception $e) {
        // Fallback: treat as CSV
        return parseCSV($filePath);
    }
    
    return $rows;
}

function parseFullXLSX($filePath) {
    // Parse ALL rows from XLSX (not just 10)
    $rows = [];
    
    try {
        $zip = new ZipArchive();
        if ($zip->open($filePath) === true) {
            // Read shared strings
            $sharedStrings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $xml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
                foreach ($xml->si as $si) {
                    $sharedStrings[] = (string)$si->t;
                }
            }
            
            // Read worksheet
            $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
            $headers = [];
            $rowIndex = 0;
            
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                $colIndex = 0;
                
                foreach ($row->c as $cell) {
                    $value = '';
                    
                    if (isset($cell->v)) {
                        if (isset($cell['t']) && (string)$cell['t'] === 's') {
                            // Shared string
                            $value = $sharedStrings[(int)$cell->v];
                        } else {
                            $value = (string)$cell->v;
                        }
                    }
                    
                    if ($rowIndex === 0) {
                        $headers[$colIndex] = $value;
                    } else {
                        $header = $headers[$colIndex] ?? "column_$colIndex";
                        $rowData[$header] = $value;
                    }
                    
                    $colIndex++;
                }
                
                if ($rowIndex > 0 && !empty($rowData)) {
                    $rows[] = $rowData;
                }
                
                $rowIndex++;
                // NO LIMIT - read all rows
            }
            
            $zip->close();
        }
    } catch (Exception $e) {
        // Fallback: treat as CSV
        return parseFullCSV($filePath);
    }
    
    return $rows;
}

// Get all lists
$stmt = $db->query("SELECT * FROM lists ORDER BY is_default DESC, name");
$lists = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all sites
$stmt = $db->query("SELECT * FROM sites ORDER BY name");
$sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

$user = $auth->getCurrentUser();
$pageTitle = 'Import Subscribers';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<h1 class="text-3xl font-bold text-gray-900 mb-6">Import Subscribers</h1>

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

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Upload Form -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Upload File</h3>
        
        <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg mb-4">
            <p class="font-semibold mb-2">File Format Requirements:</p>
            <ul class="list-disc list-inside space-y-1 text-sm">
                <li>CSV or XLSX format</li>
                <li>First row must contain column headers</li>
                <li>Required column: <code class="bg-blue-100 px-1 rounded">email</code></li>
                <li>Optional: any additional custom fields</li>
            </ul>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="preview">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Select List</label>
                <select name="list_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Choose a list</option>
                    <?php foreach ($lists as $list): ?>
                    <option value="<?= $list['id'] ?>" <?= isset($_SESSION['import_list_id']) && $_SESSION['import_list_id'] == $list['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($list['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Upload CSV or XLSX File</label>
                <input type="file" name="import_file" accept=".csv,.xlsx" required 
                       class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
            </div>

            <div>
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
                    </svg>
                    Preview Import
                </button>
            </div>
        </form>
    </div>

    <!-- Example CSV -->
    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-4">Example CSV Format</h3>
        <pre class="bg-gray-50 px-4 py-3 rounded-lg text-sm overflow-x-auto mb-4"><code>email,name,phone
john@example.com,John Doe,555-0100
jane@example.com,Jane Smith,555-0101
bob@example.com,Bob Johnson,555-0102</code></pre>
        
        <p class="text-sm text-gray-600 mb-4">The email column is required. All other columns are optional and will be stored as custom data.</p>
        
        <a href="?download_sample=1" class="inline-flex items-center px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition text-sm">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
            </svg>
            Download Sample CSV
        </a>
    </div>
</div>

<?php if (!empty($preview)): ?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Preview - First 10 Rows</h3>
    </div>
    <div class="p-6">
        <form method="POST">
            <input type="hidden" name="action" value="import">
            
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 mb-2">Select Site (Required)</label>
                <select name="site_id" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Choose a site</option>
                    <?php foreach ($sites as $site): ?>
                    <option value="<?= $site['id'] ?>">
                        <?= htmlspecialchars($site['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label class="flex items-center">
                    <input type="checkbox" name="skip_verification" value="1" id="skip_verification" class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700">Skip email verification (mark all as verified)</span>
                </label>
            </div>

            <div class="overflow-x-auto mb-4">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <?php if (!empty($preview[0])): ?>
                                <?php foreach (array_keys($preview[0]) as $header): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?= htmlspecialchars($header) ?></th>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($preview as $row): ?>
                        <tr class="hover:bg-gray-50">
                            <?php foreach ($row as $value): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?= htmlspecialchars($value) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center space-x-3">
                <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    Confirm Import
                </button>
                <a href="import.php" class="px-4 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($importStats): ?>
<div class="bg-white rounded-lg shadow">
    <div class="px-6 py-4 border-b border-gray-200">
        <h3 class="text-lg font-semibold text-gray-900">Import Results</h3>
    </div>
    <div class="p-6">
        <dl class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-green-50 p-4 rounded-lg">
                <dt class="text-sm font-medium text-gray-600 mb-1">Successfully Imported</dt>
                <dd class="text-3xl font-bold text-green-600"><?= $importStats['imported'] ?></dd>
                <dd class="text-sm text-gray-600">subscribers</dd>
            </div>
            
            <div class="bg-yellow-50 p-4 rounded-lg">
                <dt class="text-sm font-medium text-gray-600 mb-1">Skipped (Duplicates)</dt>
                <dd class="text-3xl font-bold text-yellow-600"><?= $importStats['skipped'] ?></dd>
                <dd class="text-sm text-gray-600">subscribers</dd>
            </div>
            
            <div class="bg-red-50 p-4 rounded-lg">
                <dt class="text-sm font-medium text-gray-600 mb-1">Errors</dt>
                <dd class="text-3xl font-bold text-red-600"><?= $importStats['errors'] ?></dd>
                <dd class="text-sm text-gray-600">subscribers</dd>
            </div>
        </dl>
        
        <a href="subscribers.php?list_id=<?= $_SESSION['import_list_id'] ?? '' ?>" class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            View Subscribers
        </a>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
