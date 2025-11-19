<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';

$db = Database::getInstance()->getConnection();

// 1. Create two test lists
$db->exec("INSERT INTO lists (name, description) VALUES ('Test List A', 'Description A')");
$listA = $db->lastInsertId();
$db->exec("INSERT INTO lists (name, description) VALUES ('Test List B', 'Description B')");
$listB = $db->lastInsertId();

echo "Created List A ($listA) and List B ($listB)\n";

// 2. Add subscribers
$emailA = 'testA_' . time() . '@example.com';
$emailB = 'testB_' . time() . '@example.com';

$stmt = $db->prepare("INSERT INTO subscribers (email, first_name, verified, unsubscribed) VALUES (?, 'User A', 1, 0)");
$stmt->execute([$emailA]);
$subA = $db->lastInsertId();

$stmt = $db->prepare("INSERT INTO subscribers (email, first_name, verified, unsubscribed) VALUES (?, 'User B', 1, 0)");
$stmt->execute([$emailB]);
$subB = $db->lastInsertId();

// 3. Assign to lists
$stmt = $db->prepare("INSERT INTO subscriber_lists (subscriber_id, list_id, status) VALUES (?, ?, 'subscribed')");
$stmt->execute([$subA, $listA]);
$stmt->execute([$subB, $listB]);

echo "Created Subscriber A ($subA) in List A and Subscriber B ($subB) in List B\n";

// 4. Create Campaign
$stmt = $db->prepare("INSERT INTO email_campaigns (list_id, subject, body, status, created_at) VALUES (?, 'Test Subject', 'Test Body', 'sent', CURRENT_TIMESTAMP)");
$stmt->execute([$listA]); // Legacy column gets first list
$campaignId = $db->lastInsertId();

echo "Created Campaign $campaignId\n";

// 5. Create Queue Job with BOTH lists
$listIds = "$listA,$listB";
$stmt = $db->prepare("INSERT INTO queue_jobs (campaign_id, list_ids, status) VALUES (?, ?, 'pending')");
$stmt->execute([$campaignId, $listIds]);
$jobId = $db->lastInsertId();

echo "Created Queue Job $jobId with list_ids: $listIds\n";

// 6. Simulate Worker Logic
echo "\n--- Simulating Worker Logic ---\n";

$stmt = $db->prepare("SELECT * FROM queue_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

$listIdsArray = !empty($job['list_ids']) ? explode(',', $job['list_ids']) : [$listA];
echo "Worker parsed list IDs: " . implode(', ', $listIdsArray) . "\n";

$placeholders = str_repeat('?,', count($listIdsArray) - 1) . '?';
$sql = "SELECT DISTINCT s.* 
        FROM subscribers s
        INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id
        WHERE sl.list_id IN ($placeholders)
        AND s.verified = 1 
        AND s.unsubscribed = 0
        AND sl.unsubscribed = 0";

echo "Worker SQL: $sql\n";

$stmt = $db->prepare($sql);
$stmt->execute($listIdsArray);
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($subscribers) . " subscribers:\n";
foreach ($subscribers as $s) {
    echo "- {$s['email']} (ID: {$s['id']})\n";
}

if (count($subscribers) === 2) {
    echo "\nSUCCESS: Worker logic correctly found both subscribers.\n";
} else {
    echo "\nFAILURE: Worker logic found " . count($subscribers) . " subscribers, expected 2.\n";
}
