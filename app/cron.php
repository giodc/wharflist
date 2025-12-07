<?php
// System Cron Entry Point
// Run this via crontab: * * * * * php /path/to/app/cron.php
// This script processes one job and exits, preventing overlapping processes.

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/phpmailer.php';

$db = Database::getInstance()->getConnection();

// AUTHENTICATION CHECK
// If running via HTTP, require the cron secret key
if (php_sapi_name() !== 'cli') {
    $stmt = $db->query("SELECT value FROM settings WHERE key = 'cron_secret'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $storedSecret = $result['value'] ?? '';

    $providedKey = $_GET['key'] ?? '';

    if (empty($storedSecret) || $providedKey !== $storedSecret) {
        http_response_code(403);
        die('Forbidden: Invalid Cron Key');
    }
}

// Prevent multiple instances if one is already running (simple file lock)
$lockFile = __DIR__ . '/../cron.lock';
$fp = fopen($lockFile, 'w+');

if (!flock($fp, LOCK_EX | LOCK_NB)) {
    // Could not get lock, another cron is running
    if (php_sapi_name() !== 'cli') {
        http_response_code(423); // Locked
        echo "Error: Process locked (another instance running)";
    }
    exit();
}

// Logging
$logFile = __DIR__ . '/../cron.log';
function cronLog($message) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
    if (php_sapi_name() !== 'cli') {
        echo htmlspecialchars($message) . "<br>\n";
    }
}

