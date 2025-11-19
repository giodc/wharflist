<?php
// Email helper functions

function sendVerificationEmail($email, $token, $db) {
    // Get SMTP settings
    $stmt = $db->query("SELECT key, value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    if (empty($settings['smtp_host'])) {
        return false;
    }
    
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
    
    // Use BASE_URL constant for root domain
    $baseUrl = defined('BASE_URL') ? BASE_URL : ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . "://" . $_SERVER['HTTP_HOST']);
    
    $verificationUrl = $baseUrl . "/verify.php?token=" . urlencode($token);
    
    $unsubToken = md5($email . 'unsubscribe_salt_2024');
    $unsubscribeUrl = $baseUrl . "/unsubscribe.php?email=" . urlencode($email) . "&token=" . $unsubToken;
    
    // Build footer HTML with alignment matching logo position
    $footerAlign = $logoPosition === 'left' ? 'left' : ($logoPosition === 'right' ? 'right' : 'center');
    $footerHtml = "<div style='margin-top: 40px; padding-top: 20px; border-top: 2px solid #e5e7eb; font-size: 13px; color: #6b7280; line-height: 1.8; text-align: $footerAlign;'>";
    
    // Default verification message
    $footerHtml .= "<p style='margin: 0 0 15px 0;'>If you didn't subscribe to this list, you can safely ignore this email.</p>";
    
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
    $links[] = "<a href='" . $unsubscribeUrl . "' style='color: #6b7280; text-decoration: underline;'>Unsubscribe</a>";
    
    if (!empty($links)) {
        $footerHtml .= "<p style='margin: 10px 0 0 0;'>" . implode(" &nbsp;·&nbsp; ", $links) . "</p>";
    }
    
    $footerHtml .= "</div>";
    
    $subject = "Please verify your email address";
    $message = "
    <html>
    <head>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; line-height: 1.6; color: #1f2937; margin: 0; padding: 0; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .button { display: inline-block; padding: 12px 24px; background-color: #0066cc; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            $logoHtml
            <h2>Verify Your Email Address</h2>
            <p>Thank you for subscribing! Please click the button below to verify your email address:</p>
            <a href='$verificationUrl' class='button'>Verify Email Address</a>
            <p>Or copy and paste this link into your browser:</p>
            <p style='word-break: break-all;'>$verificationUrl</p>
            $footerHtml
        </div>
    </body>
    </html>
    ";
    
    try {
        require_once __DIR__ . '/phpmailer.php';
        $mailer = new SimpleMailer(
            $settings['smtp_host'],
            $settings['smtp_port'],
            $settings['smtp_user'],
            $settings['smtp_pass']
        );
        
        return $mailer->send($settings['smtp_from'], $email, $subject, $message);
    } catch (Exception $e) {
        error_log("Email error: " . $e->getMessage());
        return false;
    }
}
