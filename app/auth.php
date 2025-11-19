<?php

class Auth
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    public function login($username, $password)
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['totp_enabled']) {
                $_SESSION['pending_2fa'] = $user['id'];
                return ['success' => true, 'requires_2fa' => true];
            } else {
                // Regenerate session ID to prevent session fixation
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                return ['success' => true, 'requires_2fa' => false];
            }
        }

        return ['success' => false, 'error' => 'Invalid credentials'];
    }

    public function verify2FA($code)
    {
        if (!isset($_SESSION['pending_2fa'])) {
            return false;
        }

        $userId = $_SESSION['pending_2fa'];
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            // Try TOTP first
            if ($this->verifyTOTP($user['totp_secret'], $code)) {
                $this->completeLogin($user);
                return true;
            }

            // Try backup code
            if ($this->verifyBackupCode($user['id'], $code)) {
                $this->completeLogin($user);
                return true;
            }
        }

        return false;
    }

    private function completeLogin($user)
    {
        unset($_SESSION['pending_2fa']);
        // Regenerate session ID after 2FA verification
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
    }

    public function generateBackupCodes()
    {
        $codes = [];
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // Removed confusing chars like I, 1, O, 0

        for ($i = 0; $i < 10; $i++) {
            $code = '';
            for ($j = 0; $j < 8; $j++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $codes[] = $code;
        }

        return $codes;
    }

    public function saveBackupCodes($userId, $codes)
    {
        try {
            $this->db->beginTransaction();

            // Delete existing codes
            $stmt = $this->db->prepare("DELETE FROM backup_codes WHERE user_id = ?");
            $stmt->execute([$userId]);

            // Insert new codes
            $stmt = $this->db->prepare("INSERT INTO backup_codes (user_id, code) VALUES (?, ?)");
            foreach ($codes as $code) {
                // Store hashed version for security
                $stmt->execute([$userId, password_hash($code, PASSWORD_DEFAULT)]);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Failed to save backup codes: " . $e->getMessage());
            return false;
        }
    }

    public function verifyBackupCode($userId, $code)
    {
        // Normalize code
        $code = strtoupper(trim(str_replace(' ', '', $code)));

        // Check format (8 chars)
        if (strlen($code) !== 8) {
            return false;
        }

        // Get all unused codes for user
        $stmt = $this->db->prepare("SELECT id, code FROM backup_codes WHERE user_id = ? AND used = 0");
        $stmt->execute([$userId]);
        $backupCodes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($backupCodes as $storedCode) {
            if (password_verify($code, $storedCode['code'])) {
                // Mark as used
                $updateStmt = $this->db->prepare("UPDATE backup_codes SET used = 1 WHERE id = ?");
                $updateStmt->execute([$storedCode['id']]);
                return true;
            }
        }

        return false;
    }

    public function getBackupCodesCount($userId)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM backup_codes WHERE user_id = ? AND used = 0");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn();
    }

    public function regenerateBackupCodes($userId)
    {
        $codes = $this->generateBackupCodes();
        if ($this->saveBackupCodes($userId, $codes)) {
            return $codes;
        }
        return false;
    }

    public function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }

    public function requireLogin()
    {
        if (!$this->isLoggedIn()) {
            header('Location: /app/login.php');
            exit;
        }
    }

    public function logout()
    {
        session_destroy();
        header('Location: /app/login.php');
        exit;
    }

    public function getCurrentUser()
    {
        if (!$this->isLoggedIn()) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT id, username, email, totp_enabled FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function generateTOTPSecret()
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $secret;
    }

    public function getTOTPUri($secret, $email, $issuer = 'WharfList')
    {
        return 'otpauth://totp/' . urlencode($issuer) . ':' . urlencode($email) . '?secret=' . $secret . '&issuer=' . urlencode($issuer);
    }

    public function verifyTOTP($secret, $code)
    {
        // Normalize input code - remove spaces, trim, ensure it's a string
        $code = trim(str_replace(' ', '', (string) $code));

        // Must be exactly 6 digits
        if (!preg_match('/^\d{6}$/', $code)) {
            error_log("2FA: Invalid code format: " . $code);
            return false;
        }

        $timeSlice = floor(time() / 30);
        error_log("2FA: Verifying code $code at timeSlice $timeSlice");

        // Check current and adjacent time slices for clock skew
        for ($i = -1; $i <= 1; $i++) {
            $calculatedCode = $this->getTOTPCode($secret, $timeSlice + $i);
            error_log("2FA: Calculated code for timeSlice " . ($timeSlice + $i) . ": " . $calculatedCode);
            if ($calculatedCode === $code) {
                error_log("2FA: Code matched!");
                return true;
            }
        }

        error_log("2FA: No match found");
        return false;
    }

    private function getTOTPCode($secret, $timeSlice)
    {
        $secret = $this->base32Decode($secret);
        $time = pack('N*', 0, $timeSlice);
        $hash = hash_hmac('sha1', $time, $secret, true);
        $offset = ord($hash[19]) & 0xf;
        $code = (
            ((ord($hash[$offset]) & 0x7f) << 24) |
            ((ord($hash[$offset + 1]) & 0xff) << 16) |
            ((ord($hash[$offset + 2]) & 0xff) << 8) |
            (ord($hash[$offset + 3]) & 0xff)
        ) % 1000000;

        return str_pad($code, 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode($secret)
    {
        $base32chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $base32charsFlipped = array_flip(str_split($base32chars));

        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = [6, 4, 3, 1, 0];
        if (!in_array($paddingCharCount, $allowedValues)) {
            return false;
        }

        for ($i = 0; $i < 4; $i++) {
            if (
                $paddingCharCount == $allowedValues[$i] &&
                substr($secret, -($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])
            ) {
                return false;
            }
        }

        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = '';

        for ($i = 0; $i < count($secret); $i = $i + 8) {
            $x = '';
            if (!in_array($secret[$i], $base32charsFlipped)) {
                return false;
            }
            for ($j = 0; $j < 8; $j++) {
                $x .= str_pad(base_convert(@$base32charsFlipped[@$secret[$i + $j]], 10, 2), 5, '0', STR_PAD_LEFT);
            }
            $eightBits = str_split($x, 8);
            for ($z = 0; $z < count($eightBits); $z++) {
                $binaryString .= (($y = chr(base_convert($eightBits[$z], 2, 10))) || ord($y) == 48) ? $y : '';
            }
        }

        return $binaryString;
    }

    public function enableTOTP($userId, $secret)
    {
        $stmt = $this->db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?");
        return $stmt->execute([$secret, $userId]);
    }

    public function disableTOTP($userId)
    {
        $stmt = $this->db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?");
        return $stmt->execute([$userId]);
    }

    public function changePassword($userId, $currentPassword, $newPassword)
    {
        $stmt = $this->db->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($currentPassword, $user['password'])) {
            return false;
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        return $stmt->execute([$hashedPassword, $userId]);
    }
}
