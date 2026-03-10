<?php
/**
 * QAIAO Portal — Mailer
 * University of Eastern Pangasinan
 * Quality Assurance, Internationalization and Accreditation Office
 *
 * Tries SMTP first (fsockopen, no Composer needed).
 * Falls back to PHP's built-in mail() if SMTP is not configured or fails.
 *
 * Configure credentials in mail/config.php.
 */

require_once __DIR__ . '/config.php';

/**
 * Send an email. Returns true on success, false on failure.
 */
function qa_send_mail($to, string $subject, string $html, string $plain = ''): bool
{
    if (!MAIL_ENABLED) return true;

    $GLOBALS['qa_mail_last_error'] = '';

    if (empty($plain)) {
        $plain = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $html));
        $plain = preg_replace("/\n{3,}/", "\n\n", $plain);
    }

    if (is_string($to)) {
        $recipients = [[$to, '']];
    } elseif (isset($to['email'])) {
        $recipients = [[$to['email'], $to['name'] ?? '']];
    } else {
        $recipients = [];
        foreach ($to as $email => $name) {
            $recipients[] = is_int($email) ? [$name, ''] : [$email, $name];
        }
    }

    $smtp_configured = (
        defined('SMTP_USER') && SMTP_USER !== '' && SMTP_USER !== 'your_email@gmail.com' &&
        defined('SMTP_PASS') && SMTP_PASS !== '' && SMTP_PASS !== 'your_app_password_here'
    );

    if ($smtp_configured) {
        $ok = _qa_smtp_send($recipients, $subject, $html, $plain);
        if ($ok) return true;
        $smtp_err = $GLOBALS['qa_mail_last_error'] ?? 'SMTP failed';
        error_log("QA Mailer: SMTP failed ({$smtp_err}), falling back to mail()");
    }

    return _qa_native_send($recipients, $subject, $html, $plain);
}

function _qa_smtp_send(array $recipients, string $subject, string $html, string $plain): bool
{
    $boundary = '=_Part_' . md5(microtime());
    $msg_id   = '<' . microtime(true) . '.' . rand(100000, 999999) . '@' . (gethostname() ?: 'localhost') . '>';
    $date     = date('r');
    $to_hdr   = implode(', ', array_map(function($r) { return $r[1] ? "\"{$r[1]}\" <{$r[0]}>" : $r[0]; }, $recipients));

    $msg  = "Date: $date\r\n";
    $msg .= "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $msg .= "To: $to_hdr\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $msg .= "Message-ID: $msg_id\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";
    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $msg .= quoted_printable_encode($plain) . "\r\n\r\n";
    $msg .= "--$boundary\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $msg .= quoted_printable_encode($html) . "\r\n\r\n";
    $msg .= "--$boundary--\r\n";

    $host    = SMTP_HOST;
    $port    = SMTP_PORT;
    $timeout = 15;
    $errno   = 0;
    $errstr  = '';

    $stream = (SMTP_SECURE === 'ssl')
        ? @fsockopen("ssl://$host", $port, $errno, $errstr, $timeout)
        : @fsockopen($host, $port, $errno, $errstr, $timeout);

    if (!$stream) {
        $err = "Cannot connect to SMTP {$host}:{$port} — {$errstr} ({$errno})";
        error_log("QA Mailer: $err");
        $GLOBALS['qa_mail_last_error'] = $err;
        return false;
    }

    try {
        _smtp_expect($stream, '220');
        _smtp_cmd($stream, "EHLO " . (gethostname() ?: 'localhost'));
        _smtp_read_full($stream);

        if (SMTP_SECURE === 'tls') {
            _smtp_cmd($stream, "STARTTLS");
            _smtp_expect($stream, '220');
            if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException("STARTTLS negotiation failed");
            }
            _smtp_cmd($stream, "EHLO " . (gethostname() ?: 'localhost'));
            _smtp_read_full($stream);
        }

        if (SMTP_AUTH) {
            _smtp_cmd($stream, "AUTH LOGIN");
            _smtp_expect($stream, '334');
            _smtp_cmd($stream, base64_encode(SMTP_USER));
            _smtp_expect($stream, '334');
            _smtp_cmd($stream, base64_encode(SMTP_PASS));
            _smtp_expect($stream, '235');
        }

        _smtp_cmd($stream, "MAIL FROM:<" . MAIL_FROM . ">");
        _smtp_expect($stream, '250');

        foreach ($recipients as [$addr, $_]) {
            _smtp_cmd($stream, "RCPT TO:<$addr>");
            _smtp_expect($stream, '250');
        }

        _smtp_cmd($stream, "DATA");
        _smtp_expect($stream, '354');

        foreach (explode("\r\n", $msg) as $line) {
            if (strlen($line) > 0 && $line[0] === '.') $line = '.' . $line;
            fwrite($stream, $line . "\r\n");
        }
        _smtp_cmd($stream, ".");
        _smtp_expect($stream, '250');
        _smtp_cmd($stream, "QUIT");

    } catch (\Throwable $e) {
        $err = $e->getMessage();
        error_log("QA Mailer SMTP: $err");
        $GLOBALS['qa_mail_last_error'] = $err;
        fclose($stream);
        return false;
    }

    fclose($stream);
    return true;
}

