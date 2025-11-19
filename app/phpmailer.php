<?php

class SimpleMailer {
    private $host;
    private $port;
    private $username;
    private $password;

    public function __construct($host, $port, $username, $password) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
    }

    public function send($from, $to, $subject, $body, $isHtml = true) {
        $headers = "MIME-Version: 1.0\r\n";
        if ($isHtml) {
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        } else {
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        }
        $headers .= "From: $from\r\n";
        $headers .= "Reply-To: $from\r\n";

        // Use SMTP if configured
        if (!empty($this->host) && function_exists('stream_socket_client')) {
            return $this->sendSMTP($from, $to, $subject, $body, $isHtml);
        }

        // Fallback to PHP mail()
        return mail($to, $subject, $body, $headers);
    }

    private function sendSMTP($from, $to, $subject, $body, $isHtml) {
        try {
            $socket = stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                30
            );

            if (!$socket) {
                error_log("SMTP connection failed: $errstr ($errno)");
                return false;
            }

            $this->readResponse($socket);

            // EHLO
            fwrite($socket, "EHLO {$this->host}\r\n");
            $this->readResponse($socket);

            // STARTTLS if port 587 and not localhost/mailhog (port 1025 is MailHog, no TLS)
            $skipTLS = in_array($this->host, ['localhost', '127.0.0.1']) && $this->port == 1025;
            
            if ($this->port == 587 && !$skipTLS) {
                fwrite($socket, "STARTTLS\r\n");
                $response = $this->readResponse($socket);
                
                // Only enable crypto if STARTTLS was successful
                if (strpos($response, '220') === 0) {
                    stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    fwrite($socket, "EHLO {$this->host}\r\n");
                    $this->readResponse($socket);
                }
            }

            // AUTH LOGIN
            if (!empty($this->username) && !empty($this->password)) {
                fwrite($socket, "AUTH LOGIN\r\n");
                $this->readResponse($socket);
                fwrite($socket, base64_encode($this->username) . "\r\n");
                $this->readResponse($socket);
                fwrite($socket, base64_encode($this->password) . "\r\n");
                $this->readResponse($socket);
            }

            // MAIL FROM
            fwrite($socket, "MAIL FROM: <$from>\r\n");
            $this->readResponse($socket);

            // RCPT TO
            fwrite($socket, "RCPT TO: <$to>\r\n");
            $this->readResponse($socket);

            // DATA
            fwrite($socket, "DATA\r\n");
            $this->readResponse($socket);

            // Headers and body
            $contentType = $isHtml ? 'text/html' : 'text/plain';
            $message = "From: $from\r\n";
            $message .= "To: $to\r\n";
            $message .= "Subject: $subject\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: $contentType; charset=UTF-8\r\n";
            $message .= "\r\n";
            $message .= $body;
            $message .= "\r\n.\r\n";

            fwrite($socket, $message);
            $this->readResponse($socket);

            // QUIT
            fwrite($socket, "QUIT\r\n");
            $this->readResponse($socket);

            fclose($socket);
            return true;
        } catch (Exception $e) {
            error_log("SMTP error: " . $e->getMessage());
            return false;
        }
    }

    private function readResponse($socket) {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $response;
    }
}
