<?php
/**
 * Mail Configuration — QAIAO Portal
 * University of Eastern Pangasinan
 * Quality Assurance, Internationalization and Accreditation Office
 *
 * Edit these settings to match your mail server.
 * SMTP is sent via PHP's fsockopen (no Composer / PHPMailer needed).
 *
 * For Gmail:
 *   SMTP_HOST = 'smtp.gmail.com'
 *   SMTP_PORT = 587
 *   SMTP_USER = 'youraddress@gmail.com'
 *   SMTP_PASS = '<App Password from Google Account Security>'
 *   SMTP_SECURE = 'tls'
 *
 * For local dev (MailHog / Mailtrap):
 *   SMTP_HOST = '127.0.0.1'
 *   SMTP_PORT = 1025
 *   SMTP_SECURE = 'none'
 *   SMTP_AUTH  = false
 */

define('MAIL_ENABLED',    true);           // Set false to disable all email sending
define('SMTP_HOST',       'smtp.gmail.com');
define('SMTP_PORT',       587);
define('SMTP_SECURE',     'tls');          // 'tls', 'ssl', or 'none'
define('SMTP_AUTH',       true);
define('SMTP_USER',       'pabloharold670@gmail.com');
define('SMTP_PASS',       'wrkptjnlxxaguxho');

define('MAIL_FROM',       'pabloharold670@gmail.com');
define('MAIL_FROM_NAME',  'QAIAO System');
define('APP_NAME',        'QAIAO System');
define('APP_URL',         'http://localhost/QA_SYSTEM_V35/qa_system');  // No trailing slash

// OTP settings
define('OTP_EXPIRY_MINUTES',    10);   // OTP valid for 10 minutes
define('OTP_DIGITS',             6);   // 6-digit OTP

// Password reset settings
define('RESET_TOKEN_EXPIRY_MINUTES', 60);  // Reset link valid for 60 minutes
