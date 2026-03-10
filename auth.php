<?php
// Include this at the top of every protected page
function require_login($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php"); exit();
    }
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: ../login.php"); exit();
    }
}

function require_login_root($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php"); exit();
    }
    if (!empty($allowed_roles) && !in_array($_SESSION['role'], $allowed_roles)) {
        header("Location: login.php"); exit();
    }
}

function is_admin_role($role = null) {
    $r = $role ?? $_SESSION['role'] ?? '';
    return in_array($r, ['qa_director', 'qa_staff']);
}

function is_qa_director() {
    return ($_SESSION['role'] ?? '') === 'qa_director';
}
?>
