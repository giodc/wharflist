<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/database.php';
require_once __DIR__ . '/app/email-helper.php';

// Ensure we always return JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Turn off output buffering to prevent HTML errors
if (ob_get_level())
    ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Catch any PHP errors and return as JSON
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'Server error occurred'
    ];
    // Only include debug info if display_errors is enabled
    if (ini_get('display_errors') == '1') {
        $response['debug'] = [
            'error' => $errstr,
            'file' => $errfile,
            'line' => $errline
        ];
    }
    echo json_encode($response);
    exit;
});

class RateLimiter
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function check($ipAddress, $siteId, $maxAttempts = 5, $timeWindow = 3600)
    {
        $stmt = $this->db->prepare("SELECT attempts, last_attempt FROM rate_limits WHERE ip_address = ? AND site_id = ?");
        $stmt->execute([$ipAddress, $siteId]);
        $limit = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($limit) {
            $timeDiff = time() - strtotime($limit['last_attempt']);

            if ($timeDiff > $timeWindow) {
                // Reset counter
                $stmt = $this->db->prepare("UPDATE rate_limits SET attempts = 1, last_attempt = CURRENT_TIMESTAMP WHERE ip_address = ? AND site_id = ?");
                $stmt->execute([$ipAddress, $siteId]);
                return true;
            } elseif ($limit['attempts'] >= $maxAttempts) {
                return false;
            } else {
                // Increment counter
                $stmt = $this->db->prepare("UPDATE rate_limits SET attempts = attempts + 1, last_attempt = CURRENT_TIMESTAMP WHERE ip_address = ? AND site_id = ?");
                $stmt->execute([$ipAddress, $siteId]);
                return true;
            }
        } else {
            // First attempt
            $stmt = $this->db->prepare("INSERT INTO rate_limits (ip_address, site_id) VALUES (?, ?)");
            $stmt->execute([$ipAddress, $siteId]);
            return true;
        }
    }
}

function sendResponse($success, $message, $data = [])
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit;
}

// sendVerificationEmail function is now in email-helper.php

