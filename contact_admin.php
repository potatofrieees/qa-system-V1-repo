<?php
/**
 * contact_admin.php
 * Called via AJAX (POST) from the login page when a deactivated/suspended user
 * clicks "Contact System Administrator".
 */
ob_start(); // Capture any accidental output (warnings, notices, etc.)
session_start();
include 'database/db_connect.php';
require_once 'mail/emails.php';

// Discard any output so far (PHP notices from db_connect or emails.php)
ob_clean();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['ok'=>false,'msg'=>'Method not allowed.']);
    exit;
}

$email  = trim($_POST['email']  ?? '');
$reason = trim($_POST['reason'] ?? '');  // 'inactive' | 'suspended'
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';

// Basic validation
if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    ob_end_clean();
    echo json_encode(['ok'=>false,'msg'=>'Invalid request.']);
    exit;
}

// Look up user
$stmt = $conn->prepare("SELECT id, name, status FROM users WHERE email=? AND deleted_at IS NULL LIMIT 1");
if (!$stmt) {
    ob_end_clean();
    echo json_encode(['ok'=>true,'msg'=>'Your request has been sent.']);
    exit;
}
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['status'] === 'active') {
    ob_end_clean();
    echo json_encode(['ok'=>true,'msg'=>'Your request has been sent. An administrator will contact you soon.']);
    exit;
}

$uid     = (int)$user['id'];
$uname   = $user['name'];
$ustatus = $user['status'];
$ip_e    = $conn->real_escape_string($ip);
$name_e  = $conn->real_escape_string($uname);
$email_e = $conn->real_escape_string($email);

// Rate-limit: max 2 requests per 15 minutes per user
$rate_check = $conn->query("SELECT COUNT(*) c FROM audit_logs
    WHERE entity_type='users' AND entity_id=$uid AND action='CONTACT_ADMIN_REQUEST'
    AND created_at >= NOW() - INTERVAL 15 MINUTE");
if ($rate_check) {
    if ((int)$rate_check->fetch_assoc()['c'] >= 2) {
        ob_end_clean();
        echo json_encode(['ok'=>true,'msg'=>'Your request has been sent. An administrator will contact you soon.']);
        exit;
    }
}

// Audit the contact request
$conn->query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address)
    VALUES (0, 'CONTACT_ADMIN_REQUEST', 'users', $uid, 'Account access request from $name_e ($email_e) — status: $ustatus', '$ip_e')");

$label       = ($ustatus === 'suspended') ? 'suspended' : 'deactivated';
$notif_msg   = $conn->real_escape_string("User \"$uname\" ($email) has been $label and is requesting account access restoration. IP: $ip");
$notif_title = $conn->real_escape_string("Account Access Request — $uname");
$link_e      = $conn->real_escape_string("users.php?search=" . urlencode($email));

// Try to add the new ENUM value if not already present (silently ignore if it fails)
@$conn->query("ALTER TABLE notifications MODIFY COLUMN type ENUM('document_submitted','review_decision','revision_requested','deadline_reminder','assignment','system','general','account_access_request') DEFAULT 'general'");

// Notify all QA directors
$directors = $conn->query("SELECT u.id, u.email, u.name FROM users u
    JOIN roles r ON r.id=u.role_id
    WHERE r.role_key='qa_director' AND u.deleted_at IS NULL AND u.status='active'");

$notified = 0;
if ($directors && $directors->num_rows > 0) {
    while ($dir = $directors->fetch_assoc()) {
        $did = (int)$dir['id'];

        // Insert notification — try new type first, fall back to 'general'
        $ins = @$conn->query("INSERT INTO notifications (user_id, type, title, message, link, priority, created_at)
            VALUES ($did, 'account_access_request', '$notif_title', '$notif_msg', '$link_e', 'high', NOW())");
        if (!$ins) {
            @$conn->query("INSERT INTO notifications (user_id, type, title, message, link, priority, created_at)
                VALUES ($did, 'general', '$notif_title', '$notif_msg', '$link_e', 'high', NOW())");
        }

        // Email (silently ignore failures)
        @mail_account_access_request($dir['email'], $dir['name'], $uname, $email, $ustatus, $ip);
        $notified++;
    }
}

ob_end_clean();
echo json_encode(['ok'=>true,'msg'=>'Your request has been sent. An administrator will review your account shortly.']);
