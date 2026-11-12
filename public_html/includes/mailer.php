<?php
/**
 * includes/mailer.php – Email utilities for Family Tree SaaS
 *
 * Sends through SMTP when SMTP_HOST is set, and falls back to PHP mail()
 * (which needs a local MTA) otherwise. Useful environment variables:
 *   APP_URL=https://your-domain.example
 *   MAIL_FROM_EMAIL=noreply@your-domain.example
 *   MAIL_FROM_NAME=Family Tree
 *   SMTP_HOST=smtp.your-provider.example
 *   SMTP_PORT=587
 *   SMTP_SECURE=tls          # tls (STARTTLS), ssl (implicit TLS) or none
 *   SMTP_USER=postmaster@your-domain.example
 *   SMTP_PASS=your-password
 */

// Guard: don't allow direct web access
if (!defined('FT_APP')) {
    http_response_code(403);
    exit;
}

// ── Configuration helpers ─────────────────────────────────────────────────
/** Read an environment variable, treating an empty value as unset. */
function ft_mail_env(string $key, string $default = ''): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return (string)$value;
}

/** Strip anything that could break or inject a mail header. */
function ft_mail_clean(string $value): string {
    return trim(str_replace(["\r", "\n", "\0"], '', $value));
}

/** Keep only the characters that can belong to an envelope address. */
function ft_mail_address(string $value): string {
    return str_replace(['<', '>', ' ', "\t", ',', ';'], '', ft_mail_clean($value));
}