function _qa_native_send(array $recipients, string $subject, string $html, string $plain): bool
{
    $boundary = '=_Part_' . md5(microtime());
    $to_str   = implode(', ', array_map(function($r) { return $r[1] ? "\"{$r[1]}\" <{$r[0]}>" : $r[0]; }, $recipients));

    $headers  = "From: " . MAIL_FROM_NAME . " <" . MAIL_FROM . ">\r\n";
    $headers .= "Reply-To: " . MAIL_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n";
    $headers .= "X-Mailer: QAIAO-Portal-PHP\r\n";

    $body  = "--$boundary\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($plain) . "\r\n\r\n";
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: quoted-printable\r\n\r\n";
    $body .= quoted_printable_encode($html) . "\r\n\r\n";
    $body .= "--$boundary--\r\n";

    $ok = @mail($to_str, "=?UTF-8?B?" . base64_encode($subject) . "?=", $body, $headers);
    if (!$ok) {
        $err = "PHP mail() returned false — check server sendmail/postfix config.";
        error_log("QA Mailer: $err");
        $GLOBALS['qa_mail_last_error'] = $err;
    }
    return (bool)$ok;
}

// ── SMTP low-level helpers ──────────────────────────────────
function _smtp_cmd($stream, string $cmd): void { fwrite($stream, $cmd . "\r\n"); }

function _smtp_expect($stream, string $code): string
{
    $response = _smtp_read_full($stream);
    if (substr(trim($response), 0, 3) !== $code) {
        throw new \RuntimeException("SMTP expected $code, got: " . trim($response));
    }
    return $response;
}

function _smtp_read_full($stream): string
{
    $data = '';
    while (!feof($stream)) {
        $line  = fgets($stream, 515);
        $data .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
        if (strlen($line) < 4) break;
    }
    return $data;
}

/* ─────────────────────────────────────────────────────────────
   UEP / QAIAO EMAIL TEMPLATE HELPERS
───────────────────────────────────────────────────────────── */

/**
 * Build the absolute base URL of the project from APP_URL.
 * APP_URL may end with a .php file — we strip it to get the folder.
 * e.g. http://localhost/QA_SYSTEM_V27/qa_system/login.php
 *   -> http://localhost/QA_SYSTEM_V27/qa_system
 */
function _qa_base_url(): string
{
    $url = defined('APP_URL') ? APP_URL : '';
    // Strip trailing filename (anything ending in .php)
    $url = preg_replace('/\/[^\/]+\.php$/i', '', $url);
    return rtrim($url, '/');
}

/**
 * Main email wrapper template.
 * Logo is loaded from images/UEP_logo.png on your server.
 */