try {
    // Check for pending or due scheduled jobs
    $stmt = $db->query("SELECT * FROM queue_jobs 
                        WHERE status = 'pending' 
                        OR (status = 'scheduled' AND scheduled_at <= CURRENT_TIMESTAMP) 
                        ORDER BY COALESCE(scheduled_at, created_at) ASC LIMIT 1");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        // Nothing to do
        if (php_sapi_name() !== 'cli') {
            echo "Success: No pending jobs<br>\n";
        }
        exit();
    }

    $jobType = $job['status'] === 'scheduled' ? 'scheduled' : 'pending';
    cronLog("Processing $jobType job {$job['id']} for campaign {$job['campaign_id']}");

    // Mark as processing
    $stmt = $db->prepare("UPDATE queue_jobs SET status = 'processing', started_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$job['id']]);

    // Get campaign
    $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $stmt->execute([$job['campaign_id']]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        throw new Exception("Campaign not found");
    }

    // Get settings
    $stmt = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }

    // Get subscribers
    $listIds = !empty($job['list_ids']) ? explode(',', $job['list_ids']) : [$campaign['list_id']];
    $placeholders = str_repeat('?,', count($listIds) - 1) . '?';

    $stmt = $db->prepare("SELECT DISTINCT s.* 
                          FROM subscribers s
                          INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id
                          WHERE sl.list_id IN ($placeholders)
                          AND s.verified = 1 
                          AND s.unsubscribed = 0
                          AND sl.unsubscribed = 0");
    $stmt->execute($listIds);
    $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $total = count($subscribers);
    
    // Update total
    $stmt = $db->prepare("UPDATE queue_jobs SET total = ? WHERE id = ?");
    $stmt->execute([$total, $job['id']]);

    cronLog("Sending to {$total} subscribers");

    if ($total == 0) {
        $stmt = $db->prepare("UPDATE queue_jobs SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$job['id']]);
        cronLog("No subscribers, job completed");
        exit();
    }

    // Prepare Mailer
    $mailer = new SimpleMailer(
        $settings['smtp_host'] ?? '',
        $settings['smtp_port'] ?? '587',
        $settings['smtp_user'] ?? '',
        $settings['smtp_pass'] ?? ''
    );

    // Prepare Email Content (Logo, Footer, etc.)
    $logoUrl = $settings['email_logo'] ?? '';
    $logoPosition = $settings['logo_position'] ?? 'center';
    $logoName = $settings['logo_name'] ?? '';

    $logoHtml = '';
    if (!empty($logoUrl) || !empty($logoName)) {
        $alignStyle = $logoPosition === 'left' ? 'text-align: left;' : ($logoPosition === 'right' ? 'text-align: right;' : 'text-align: center;');
        $logoHtml = "<div style='$alignStyle margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #ddd;'>";
        if (!empty($logoUrl)) {
            $logoHtml .= "<img src='$logoUrl' alt='Logo' style='max-width: 200px; height: auto; display: inline-block;'><br>";
        }
        if (!empty($logoName)) {
            $logoHtml .= "<div style='font-size: 20px; font-weight: bold; color: #333; margin-top: 10px;'>$logoName</div>";
        }
        $logoHtml .= "</div>";
    }

    $footerAlign = $logoPosition === 'left' ? 'left' : ($logoPosition === 'right' ? 'right' : 'center');
    $footerHtml = "<div style='margin-top: 40px; padding-top: 20px; border-top: 2px solid #e5e7eb; font-size: 13px; color: #6b7280; line-height: 1.8; text-align: $footerAlign;'>";

    if (!empty($settings['footer_text'])) {
        $footerHtml .= "<p style='margin: 0 0 15px 0;'>" . nl2br(htmlspecialchars($settings['footer_text'])) . "</p>";
    }

    // Company info
    if (!empty($settings['footer_company_name']) || !empty($settings['footer_address'])) {
        $footerHtml .= "<p style='margin: 0 0 10px 0; color: #374151; font-weight: 500;'>";
        if (!empty($settings['footer_company_name'])) $footerHtml .= htmlspecialchars($settings['footer_company_name']);
        $footerHtml .= "</p>";
        if (!empty($settings['footer_address'])) $footerHtml .= "<p style='margin: 0 0 10px 0;'>" . nl2br(htmlspecialchars($settings['footer_address'])) . "</p>";
    }

    // Unsubscribe link placeholder
    $footerHtml .= "<p style='margin: 10px 0 0 0;'>{{UNSUBSCRIBE_LINK}}</p></div>";

    $emailBodyTemplate = "<html><body style='font-family: sans-serif;'>$logoHtml" . $campaign['body'] . "$footerHtml</body></html>";
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost';

    // Sending Loop
    $sentCount = 0;
    $emailsPerBatch = (int) ($settings['emails_per_batch'] ?? 50);
    $delayBetweenEmails = (float) ($settings['delay_between_emails'] ?? 0);

    foreach ($subscribers as $index => $subscriber) {
        $unsubToken = md5($subscriber['email'] . 'unsubscribe_salt_2024');
        $unsubscribeUrl = $baseUrl . "/unsubscribe.php?email=" . urlencode($subscriber['email']) . "&token=" . $unsubToken . "&list_id=" . $campaign['list_id'];
        $unsubscribeLink = "<a href='" . $unsubscribeUrl . "' style='color: #6b7280; text-decoration: underline;'>Unsubscribe</a>";
        
        $body = str_replace('{{UNSUBSCRIBE_LINK}}', $unsubscribeLink, $emailBodyTemplate);

        if ($mailer->send($settings['smtp_from'] ?? '', $subscriber['email'], $campaign['subject'], $body)) {
            $sentCount++;
        }

        // Delay
        if ($delayBetweenEmails > 0) usleep((int) ($delayBetweenEmails * 1000000));
        
        // Progress Update
        if ($sentCount % 5 == 0) {
            $stmt = $db->prepare("UPDATE queue_jobs SET progress = ? WHERE id = ?");
            $stmt->execute([$sentCount, $job['id']]);
        }
    }

    // Final Update
    $stmt = $db->prepare("UPDATE email_campaigns SET sent_count = ?, status = 'sent', sent_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sentCount, $campaign['id']]);

    $stmt = $db->prepare("UPDATE queue_jobs SET status = 'completed', progress = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sentCount, $job['id']]);

    cronLog("Job {$job['id']} completed: {$sentCount}/{$total} emails sent");

} catch (Exception $e) {
    cronLog("Error: " . $e->getMessage());
    if (isset($job)) {
        $stmt = $db->prepare("UPDATE queue_jobs SET status = 'failed', error = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$e->getMessage(), $job['id']]);
    }
}

// Release lock
flock($fp, LOCK_UN);
fclose($fp);
