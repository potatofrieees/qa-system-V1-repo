<?php
/**
 * QAIAO Portal — All Email Senders
 * University of Eastern Pangasinan
 * Quality Assurance, Internationalization and Accreditation Office
 *
 * One function per email type. Each returns true/false.
 * Include this file wherever you need to send mail.
 */

require_once __DIR__ . '/mailer.php';

/* ═══════════════════════════════════════════════════════════
   1. LOGIN OTP (2FA)
═══════════════════════════════════════════════════════════ */
function mail_otp(string $email, string $name, string $otp): bool
{
    $subject = 'Login Verification Code — QAIAO Portal';
    $minutes = OTP_EXPIRY_MINUTES;

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 24px;'>"
        .   "You requested to sign in to the <strong>QAIAO Portal</strong>. Use the verification code below to complete your login. "
        .   "This code is valid for a single use only."
        . "</p>"
        . qa_email_otp($otp, $minutes)
        . qa_email_box(
            "<strong style='color:#6B0000;'>&#9888; Security Notice</strong><br>"
            . "Never share this code with anyone — not even QAIAO staff. "
            . "If you did not attempt to log in, please contact your system administrator immediately.",
            '#fff8f8', '#6B0000'
          );

    $html = qa_email_template('Login Verification Code', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   2. FORGOT PASSWORD — reset link
═══════════════════════════════════════════════════════════ */
function mail_password_reset(string $email, string $name, string $token): bool
{
    $url     = APP_URL . '/forgot_password.php?token=' . urlencode($token);
    $minutes = RESET_TOKEN_EXPIRY_MINUTES;
    $subject = 'Password Reset Request — QAIAO Portal';

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "We received a request to reset the password for your QAIAO Portal account. "
        .   "Click the button below to create a new password. This link expires in <strong>{$minutes} minutes</strong>."
        . "</p>"
        . qa_email_btn($url, 'Reset My Password')
        . qa_email_box(
            "<strong>&#9888; Did not request this?</strong><br>"
            . "If you did not request a password reset, you can safely ignore this email. "
            . "Your password will not change and your account remains secure.",
            '#fffbeb', '#d97706'
          );

    $html = qa_email_template('Password Reset Request', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   3. ADMIN-RESET PASSWORD NOTIFICATION
═══════════════════════════════════════════════════════════ */
function mail_admin_reset_password(string $email, string $name, string $new_password): bool
{
    $url     = APP_URL . '/login.php';
    $subject = 'Your Password Has Been Reset — QAIAO Portal';

    $rows = qa_email_info_row('Email Address', htmlspecialchars($email))
          . qa_email_info_row('Temporary Password', "<span style='font-family:monospace;font-size:16px;letter-spacing:2px;color:#6B0000;'>{$new_password}</span>", '#6B0000');

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "A QAIAO Portal administrator has reset your account password. Below are your new login credentials."
        . "</p>"
        . qa_email_info_table($rows)
        . "<p style='color:#6b7280;font-size:13px;text-align:center;margin:0 0 20px;'>Please log in and change your password immediately from your profile settings.</p>"
        . qa_email_btn($url, 'Log In to QAIAO Portal')
        . qa_email_box(
            "<strong style='color:#059669;'>&#128274; Security Reminder</strong><br>"
            . "For your account security, do not share your password with anyone. "
            . "You can change it anytime from your profile after logging in.",
            '#f0fdf4', '#059669'
          );

    $html = qa_email_template('Password Reset by Administrator', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   4. WELCOME — new account created by admin
═══════════════════════════════════════════════════════════ */
function mail_welcome(string $email, string $name, string $role, string $temp_password): bool
{
    $url     = APP_URL . '/login.php';
    $subject = 'Welcome to the QAIAO Portal — University of Eastern Pangasinan';

    $rows = qa_email_info_row('Full Name', htmlspecialchars($name))
          . qa_email_info_row('Email Address', htmlspecialchars($email))
          . qa_email_info_row('Role', htmlspecialchars($role))
          . qa_email_info_row('Temporary Password', "<span style='font-family:monospace;font-size:15px;letter-spacing:2px;color:#6B0000;'>{$temp_password}</span>", '#6B0000');

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "Welcome to the <strong>Quality Assurance, Internationalization and Accreditation Office Portal</strong> "
        .   "of the University of Eastern Pangasinan. Your account has been created and is ready to use."
        . "</p>"
        . qa_email_info_table($rows)
        . "<p style='color:#6b7280;font-size:13px;text-align:center;margin:0 0 20px;'>Please log in and change your password as soon as possible.</p>"
        . qa_email_btn($url, 'Log In to QAIAO Portal')
        . qa_email_box(
            "<strong style='color:#059669;'>&#128274; Keep your credentials safe.</strong><br>"
            . "Do not share your password with anyone. "
            . "If you experience any issues accessing your account, please contact your system administrator.",
            '#f0fdf4', '#059669'
          );

    $html = qa_email_template('Welcome to the QAIAO Portal', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   5. DOCUMENT REVIEW DECISION (to uploader)
═══════════════════════════════════════════════════════════ */
function mail_review_decision(string $email, string $name, string $doc_title, string $decision, string $comments = ''): bool
{
    $url = APP_URL . '/user/my_documents.php';

    $labels = [
        'approved'           => 'Approved',
        'revision_requested' => 'Revision Requested',
        'rejected'           => 'Rejected',
    ];
    $icons = [
        'approved'           => '&#10003;',
        'revision_requested' => '&#8617;',
        'rejected'           => '&#10007;',
    ];
    $colors = [
        'approved'           => '#059669',
        'revision_requested' => '#d97706',
        'rejected'           => '#dc2626',
    ];
    $bgs = [
        'approved'           => '#f0fdf4',
        'revision_requested' => '#fffbeb',
        'rejected'           => '#fff8f8',
    ];
    $action_notes = [
        'approved'           => 'No further action is required for this document. Thank you for your submission.',
        'revision_requested' => 'Please review the comments below, make the necessary revisions, and resubmit your document at your earliest convenience.',
        'rejected'           => 'Please contact the QAIAO team if you need further clarification regarding this decision.',
    ];

    $label       = $labels[$decision]      ?? ucwords(str_replace('_', ' ', $decision));
    $icon        = $icons[$decision]       ?? '&#9679;';
    $color       = $colors[$decision]      ?? '#2563a8';
    $bg          = $bgs[$decision]         ?? '#f8f9fc';
    $action_note = $action_notes[$decision] ?? '';
    $subject     = "Document {$label} — QAIAO Portal";

    $rows = qa_email_info_row('Document', htmlspecialchars($doc_title))
          . qa_email_info_row('Decision', "<span style='color:{$color};font-weight:700;'>{$icon} {$label}</span>", $color);

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "The QAIAO team has reviewed your submitted document. Here is the review decision:"
        . "</p>"
        . qa_email_info_table($rows);

    if ($comments) {
        $body .= qa_email_box(
            "<strong style='color:#d97706;'>&#128203; Reviewer Comments</strong><br><br>"
            . nl2br(htmlspecialchars($comments)),
            '#fffbeb', '#d97706'
        );
    }

    $body .= "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:16px 0 20px;'>{$action_note}</p>"
        . qa_email_btn($url, 'View My Documents');

    $html = qa_email_template('Document Review Decision', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   6. DEADLINE SET (to uploader)
═══════════════════════════════════════════════════════════ */
function mail_deadline_set(string $email, string $name, string $doc_title, string $deadline_date): bool
{
    $url     = APP_URL . '/user/my_documents.php';
    $subject = 'Submission Deadline Set — QAIAO Portal';
    $dl_fmt  = date('F d, Y', strtotime($deadline_date));
    $days    = (int)ceil((strtotime($deadline_date) - time()) / 86400);

    if ($days <= 3) {
        $urgency_text  = "&#128680; Urgent — this deadline is very soon!";
        $urgency_color = '#dc2626';
        $urgency_bg    = '#fff8f8';
    } elseif ($days <= 7) {
        $urgency_text  = "&#9888; Only {$days} days remaining.";
        $urgency_color = '#d97706';
        $urgency_bg    = '#fffbeb';
    } else {
        $urgency_text  = "&#128197; {$days} days remaining.";
        $urgency_color = '#059669';
        $urgency_bg    = '#f0fdf4';
    }

    $rows = qa_email_info_row('Document', htmlspecialchars($doc_title))
          . qa_email_info_row('Deadline', "<span style='color:#d97706;font-weight:700;'>{$dl_fmt}</span>", '#d97706')
          . qa_email_info_row('Time Remaining', "<span style='color:{$urgency_color};font-weight:600;'>{$urgency_text}</span>", $urgency_color);

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "The QAIAO team has set a submission deadline for one of your documents. "
        .   "Please ensure your document is submitted on or before the deadline."
        . "</p>"
        . qa_email_info_table($rows)
        . qa_email_btn($url, 'View My Documents');

    $html = qa_email_template('Submission Deadline Set', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   7. DOCUMENT SUBMITTED (to QA staff)
═══════════════════════════════════════════════════════════ */
function mail_document_submitted(string $email, string $qa_name, string $doc_title, string $uploader_name, string $program = ''): bool
{
    $url     = APP_URL . '/admin/documents.php';
    $subject = 'New Document Submitted for Review — QAIAO Portal';

    $rows = qa_email_info_row('Document', htmlspecialchars($doc_title))
          . qa_email_info_row('Submitted By', htmlspecialchars($uploader_name))
          . ($program ? qa_email_info_row('Program', htmlspecialchars($program)) : '')
          . qa_email_info_row('Submitted At', date('F d, Y \a\t h:i A'));

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$qa_name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "A new document has been submitted and is awaiting your review on the QAIAO Portal."
        . "</p>"
        . qa_email_info_table($rows)
        . qa_email_btn($url, 'Review Document');

    $html = qa_email_template('New Document for Review', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   8. DOCUMENT RESUBMITTED AFTER REVISION (to QA staff)
═══════════════════════════════════════════════════════════ */
function mail_document_resubmitted(string $email, string $qa_name, string $doc_title, string $uploader_name): bool
{
    $url     = APP_URL . '/admin/documents.php';
    $subject = 'Revised Document Resubmitted — QAIAO Portal';

    $rows = qa_email_info_row('Document', htmlspecialchars($doc_title))
          . qa_email_info_row('Resubmitted By', htmlspecialchars($uploader_name))
          . qa_email_info_row('Resubmitted At', date('F d, Y \a\t h:i A'));

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$qa_name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "A revised document has been resubmitted following a revision request and is ready for your review."
        . "</p>"
        . qa_email_info_table($rows)
        . qa_email_box(
            "<strong style='color:#d97706;'>&#128260; This is a resubmission</strong><br>"
            . "This document was previously returned for revisions. Please check if all requested changes have been addressed.",
            '#fffbeb', '#d97706'
          )
        . qa_email_btn($url, 'Review Revised Document');

    $html = qa_email_template('Revised Document Resubmitted', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   9. ACCOUNT STATUS CHANGED (to user)
═══════════════════════════════════════════════════════════ */
function mail_account_status(string $email, string $name, string $status): bool
{
    $subject  = 'Account Status Update — QAIAO Portal';
    $is_active = $status === 'active';
    $color    = $is_active ? '#059669' : '#dc2626';
    $bg       = $is_active ? '#f0fdf4' : '#fff8f8';
    $label    = ucfirst($status);
    $icon     = $is_active ? '&#9989;' : '&#128683;';
    $message  = $is_active
        ? 'Your account has been reactivated. You can now log in to the QAIAO Portal and resume your work.'
        : 'Your account has been deactivated or suspended. If you believe this is an error, please contact the system administrator.';

    $rows = qa_email_info_row('Account', htmlspecialchars($email))
          . qa_email_info_row('New Status', "<span style='color:{$color};font-weight:700;'>{$icon} {$label}</span>", $color)
          . qa_email_info_row('Updated At', date('F d, Y \a\t h:i A'));

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "Your QAIAO Portal account status has been updated by an administrator."
        . "</p>"
        . qa_email_info_table($rows)
        . qa_email_box($message, $bg, $color);

    $html = qa_email_template('Account Status Update', $body);
    return qa_send_mail($email, $subject, $html);
}

/* ═══════════════════════════════════════════════════════════
   10. ACCOUNT ACCESS REQUEST (to QA Director — from login page)
═══════════════════════════════════════════════════════════ */
function mail_account_access_request(string $dir_email, string $dir_name, string $user_name, string $user_email, string $status, string $ip = ''): bool
{
    $url     = APP_URL . '/admin/users.php?search=' . urlencode($user_email);
    $subject = 'Account Access Request — QAIAO Portal';
    $label   = ucfirst($status === 'suspended' ? 'Suspended' : 'Deactivated');

    $rows = qa_email_info_row('User Name', htmlspecialchars($user_name))
          . qa_email_info_row('Email', htmlspecialchars($user_email))
          . qa_email_info_row('Account Status', "<span style='color:#d97706;font-weight:700;'>&#9888; {$label}</span>", '#d97706')
          . ($ip ? qa_email_info_row('IP Address', htmlspecialchars($ip)) : '')
          . qa_email_info_row('Request Time', date('F d, Y \a\t h:i A'));

    $body = "<p style='color:#374151;font-size:15px;line-height:1.8;margin:0 0 6px;'>Hi <strong style='color:#1a1a2e;'>{$dir_name}</strong>,</p>"
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:0 0 20px;'>"
        .   "A user with a <strong style='color:#d97706;'>{$label}</strong> account has submitted an access restoration request via the QAIAO Portal login page."
        . "</p>"
        . qa_email_info_table($rows)
        . "<p style='color:#6b7280;font-size:14px;line-height:1.8;margin:16px 0 20px;'>"
        .   "Please review this user's account in the User Management panel and determine whether to reactivate or maintain the current status."
        . "</p>"
        . qa_email_btn($url, 'Review User Account')
        . qa_email_box(
            "If you did not expect this request or believe it may be unauthorized, you can safely disregard this notification.",
            '#f8f9fc', '#94a3b8'
          );

    $html = qa_email_template('Account Access Request', $body);
    return qa_send_mail($dir_email, $subject, $html);
}
