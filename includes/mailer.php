<?php
/**
 * includes/mailer.php
 * Simple pure-PHP SMTP mailer — no external dependencies.
 *
 * Usage:
 *   sendAppEmail('recipient@example.com', 'Name', 'Subject', '<p>HTML body</p>');
 *
 * Uses settings: smtp_host, smtp_port, smtp_user, smtp_pass, company_email, company_name.
 * Only sends when email_notifications = 1.
 */

require_once __DIR__ . '/functions.php';

/**
 * Send an HTML email via the configured SMTP server.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $htmlBody
 * @return bool  true on success, false on failure
 */
function sendAppEmail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    if (getSetting('email_notifications', '0') !== '1') {
        return false;
    }

    $smtpHost  = getSetting('smtp_host', '');
    $smtpPort  = (int) getSetting('smtp_port', '587');
    $smtpUser  = getSetting('smtp_user', '');
    $smtpPass  = getSetting('smtp_pass', '');
    $fromEmail = getSetting('company_email', $smtpUser);
    $fromName  = getSetting('company_name', 'HelpDesk');

    if (!$smtpHost || !$toEmail) {
        return false;
    }

    try {
        return _smtpSend($smtpHost, $smtpPort, $smtpUser, $smtpPass, $fromEmail, $fromName, $toEmail, $toName, $subject, $htmlBody);
    } catch (Exception $e) {
        error_log('[Mailer] ' . $e->getMessage());
        return false;
    }
}

/**
 * Core SMTP send using stream sockets.
 * Supports STARTTLS (port 587) and implicit SSL (port 465).
 */
function _smtpSend(
    string $host,
    int    $port,
    string $user,
    string $pass,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody
): bool {
    $timeout = 15;
    $useSSL  = ($port === 465);

    $address = ($useSSL ? 'ssl://' : '') . $host . ':' . $port;
    $ctx     = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
        ],
    ]);

    $conn = stream_socket_client($address, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$conn) {
        throw new Exception("SMTP connect failed ($errno): $errstr");
    }
    stream_set_timeout($conn, $timeout);

    // Helper closures
    $read = function () use ($conn): string {
        $buf = '';
        while ($line = fgets($conn, 512)) {
            $buf .= $line;
            if (isset($line[3]) && $line[3] === ' ') break; // last line of response
        }
        return $buf;
    };
    $send = function (string $cmd) use ($conn): void {
        fwrite($conn, $cmd . "\r\n");
    };
    $expect = function (string $code) use ($read): string {
        $r = $read();
        if (strncmp($r, $code, strlen($code)) !== 0) {
            throw new Exception("SMTP unexpected response: $r");
        }
        return $r;
    };

    $read(); // server greeting

    // EHLO
    $send('EHLO ' . (gethostname() ?: 'localhost'));
    $ehlo = $read();

    // STARTTLS upgrade (port 587 / plain)
    if (!$useSSL && strpos($ehlo, 'STARTTLS') !== false) {
        $send('STARTTLS');
        $expect('220');
        if (!stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            throw new Exception('STARTTLS crypto enable failed');
        }
        // Re-EHLO after TLS
        $send('EHLO ' . (gethostname() ?: 'localhost'));
        $read();
    }

    // AUTH LOGIN
    if ($user !== '') {
        $send('AUTH LOGIN');
        $expect('334');
        $send(base64_encode($user));
        $expect('334');
        $send(base64_encode($pass));
        $expect('235');
    }

    // MAIL FROM
    $send("MAIL FROM:<{$fromEmail}>");
    $expect('250');

    // RCPT TO
    $send("RCPT TO:<{$toEmail}>");
    $expect('250');

    // DATA
    $send('DATA');
    $expect('354');

    // Build MIME message
    $boundary = '=_' . md5(uniqid('', true));
    $plain    = strip_tags($htmlBody);

    $date    = date('r');
    $msgId   = '<' . md5(uniqid('', true)) . '@' . ($GLOBALS['_SERVER']['HTTP_HOST'] ?? 'localhost') . '>';
    $encFrom = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encTo   = '=?UTF-8?B?' . base64_encode($toName) . '?=';
    $encSubj = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers  = "Date: {$date}\r\n";
    $headers .= "From: {$encFrom} <{$fromEmail}>\r\n";
    $headers .= "To: {$encTo} <{$toEmail}>\r\n";
    $headers .= "Subject: {$encSubj}\r\n";
    $headers .= "Message-ID: {$msgId}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: HelpDesk/1.0\r\n";

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($plain)) . "\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $body .= "--{$boundary}--\r\n";

    // Dot-stuffing: lines starting with '.' must be doubled
    $body = preg_replace('/^\.$/m', '..', $body);

    fwrite($conn, $headers . "\r\n" . $body . "\r\n.\r\n");
    $expect('250');

    $send('QUIT');
    fclose($conn);

    return true;
}

/**
 * Render a standard branded HTML email template.
 *
 * @param string $title    Email heading
 * @param string $content  Inner HTML content (paragraphs, tables, etc.)
 * @return string
 */
function buildEmailHtml(string $title, string $content): string
{
    $company  = htmlspecialchars(getSetting('company_name', 'HelpDesk'));
    $primary  = htmlspecialchars(getSetting('theme_primary_color', '#3b82f6'));

    return <<<HTML
<!DOCTYPE html>
<html lang="it">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;padding:24px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
        <tr>
          <td style="background:{$primary};padding:20px 32px;">
            <span style="color:#ffffff;font-size:18px;font-weight:700;">{$company}</span>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <h2 style="margin:0 0 16px;color:#0f172a;font-size:20px;">{$title}</h2>
            {$content}
            <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0;">
            <p style="color:#94a3b8;font-size:12px;margin:0;">Questa è una notifica automatica del sistema {$company}. Non rispondere a questa email.</p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
