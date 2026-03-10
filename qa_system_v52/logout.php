<?php
session_start();

// Log the logout event if user was logged in
if (isset($_SESSION['user_id'])) {
    include 'database/db_connect.php';
    $uid = (int)$_SESSION['user_id'];
    // Only log if user actually exists (guards against stale sessions or deleted accounts)
    $exists = $conn->query("SELECT id FROM users WHERE id=$uid LIMIT 1");
    if ($exists && $exists->num_rows > 0) {
        $ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
        $conn->query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address)
                      VALUES ($uid, 'LOGOUT', 'users', $uid, 'User logged out', '$ip')");
    }
}

// Destroy the session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: login.php');
exit;
?>
