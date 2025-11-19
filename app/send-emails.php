<?php
// Background email sender - processes emails in batches
@ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// Increase time limit for this script
set_time_limit(60); // 60 seconds max

$campaignId = $_POST['campaign_id'] ?? 0;
$offset = (int)($_POST['offset'] ?? 0);
$batchSize = 3; // Send 3 emails per batch to avoid timeout

try {
    $db = Database::getInstance()->getConnection();

    // Get campaign details
    $stmt = $db->prepare("SELECT * FROM email_campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$campaign) {
        echo json_encode(['success' => false, 'message' => 'Campaign not found']);
        exit;
    }

    // Get batch of subscribers (using junction table, avoiding duplicates)
    $stmt = $db->prepare("
        SELECT DISTINCT s.email 
        FROM subscribers s
        INNER JOIN subscriber_lists sl ON s.id = sl.subscriber_id
        WHERE sl.list_id = ? AND s.verified = 1
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$campaign['list_id'], $batchSize, $offset]);
    $subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($subscribers)) {
        echo json_encode([
            'success' => true,
            'complete' => true,
            'sent' => $campaign['sent_count'],
            'message' => 'All emails sent'
        ]);
        exit;
    }

    // Get SMTP settings
    $stmt = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }

    require_once __DIR__ . '/phpmailer.php';
    $mailer = new SimpleMailer(
        $settings['smtp_host'] ?? '',
        $settings['smtp_port'] ?? 587,
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
        $logoHtml = "<div style='$alignStyle margin-bottom: 20px;'>";
        if (!empty($logoUrl)) {
            $logoHtml .= "<img src='$logoUrl' alt='Logo' style='max-width: 200px; height: auto; display: inline-block;'><br>";
        }
        if (!empty($logoName)) {
            $logoHtml .= "<div style='font-size: 18px; font-weight: bold; color: #333; margin-top: 10px;'>$logoName</div>";
        }
        $logoHtml .= "</div>";
    }

    // Format email body
    $emailBody = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .content { background: #fff; padding: 30px; border-radius: 8px; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; text-align: center; }
        </style>
    </head>
    <body>
        <div class='container'>
            $logoHtml
            <div class='content'>
                " . $campaign['body'] . "
            </div>
            <div class='footer'>
                <p>You received this email because you subscribed to our mailing list.</p>
            </div>
        </div>
    </body>
    </html>
    ";

    // Prepare base URL for unsubscribe links
    $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://$_SERVER[HTTP_HOST]$basePath";

$sentCount = 0;
foreach ($subscribers as $subscriber) {
    // Generate unsubscribe link for this subscriber
    $unsubToken = md5($subscriber['email'] . 'unsubscribe_salt_2024');
    $unsubscribeUrl = $baseUrl . "/unsubscribe.php?email=" . urlencode($subscriber['email']) . "&token=" . $unsubToken;
    
    // Add unsubscribe link to email body
    $personalizedBody = str_replace(
        '</div>
</body>',
        '<p style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 11px; color: #888; text-align: center;">
            <a href="' . $unsubscribeUrl . '" style="color: #888; text-decoration: underline;">Unsubscribe</a> from this mailing list
        </p>
        </div>
</body>',
        $emailBody
    );
    
    if ($mailer->send($settings['smtp_from'], $subscriber['email'], $campaign['subject'], $personalizedBody)) {
        $sentCount++;
    }
    // No delay needed for small batches
}

    // Update campaign sent count
    $newTotal = $campaign['sent_count'] + $sentCount;
    $stmt = $db->prepare("UPDATE email_campaigns SET sent_count = ? WHERE id = ?");
    $stmt->execute([$newTotal, $campaignId]);

    echo json_encode([
        'success' => true,
        'complete' => false,
        'sent' => $sentCount,
        'total' => $newTotal,
        'offset' => $offset + $batchSize,
        'message' => "Sent $sentCount emails in this batch"
    ]);
} catch (Exception $e) {
    error_log("Email sending error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error sending emails: ' . $e->getMessage()
    ]);
}