/** RFC 2047 encode non-ASCII header text (names, subjects). */
function ft_mail_header_text(string $value): string {
    $value = ft_mail_clean($value);
    if ($value !== '' && preg_match('/[\x80-\xFF]/', $value)) {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
    return $value;
}

/** Build a From/To header value such as `"Family Tree" <mail@example.com>`. */
function ft_mail_mailbox(string $name, string $email): string {
    $name = ft_mail_clean($name);
    if ($name === '') {
        return $email;
    }
    if (preg_match('/[\x80-\xFF]/', $name)) {
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }
    return '"' . str_replace('"', '', $name) . '" <' . $email . '>';
}

/**
 * SMTP settings, or an empty array when SMTP is not configured.
 * SMTP_HOST empty means PHP's mail() is used instead.
 */
function ft_mail_smtp_config(): array {
    $host = trim(ft_mail_env('SMTP_HOST'));
    if ($host === '') {
        return [];
    }

    $secure = strtolower(trim(ft_mail_env('SMTP_SECURE', 'tls')));
    if (!in_array($secure, ['tls', 'ssl', 'none'], true)) {
        $secure = 'tls';
    }

    $defaultPort = $secure === 'ssl' ? 465 : 587;
    $port = (int)ft_mail_env('SMTP_PORT', (string)$defaultPort);
    $password = getenv('SMTP_PASS');

    return [
        'host'    => $host,
        'port'    => $port > 0 ? $port : $defaultPort,
        'secure'  => $secure,
        'user'    => trim(ft_mail_env('SMTP_USER')),
        'pass'    => $password === false ? '' : (string)$password,
        'timeout' => max(3, (int)ft_mail_env('SMTP_TIMEOUT', '15')),
    ];
}

// ── SMTP client ───────────────────────────────────────────────────────────
/** Read one (possibly multi-line) SMTP reply. @return array{0:int,1:string} */
function ft_smtp_reply($socket): array {
    $code  = 0;
    $lines = [];
    while (($line = fgets($socket, 1024)) !== false) {
        $lines[] = rtrim($line, "\r\n");
        if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
            $code = (int)$m[1];
            if ($m[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return [$code, implode("\n", $lines)];
}

/** Log the failure, end the session politely and return false. */
function ft_smtp_fail($socket, int $code, string $stage): bool {
    error_log('sendEmail SMTP ' . ($code > 0 ? "{$code} " : '') . $stage);
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);
    return false;
}

/** Send one message over SMTP. Returns true when the server accepted it. */
function ft_smtp_send(
    array $cfg,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $fromEmail,
    string $fromName,
    string $messageId
): bool {
    $target = ($cfg['secure'] === 'ssl' ? 'ssl://' : '') . $cfg['host'] . ':' . $cfg['port'];
    $errno  = 0;
    $errstr = '';
    $socket = @stream_socket_client($target, $errno, $errstr, $cfg['timeout'], STREAM_CLIENT_CONNECT);
    if (!$socket) {
        error_log("sendEmail SMTP connect failed: {$target} {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($socket, $cfg['timeout']);

    // Greeting
    [$code, $text] = ft_smtp_reply($socket);
    if ($code !== 220) {
        return ft_smtp_fail($socket, $code, 'greeting: ' . $text);
    }

    $ehloHost = ft_mail_clean(ft_mail_env('SMTP_EHLO_HOST', ft_mail_env('APP_DOMAIN', 'localhost'))) ?: 'localhost';

    // EHLO, needed both for STARTTLS and AUTH
    fwrite($socket, "EHLO {$ehloHost}\r\n");
    [$code, $text] = ft_smtp_reply($socket);
    if ($code !== 250) {
        return ft_smtp_fail($socket, $code, 'EHLO: ' . $text);
    }

    if ($cfg['secure'] === 'tls') {
        fwrite($socket, "STARTTLS\r\n");
        [$code, $text] = ft_smtp_reply($socket);
        if ($code !== 220) {
            return ft_smtp_fail($socket, $code, 'STARTTLS: ' . $text);
        }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            return ft_smtp_fail($socket, 0, 'TLS negotiation failed');
        }
        // Extensions (including AUTH) may differ once TLS is active
        fwrite($socket, "EHLO {$ehloHost}\r\n");
        [$code, $text] = ft_smtp_reply($socket);
        if ($code !== 250) {
            return ft_smtp_fail($socket, $code, 'EHLO after STARTTLS: ' . $text);
        }
    }

    if ($cfg['user'] !== '') {
        fwrite($socket, "AUTH LOGIN\r\n");
        [$code, $text] = ft_smtp_reply($socket);
        if ($code !== 334) {
            return ft_smtp_fail($socket, $code, 'AUTH LOGIN: ' . $text);
        }
        fwrite($socket, base64_encode($cfg['user']) . "\r\n");
        [$code, $text] = ft_smtp_reply($socket);
        if ($code !== 334) {
            return ft_smtp_fail($socket, $code, 'AUTH username: ' . $text);
        }
        fwrite($socket, base64_encode($cfg['pass']) . "\r\n");
        [$code, $text] = ft_smtp_reply($socket);
        if ($code !== 235) {
            return ft_smtp_fail($socket, $code, 'AUTH password: ' . $text);
        }
    }

    fwrite($socket, "MAIL FROM:<{$fromEmail}>\r\n");
    [$code, $text] = ft_smtp_reply($socket);
    if ($code !== 250) {
        return ft_smtp_fail($socket, $code, 'MAIL FROM: ' . $text);
    }

    fwrite($socket, "RCPT TO:<{$toEmail}>\r\n");
    [$code, $text] = ft_smtp_reply($socket);
    if (!in_array($code, [250, 251], true)) {
        return ft_smtp_fail($socket, $code, 'RCPT TO: ' . $text);
    }

    fwrite($socket, "DATA\r\n");
    [$code, $text] = ft_smtp_reply($socket);
    if ($code !== 354) {
        return ft_smtp_fail($socket, $code, 'DATA: ' . $text);
    }

    $headers = implode("\r\n", [
        'Date: ' . date('r'),
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'From: ' . ft_mail_mailbox($fromName, $fromEmail),
        'To: ' . ft_mail_mailbox($toName, $toEmail),
        'Reply-To: ' . $fromEmail,
        'Subject: ' . ft_mail_header_text($subject),
        'Message-ID: ' . $messageId,
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);

    // Base64 keeps the body 7-bit safe, so no 8BITMIME negotiation is needed.
    $body = rtrim(chunk_split(base64_encode($htmlBody), 76, "\r\n"));
    $data = $headers . "\r\n\r\n" . $body;
    // Normalise line endings, then dot-stuff data lines (RFC 5321 4.5.2).
    $data = str_replace(["\r\n", "\r", "\n"], "\n", $data);
    $data = preg_replace('/^\./m', '..', $data);
    $data = str_replace("\n", "\r\n", $data);

    fwrite($socket, $data . "\r\n.\r\n");
    [$code, $text] = ft_smtp_reply($socket);
    if ($code !== 250) {
        return ft_smtp_fail($socket, $code, 'message body: ' . $text);
    }

    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);
    return true;
}

// ── Core send function ────────────────────────────────────────────────────
function sendEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody
): bool {
    $fromEmail = ft_mail_address(ft_mail_env(
        'MAIL_FROM_EMAIL',
        'noreply@' . (ft_mail_env('APP_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'))
    ));
    $fromName  = ft_mail_clean(ft_mail_env('MAIL_FROM_NAME', 'Family Tree'));
    $appDomain = ft_mail_clean(ft_mail_env('APP_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost')) ?: 'localhost';
    $toEmail   = ft_mail_address($toEmail);
    $toName    = ft_mail_clean($toName);
    $subject   = ft_mail_clean($subject);
    $messageId = '<' . uniqid('ft_', true) . '@' . $appDomain . '>';

    $smtp = ft_mail_smtp_config();
    if ($smtp) {
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('sendEmail SMTP: MAIL_FROM_EMAIL is missing or invalid');
            return false;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            error_log("sendEmail SMTP: invalid recipient {$toEmail}");
            return false;
        }
        return ft_smtp_send($smtp, $toEmail, $toName, $subject, $htmlBody, $fromEmail, $fromName, $messageId);
    }

    $toHeader = $toName !== '' ? ft_mail_mailbox($toName, $toEmail) : $toEmail;

    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . ft_mail_mailbox($fromName, $fromEmail),
        'Reply-To: ' . $fromEmail,
        'Message-ID: ' . $messageId,
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);

    $result = @mail($toHeader, ft_mail_header_text($subject), $htmlBody, $headers);
    if (!$result) {
        error_log("sendEmail failed: to={$toEmail} subject={$subject}");
    }
    return $result;
}

// ── Email templates ───────────────────────────────────────────────────────
function emailWrap(string $appName, string $content): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$appName}</title>
</head>
<body style="margin:0;padding:0;background:#0f1117;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#0f1117;padding:40px 20px;">
  <tr><td align="center">
    <table width="560" cellpadding="0" cellspacing="0" style="background:#1a1d26;border-radius:12px;overflow:hidden;max-width:560px;">
      <tr>
        <td style="background:#2a6496;padding:28px 36px;">
          <h1 style="margin:0;color:#fff;font-size:22px;font-weight:600;">{$appName}</h1>
        </td>
      </tr>
      <tr>
        <td style="padding:36px;color:#c8cad4;font-size:15px;line-height:1.7;">
          {$content}
        </td>
      </tr>
      <tr>
        <td style="padding:20px 36px;border-top:1px solid #2a2d3a;color:#5a5d6b;font-size:12px;">
          You're receiving this because you registered at {$appName}.
          If you didn't register, ignore this email.
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body>
</html>
HTML;
}

