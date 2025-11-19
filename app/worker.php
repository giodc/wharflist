<?php
// Email Queue Worker
// Run this in background: php worker.php &
// Or with cron: * * * * * cd /path/to/wharflist && php worker.php >> logs/worker.log 2>&1

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/phpmailer.php';

set_time_limit(300); // 5 minutes max

$db = Database::getInstance()->getConnection();
$db->exec("PRAGMA foreign_keys = ON");

echo "[" . date('Y-m-d H:i:s') . "] Worker started\n";

// Get pending jobs
$stmt = $db->query("SELECT * FROM queue_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    echo "[" . date('Y-m-d H:i:s') . "] No pending jobs\n";
    exit(0);
}

echo "[" . date('Y-m-d H:i:s') . "] Processing job {$job['id']} for campaign {$job['campaign_id']}\n";

// Mark as processing
$stmt = $db->prepare("UPDATE queue_jobs SET status = 'processing', started_at = CURRENT_TIMESTAMP WHERE id = ?");
$stmt->execute([$job['id']]);

try {
    // Get campaign
    $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $stmt->execute([$job['campaign_id']]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$campaign) {
        throw new Exception("Campaign not found");
    }
    
    // Get SMTP settings
    $stmt = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    // Get subscribers for this campaign's list (only verified, not globally unsubscribed, not list-unsubscribed)
    $stmt = $db->prepare("SELECT DISTINCT s.* 
                          FROM subscribers s
                          INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id
                          WHERE sl.list_id = ? 
                          AND s.verified = 1 
                          AND s.unsubscribed = 0
                          AND sl.unsubscribed = 0");
    $stmt->execute([$campaign['list_id']]);
    $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total = count($subscribers);
    
    // Update total count
    $stmt = $db->prepare("UPDATE queue_jobs SET total = ? WHERE id = ?");
    $stmt->execute([$total, $job['id']]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Sending to {$total} subscribers\n";
    
    if ($total == 0) {
        $stmt = $db->prepare("UPDATE queue_jobs SET status = 'completed', completed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$job['id']]);
        echo "[" . date('Y-m-d H:i:s') . "] No subscribers, job completed\n";
        exit(0);
    }
    
    // Initialize mailer
    $mailer = new SimpleMailer(
        $settings['smtp_host'] ?? '',
        $settings['smtp_port'] ?? '587',
        $settings['smtp_user'] ?? '',
        $settings['smtp_pass'] ?? ''
    );
    
    // Get logo settings
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
    
    // Build footer HTML with alignment matching logo position
    $footerAlign = $logoPosition === 'left' ? 'left' : ($logoPosition === 'right' ? 'right' : 'center');
    $footerHtml = "<div style='margin-top: 40px; padding-top: 20px; border-top: 2px solid #e5e7eb; font-size: 13px; color: #6b7280; line-height: 1.8; text-align: $footerAlign;'>";
    
    // Footer text/message
    if (!empty($settings['footer_text'])) {
        $footerHtml .= "<p style='margin: 0 0 15px 0;'>" . nl2br(htmlspecialchars($settings['footer_text'])) . "</p>";
    }
    
    // Company info
    if (!empty($settings['footer_company_name']) || !empty($settings['footer_address'])) {
        $footerHtml .= "<p style='margin: 0 0 10px 0; color: #374151; font-weight: 500;'>";
        if (!empty($settings['footer_company_name'])) {
            $footerHtml .= htmlspecialchars($settings['footer_company_name']);
        }
        $footerHtml .= "</p>";
        
        if (!empty($settings['footer_address'])) {
            $footerHtml .= "<p style='margin: 0 0 10px 0;'>" . nl2br(htmlspecialchars($settings['footer_address'])) . "</p>";
        }
    }
    
    // Contact info
    $contactInfo = [];
    if (!empty($settings['footer_email'])) {
        $contactInfo[] = "<a href='mailto:" . htmlspecialchars($settings['footer_email']) . "' style='color: #6b7280; text-decoration: none;'>" . htmlspecialchars($settings['footer_email']) . "</a>";
    }
    if (!empty($settings['footer_phone'])) {
        $contactInfo[] = htmlspecialchars($settings['footer_phone']);
    }
    if (!empty($contactInfo)) {
        $footerHtml .= "<p style='margin: 0 0 10px 0;'>" . implode(" &nbsp;·&nbsp; ", $contactInfo) . "</p>";
    }
    
    // Links (website, privacy, unsubscribe)
    $links = [];
    if (!empty($settings['footer_website_url'])) {
        $links[] = "<a href='" . htmlspecialchars($settings['footer_website_url']) . "' style='color: #6b7280; text-decoration: underline;'>Visit Website</a>";
    }
    if (!empty($settings['footer_privacy_url'])) {
        $links[] = "<a href='" . htmlspecialchars($settings['footer_privacy_url']) . "' style='color: #6b7280; text-decoration: underline;'>Privacy Policy</a>";
    }
    // Unsubscribe placeholder - will be replaced per subscriber
    $links[] = "{{UNSUBSCRIBE_LINK}}";
    
    if (!empty($links)) {
        $footerHtml .= "<p style='margin: 10px 0 0 0;'>" . implode(" &nbsp;·&nbsp; ", $links) . "</p>";
    }
    
    $footerHtml .= "</div>";
    
    // Email template
    $emailBody = "
    <html>
    <head>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        </style>
    </head>
    <body>
        <div class='container'>
            $logoHtml
            " . $campaign['body'] . "
            $footerHtml
        </div>
    </body>
    </html>
    ";
    
    // Get base URL from settings or use configured BASE_URL
    $baseUrl = defined('BASE_URL') ? rtrim(BASE_URL, '/') : 'http://localhost';
    
    // Get sending speed settings
    $emailsPerBatch = (int)($settings['emails_per_batch'] ?? 50);
    $delayBetweenEmails = (float)($settings['delay_between_emails'] ?? 0);
    
    echo "[" . date('Y-m-d H:i:s') . "] Sending speed: {$emailsPerBatch} emails per batch, {$delayBetweenEmails}s delay\n";
    
    $sentCount = 0;
    foreach ($subscribers as $index => $subscriber) {
        // Generate unsubscribe link with list ID
        $unsubToken = md5($subscriber['email'] . 'unsubscribe_salt_2024');
        $unsubscribeUrl = $baseUrl . "/unsubscribe.php?email=" . urlencode($subscriber['email']) . "&token=" . $unsubToken . "&list_id=" . $campaign['list_id'];
        
        // Replace placeholder with actual link
        $unsubscribeLink = "<a href='" . $unsubscribeUrl . "' style='color: #6b7280; text-decoration: underline;'>Unsubscribe</a>";
        $personalizedBody = str_replace('{{UNSUBSCRIBE_LINK}}', $unsubscribeLink, $emailBody);
        
        if ($mailer->send($settings['smtp_from'] ?? '', $subscriber['email'], $campaign['subject'], $personalizedBody)) {
            $sentCount++;
        }
        
        // Add delay between emails if configured
        if ($delayBetweenEmails > 0 && $index < count($subscribers) - 1) {
            usleep((int)($delayBetweenEmails * 1000000)); // Convert seconds to microseconds
        }
        
        // Check if we've hit the batch limit
        if ($emailsPerBatch > 0 && ($index + 1) % $emailsPerBatch === 0 && $index < count($subscribers) - 1) {
            echo "[" . date('Y-m-d H:i:s') . "] Batch of {$emailsPerBatch} sent, pausing briefly...\n";
            sleep(1); // Brief pause between batches
        }
        
        // Update progress every 5 emails
        if ($sentCount % 5 == 0) {
            $stmt = $db->prepare("UPDATE queue_jobs SET progress = ? WHERE id = ?");
            $stmt->execute([$sentCount, $job['id']]);
            echo "[" . date('Y-m-d H:i:s') . "] Progress: {$sentCount}/{$total}\n";
        }
    }
    
    // Update campaign sent count
    $stmt = $db->prepare("UPDATE email_campaigns SET sent_count = ? WHERE id = ?");
    $stmt->execute([$sentCount, $campaign['id']]);
    
    // Mark job as completed
    $stmt = $db->prepare("UPDATE queue_jobs SET status = 'completed', progress = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sentCount, $job['id']]);
    
    echo "[" . date('Y-m-d H:i:s') . "] Job {$job['id']} completed: {$sentCount}/{$total} emails sent\n";
    
} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Error: " . $e->getMessage() . "\n";
    
    // Mark job as failed
    $stmt = $db->prepare("UPDATE queue_jobs SET status = 'failed', error = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$e->getMessage(), $job['id']]);
}
