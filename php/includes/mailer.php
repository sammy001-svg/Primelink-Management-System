<?php
/**
 * Email Mailer Helper
 * Primelink Management System
 * Supports PHP mail() and raw SMTP (no external dependencies).
 */

require_once __DIR__ . '/settings.php';

/**
 * Send an HTML email.
 * Returns true on success, false on failure.
 */
function sendSystemEmail(PDO $pdo, string $to, string $subject, string $htmlBody, string $toName = ''): bool {
    $driver    = getSetting($pdo, 'mail_driver',      'mail');
    $fromName  = getSetting($pdo, 'smtp_from_name',   'Primelink Management');
    $fromEmail = getSetting($pdo, 'smtp_from_email',  'noreply@primelink.co.ke');

    $plainText = strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $htmlBody));

    if ($driver === 'smtp') {
        return _sendSmtp($pdo, $to, $toName, $fromEmail, $fromName, $subject, $htmlBody, $plainText);
    }

    // Fall back to PHP mail()
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "Reply-To: {$fromEmail}\r\n";
    $headers .= "X-Mailer: Primelink/1.0\r\n";

    return @mail($to, $subject, $htmlBody, $headers);
}

/**
 * Send via raw SMTP using fsockopen (supports STARTTLS on port 587).
 */
function _sendSmtp(PDO $pdo, string $to, string $toName, string $from, string $fromName, string $subject, string $html, string $text): bool {
    $host = getSetting($pdo, 'smtp_host', '');
    $port = (int)getSetting($pdo, 'smtp_port', '587');
    $user = getSetting($pdo, 'smtp_user', '');
    $pass = getSetting($pdo, 'smtp_pass', '');

    if (empty($host) || empty($user) || empty($pass)) return false;

    try {
        $boundary = md5(uniqid());
        $toLine   = $toName ? "{$toName} <{$to}>" : $to;

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
        $body .= "--{$boundary}--";

        $msg  = "From: {$fromName} <{$from}>\r\n";
        $msg .= "To: {$toLine}\r\n";
        $msg .= "Subject: {$subject}\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
        $msg .= "\r\n{$body}";

        $sock = fsockopen($host, $port, $errno, $errstr, 10);
        if (!$sock) return false;

        $read = function() use ($sock) { return fgets($sock, 1024); };
        $send = function(string $cmd) use ($sock) { fputs($sock, $cmd . "\r\n"); };

        $read(); // greeting
        $send("EHLO " . gethostname());
        while (($line = $read()) && substr($line, 3, 1) === '-') {}

        if ($port === 587) {
            $send("STARTTLS");
            $read();
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
            $send("EHLO " . gethostname());
            while (($line = $read()) && substr($line, 3, 1) === '-') {}
        }

        $send("AUTH LOGIN");
        $read();
        $send(base64_encode($user));
        $read();
        $send(base64_encode($pass));
        $resp = $read();
        if (substr(trim($resp), 0, 3) !== '235') { fclose($sock); return false; }

        $send("MAIL FROM:<{$from}>");
        $read();
        $send("RCPT TO:<{$to}>");
        $read();
        $send("DATA");
        $read();
        $send($msg . "\r\n.");
        $read();
        $send("QUIT");
        fclose($sock);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Build a standard branded HTML email.
 */
function buildEmailHtml(PDO $pdo, string $heading, string $bodyHtml, string $ctaText = '', string $ctaUrl = ''): string {
    $company = getSetting($pdo, 'company_name',    'Primelink Management System');
    $address = getSetting($pdo, 'company_address', 'Nairobi, Kenya');
    $phone   = getSetting($pdo, 'company_phone',   '');

    $cta = $ctaText && $ctaUrl
        ? "<p style='text-align:center;margin:28px 0'><a href='{$ctaUrl}' style='display:inline-block;background:#22c55e;color:#fff;padding:12px 28px;border-radius:10px;font-weight:900;font-size:14px;text-decoration:none'>{$ctaText}</a></p>"
        : '';

    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 20px">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08)">
        <!-- Header -->
        <tr><td style="background:#0f172a;padding:28px 40px">
          <h1 style="margin:0;color:#22c55e;font-size:22px;font-weight:900;letter-spacing:-0.5px">{$company}</h1>
          <p style="margin:4px 0 0;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:2px">Official Notification</p>
        </td></tr>
        <!-- Body -->
        <tr><td style="padding:40px">
          <h2 style="margin:0 0 20px;color:#0f172a;font-size:20px;font-weight:900">{$heading}</h2>
          {$bodyHtml}
          {$cta}
        </td></tr>
        <!-- Footer -->
        <tr><td style="background:#f8fafc;padding:20px 40px;border-top:1px solid #e2e8f0">
          <p style="margin:0;font-size:11px;color:#94a3b8;text-align:center">{$company} &bull; {$address} &bull; {$phone}</p>
          <p style="margin:6px 0 0;font-size:10px;color:#cbd5e1;text-align:center">This is an automated message. Please do not reply directly to this email.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}