function btnStyle(): string {
    return 'display:inline-block;padding:13px 28px;background:#2a6496;color:#fff;text-decoration:none;'
         . 'border-radius:7px;font-size:15px;font-weight:600;margin:20px 0;';
}

// ── Password reset email ───────────────────────────────────────────────────
function sendPasswordResetEmail(string $toEmail, string $toName, string $token): bool {
    $appUrl  = rtrim(getenv('APP_URL') ?: ('https://' . (getenv('APP_DOMAIN') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'))), '/');
    $appName = getenv('MAIL_FROM_NAME') ?: 'Family Tree';
    $url     = $appUrl . '/admin/reset-password.php?token=' . urlencode($token);

    $content = '
        <p>Hi ' . htmlspecialchars($toName) . ',</p>
        <p>We received a request to reset the password for your account.
           Click the button below to choose a new password.</p>
        <p><a href="' . htmlspecialchars($url) . '" style="' . btnStyle() . '">Reset My Password</a></p>
        <p style="font-size:13px;color:#5a5d6b;">
            Or copy this link into your browser:<br>
            <span style="color:#7ab3d4;">' . htmlspecialchars($url) . '</span>
        </p>
        <p style="font-size:13px;color:#5a5d6b;">
            This link expires in <strong style="color:#c8cad4;">1 hour</strong>.<br>
            If you did not request a password reset, you can safely ignore this email.
        </p>';

    return sendEmail($toEmail, $toName, 'Reset your password – ' . $appName, emailWrap($appName, $content));
}