function qa_email_template(string $title, string $content, string $footer = ''): string
{
    $year     = date('Y');
    $logo_url = _qa_base_url() . 'D:\xampp\htdocs\qa_system_v35\qa_system\images\UEP_logo.png';

    if (!$footer) {
        $footer = 'This is an automated message from the QAIAO Portal. Please do not reply to this email.';
    }

    // Build the full HTML as a string — no heredoc to avoid whitespace pitfalls
    $html  = '<!DOCTYPE html>';
    $html .= '<html lang="en">';
    $html .= '<head>';
    $html .= '<meta charset="UTF-8">';
    $html .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $html .= '<title>' . htmlspecialchars($title) . '</title>';
    $html .= '</head>';
    $html .= '<body style="margin:0;padding:0;background:#0f0505;font-family:\'Segoe UI\',Arial,sans-serif;">';

    // Outer table
    $html .= '<table width="100%" cellpadding="0" cellspacing="0" style="background:#0f0505;padding:40px 20px;">';
    $html .= '<tr><td align="center">';

    // Card table
    $html .= '<table width="100%" style="max-width:580px;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 8px 40px rgba(0,0,0,0.45);">';

    // ── HEADER ──
    $html .= '<tr>';
    $html .= '<td style="padding:0;background:linear-gradient(160deg,#3a0000 0%,#6B0000 45%,#1a0a14 100%);">';
    // Gold shimmer line
    $html .= '<div style="height:3px;background:linear-gradient(to right,transparent,#c9a84c,#e8c96e,#c9a84c,transparent);"></div>';
    $html .= '<table width="100%" cellpadding="0" cellspacing="0">';
    $html .= '<tr><td style="padding:32px 40px 28px;text-align:center;">';

    // UEP Logo — hosted on your server
    $html .= '<div style="margin-bottom:16px;">';
    $html .= '<img src="' . $logo_url . '" alt="UEP Logo" width="80" height="80"';
    $html .= ' style="display:block;margin:0 auto;border-radius:50%;border:2px solid rgba(201,168,76,0.6);box-shadow:0 4px 16px rgba(0,0,0,0.5);">';
    $html .= '</div>';

    // QAIAO badge
    $html .= '<div style="display:inline-block;background:linear-gradient(135deg,#4a0000,#6B0000);color:white;font-size:10px;font-weight:700;letter-spacing:3px;text-transform:uppercase;padding:5px 16px;border-radius:100px;margin-bottom:14px;box-shadow:0 0 20px rgba(107,0,0,0.6);">QAIAO Portal</div>';

    // University name
    $html .= '<div style="color:rgba(255,255,255,0.5);font-size:10px;letter-spacing:2px;text-transform:uppercase;margin-bottom:6px;">University of Eastern Pangasinan</div>';

    // Office name
    $html .= '<div style="color:#e8c96e;font-size:13px;font-weight:600;letter-spacing:0.5px;line-height:1.6;">';
    $html .= 'Quality Assurance, Internationalization<br>and Accreditation Office';
    $html .= '</div>';

    // Gold divider
    $html .= '<div style="width:48px;height:1px;background:linear-gradient(to right,transparent,#c9a84c,transparent);margin:16px auto 0;"></div>';

    $html .= '</td></tr>';
    $html .= '</table>';
    $html .= '</td></tr>';

    // ── TITLE STRIP ──
    $html .= '<tr>';
    $html .= '<td style="background:#1a0505;padding:18px 40px;border-bottom:1px solid rgba(201,168,76,0.15);">';
    $html .= '<h2 style="margin:0;color:#ffffff;font-size:18px;font-weight:700;letter-spacing:0.3px;">' . htmlspecialchars($title) . '</h2>';
    $html .= '</td></tr>';

    // ── BODY ──
    $html .= '<tr>';
    $html .= '<td style="background:#ffffff;padding:36px 40px;">';
    $html .= $content;
    $html .= '</td></tr>';

    // ── FOOTER ──
    $html .= '<tr>';
    $html .= '<td style="background:#f5f5f8;padding:20px 40px;border-top:1px solid #e8edf5;text-align:center;">';
    $html .= '<div style="width:40px;height:2px;background:#C9A84C;margin:0 auto 14px;border-radius:2px;"></div>';
    $html .= '<p style="margin:0;color:#8a94a6;font-size:11px;line-height:1.7;">' . htmlspecialchars($footer) . '</p>';
    $html .= '<p style="margin:10px 0 0;color:#b0b9c6;font-size:10px;">&copy; ' . $year . ' University of Eastern Pangasinan &mdash; QAIAO Portal. All rights reserved.</p>';
    $html .= '</td></tr>';

    $html .= '</table>';
    $html .= '</td></tr></table>';
    $html .= '</body></html>';

    return $html;
}