try {
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendResponse(false, 'Invalid request method');
    }

    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if ($data === null) {
        error_log("Invalid JSON input: " . $rawInput);
        sendResponse(false, 'Invalid request data');
    }

    $apiKey = $data['api_key'] ?? '';
    $email = $data['email'] ?? '';
    $customData = $data['custom_data'] ?? [];
    $honeypot = $data['_hp'] ?? null;
    $timestamp = $data['_t'] ?? 0;

    // Silent anti-spam checks
    // 1. Honeypot check - if honeypot field has value, silently reject
    if ($honeypot !== '' && $honeypot !== null) {
        error_log("Bot detected (honeypot): " . $email . " - Silently rejected");
        // Pretend success to fool bots
        sendResponse(true, 'Please check your email to verify your subscription');
    }

    // 2. Timing check - if request is too fast, silently reject
    // Note: 1 second threshold allows for fast but human typing speeds
    if ($timestamp > 0) {
        $timeDiff = (microtime(true) * 1000) - $timestamp;
        if ($timeDiff < 1000) {
            error_log("Bot detected (timing): " . $email . " - Submitted in {$timeDiff}ms - Silently rejected");
            sendResponse(true, 'Please check your email to verify your subscription');
        }
        error_log("Timing check passed: " . $email . " - Submitted in {$timeDiff}ms");
    }

    error_log("Anti-spam checks passed for: " . $email);

    // Validate inputs
    if (empty($apiKey) || empty($email)) {
        sendResponse(false, 'Missing required fields');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, 'Invalid email address');
    }

    // Get site by API key
    $stmt = $db->prepare("SELECT * FROM sites WHERE api_key = ?");
    $stmt->execute([$apiKey]);
    $site = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$site) {
        sendResponse(false, 'Invalid API key');
    }

    // Check domain (if provided in request)
    if (isset($_SERVER['HTTP_REFERER'])) {
        $refererHost = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
        $configuredDomain = $site['domain'];

        // Normalize domains - remove www. and convert to lowercase
        $refererNormalized = strtolower(preg_replace('/^www\./', '', $refererHost));
        $configuredNormalized = strtolower(preg_replace('/^www\./', '', $configuredDomain));

        error_log("Domain check: Referer=$refererHost, Configured={$site['domain']}, SiteID={$site['id']}, SiteName={$site['name']}, Match=" . ($refererNormalized === $configuredNormalized ? 'YES' : 'NO'));

        if ($refererHost && $refererNormalized !== $configuredNormalized) {
            error_log("Domain mismatch: '$refererNormalized' !== '$configuredNormalized' (Site: {$site['name']})");
            // Include debug info in response if display_errors is enabled
            if (ini_get('display_errors') == '1') {
                sendResponse(false, 'Domain not allowed', [
                    'referer_domain' => $refererNormalized,
                    'configured_domain' => $configuredNormalized,
                    'site_name' => $site['name'],
                    'site_id' => $site['id']
                ]);
            } else {
                sendResponse(false, 'Domain not allowed');
            }
        }
    }

    // Rate limiting
    $ipAddress = $_SERVER['REMOTE_ADDR'];
    $rateLimiter = new RateLimiter($db);

    if (!$rateLimiter->check($ipAddress, $site['id'])) {
        sendResponse(false, 'Too many attempts. Please try again later.');
    }

    // Check if email already exists (globally, not per-list)
    $stmt = $db->prepare("SELECT * FROM subscribers WHERE email = ?");
    $stmt->execute([$email]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // If they were globally unsubscribed, reset it (they're re-subscribing)
        if ($existing['unsubscribed']) {
            $stmt = $db->prepare("UPDATE subscribers SET unsubscribed = 0 WHERE id = ?");
            $stmt->execute([$existing['id']]);
            error_log("Reset unsubscribed flag for subscriber {$existing['id']}");
        }

        // Subscriber exists - check if already in this list
        $stmt = $db->prepare("SELECT * FROM subscriber_lists WHERE subscriber_id = ? AND list_id = ?");
        $stmt->execute([$existing['id'], $site['list_id']]);
        $inList = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($inList) {
            // Already in this list
            if ($existing['verified']) {
                sendResponse(false, 'Email already subscribed to this list');
            } else {
                sendResponse(false, 'Email pending verification. Please check your inbox.');
            }
        } else {
            // Exists but not in this list - add to list
            $stmt = $db->prepare("INSERT INTO subscriber_lists (subscriber_id, list_id) VALUES (?, ?)");
            if ($stmt->execute([$existing['id'], $site['list_id']])) {
                error_log("Added existing subscriber {$existing['id']} to list {$site['list_id']}");

                if ($existing['verified']) {
                    sendResponse(true, 'Successfully subscribed to this list');
                } else {
                    // Resend verification email
                    require_once __DIR__ . '/app/email-helper.php';
                    sendVerificationEmail($email, $existing['verification_token'], $db);
                    sendResponse(true, 'Please check your email to verify your subscription');
                }
            } else {
                sendResponse(false, 'Failed to subscribe. Please try again.');
            }
        }
    }

    // New subscriber - create and add to list
    $token = bin2hex(random_bytes(32));

    // Insert subscriber (without list_id - we use junction table)
    $stmt = $db->prepare("INSERT INTO subscribers (email, site_id, verification_token, custom_data) VALUES (?, ?, ?, ?)");
    $result = $stmt->execute([
        $email,
        $site['id'],
        $token,
        json_encode($customData)
    ]);

    if (!$result) {
        error_log("Failed to insert subscriber: " . json_encode($stmt->errorInfo()));
        sendResponse(false, 'Failed to subscribe. Please try again.');
    }

    $subscriberId = $db->lastInsertId();

    // Add to list via junction table
    $stmt = $db->prepare("INSERT INTO subscriber_lists (subscriber_id, list_id) VALUES (?, ?)");
    $stmt->execute([$subscriberId, $site['list_id']]);

    error_log("Subscriber inserted: ID=$subscriberId, Email=$email, List={$site['list_id']}, Site={$site['id']}");

    // Send verification email
    if (sendVerificationEmail($email, $token, $db)) {
        sendResponse(true, 'Please check your email to verify your subscription');
    } else {
        sendResponse(true, 'Subscribed! Verification email will be sent shortly.');
    }

} catch (Exception $e) {
    error_log('API Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    // Only show detailed error message if display_errors is enabled
    if (ini_get('display_errors') == '1') {
        sendResponse(false, 'An error occurred: ' . $e->getMessage());
    } else {
        sendResponse(false, 'An error occurred. Please try again later.');
    }
}

// Catch any uncaught errors
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level())
            ob_end_clean();
        http_response_code(500);
        $response = [
            'success' => false,
            'message' => 'A fatal error occurred'
        ];
        // Only include debug info if display_errors is enabled
        if (ini_get('display_errors') == '1') {
            $response['debug'] = $error;
        }
        echo json_encode($response);
    }
});