function qa_email_btn(string $url, string $label, string $color = '#6B0000'): string
{
    return '<div style="text-align:center;margin:28px 0;">'
        . '<a href="' . $url . '" style="display:inline-block;background:linear-gradient(135deg,' . $color . ',#9B1C1C);color:white;text-decoration:none;padding:14px 36px;border-radius:10px;font-weight:700;font-size:14px;letter-spacing:.5px;box-shadow:0 4px 16px rgba(107,0,0,0.35);">' . $label . '</a>'
        . '<br><span style="font-size:10px;color:#b0b9c6;margin-top:10px;display:block;">Or copy this link: <span style="font-family:monospace;color:#6b7a8d;">' . $url . '</span></span>'
        . '</div>';
}

function qa_email_box(string $content, string $bg = '#e8f0fb', string $border = '#6B0000'): string
{
    return '<div style="background:' . $bg . ';border-left:3px solid ' . $border . ';border-radius:0 10px 10px 0;padding:16px 20px;margin:20px 0;font-size:14px;color:#1e2a3a;line-height:1.7;">' . $content . '</div>';
}

function qa_email_otp(string $otp, int $minutes = 10): string
{
    $digits_html = '';
    for ($i = 0; $i < strlen($otp); $i++) {
        $digits_html .= '<span style="'
            . 'display:inline-block;'
            . 'background:#3a0000;'
            . 'border:1px solid rgba(201,168,76,0.45);'
            . 'border-radius:8px;'
            . 'width:44px;height:54px;'
            . 'line-height:54px;'
            . 'text-align:center;'
            . 'font-size:28px;'
            . 'font-weight:800;'
            . 'color:#e8c96e;'
            . 'font-family:monospace;'
            . 'margin:0 3px;'
            . '">' . $otp[$i] . '</span>';
    }

    return '<div style="text-align:center;margin:28px 0;">'
        . '<div style="background:linear-gradient(160deg,#3a0000,#6B0000,#1a0a14);border-radius:16px;padding:28px 24px;display:inline-block;box-shadow:0 4px 24px rgba(0,0,0,0.45);">'
        .   '<div style="font-size:10px;color:rgba(255,255,255,0.45);letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;">Your Verification Code</div>'
        .   '<div style="white-space:nowrap;">' . $digits_html . '</div>'
        .   '<div style="margin-top:14px;font-size:11px;color:rgba(255,255,255,0.4);letter-spacing:0.5px;">Expires in <strong style="color:#c9a84c;">' . $minutes . ' minutes</strong> &mdash; Do not share this code</div>'
        . '</div>'
        . '</div>';
}

function qa_email_info_row(string $label, string $value, string $value_color = '#1e2a3a'): string
{
    return '<tr>'
        . '<td style="padding:8px 0;color:#8a94a6;font-size:13px;width:40%;vertical-align:top;">' . $label . '</td>'
        . '<td style="padding:8px 0;color:' . $value_color . ';font-size:13px;font-weight:600;vertical-align:top;">' . $value . '</td>'
        . '</tr>';
}

function qa_email_info_table(string $rows_html): string
{
    return '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fc;border-radius:10px;padding:16px 20px;margin:20px 0;border:1px solid #e8edf5;">'
        . '<tbody>' . $rows_html . '</tbody>'
        . '</table>';
}
