<?php
/**
 * Admin Sidebar
 * USAGE: <?php include 'sidebar.php'; ?> placed AFTER <body> tag
 * Before including, set: $active_nav and ensure $conn + $_SESSION are available
 */
$unread = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $sid_uid = (int)$_SESSION['user_id'];
    $sid_r   = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$sid_uid AND is_read=0");
    if ($sid_r) $unread = (int)$sid_r->fetch_assoc()['c'];
}

$sid_name     = htmlspecialchars($_SESSION['name'] ?? 'User');
$sid_role_lbl = htmlspecialchars($_SESSION['role_label'] ?? '');

// Build initials from name
$sid_name_raw = $_SESSION['name'] ?? 'U';
$sid_parts    = explode(' ', trim($sid_name_raw));
$sid_initials = strtoupper(substr($sid_parts[0], 0, 1) . (isset($sid_parts[1]) ? substr($sid_parts[1], 0, 1) : ''));

function admin_nav($href, $svg, $label, $key) {
    global $active_nav;
    $cls = ($active_nav === $key) ? 'nav-link active' : 'nav-link';
    echo "<a href='{$href}' class='{$cls}'>{$svg} <span>{$label}</span></a>";
}
function admin_nav_badge($href, $svg, $label, $key, $badge) {
    global $active_nav;
    $cls = ($active_nav === $key) ? 'nav-link active' : 'nav-link';
    $b   = $badge > 0 ? "<span class='nav-badge'>{$badge}</span>" : '';
    echo "<a href='{$href}' class='{$cls}'>{$svg} <span>{$label}</span>{$b}</a>";
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon">
            <img src="../images/UEP_logo.png" alt="UEP Logo" style="width:100%;height:100%;object-fit:contain;display:block;" onerror="this.style.display='none'">
        </div>
        <div>
            <h1>QAIAO</h1>
            <span>QA Admin Panel</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <?php admin_nav('dashboard.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>',
            'Dashboard', 'dashboard'); ?>
        <?php admin_nav('documents.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>',
            'Documents', 'documents'); ?>
        <?php
        // Get pending reviews badge count
        $pending_queue = 0;
        if (isset($conn)) {
            $pq = $conn->query("SELECT COUNT(*) c FROM documents WHERE status IN ('submitted','under_review') AND deleted_at IS NULL");
            if ($pq) $pending_queue = (int)$pq->fetch_assoc()['c'];
        }
        ?>
        <?php admin_nav_badge('reviews_queue.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm9.707 5.707a1 1 0 00-1.414-1.414L9 12.586l-1.293-1.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>',
            'Reviews Queue', 'reviews_queue', $pending_queue); ?>
        <?php admin_nav('reviews.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>',
            'Review History', 'reviews'); ?>

        <div class="nav-section-label">Management</div>
        <?php
        $access_requests = 0;
        if (isset($conn)) {
            $ar = $conn->query("SELECT COUNT(*) c FROM notifications WHERE type='account_access_request' AND is_read=0 AND user_id=" . (int)$_SESSION['user_id']);
            if ($ar) $access_requests = (int)$ar->fetch_assoc()['c'];
        }
        ?>
        <?php admin_nav_badge('users.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>',
            'Users', 'users', $access_requests); ?>
        <?php admin_nav('departments.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"/></svg>',
            'Colleges & Programs', 'departments'); ?>
        <?php admin_nav('reports.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 000 2v8a2 2 0 002 2h2.586l-1.293 1.293a1 1 0 101.414 1.414L10 15.414l2.293 2.293a1 1 0 001.414-1.414L12.414 15H15a2 2 0 002-2V5a1 1 0 100-2H3zm11 4a1 1 0 10-2 0v4a1 1 0 102 0V7zm-3 1a1 1 0 10-2 0v3a1 1 0 102 0V8zM8 9a1 1 0 00-2 0v2a1 1 0 102 0V9z" clip-rule="evenodd"/></svg>',
            'Reports', 'reports'); ?>
        <?php admin_nav('progress.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg>',
            'Progress Tracking', 'progress'); ?>
        <?php admin_nav('announcements.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M18 3a1 1 0 00-1.447-.894L8.763 6H5a3 3 0 000 6h.28l1.771 5.316A1 1 0 008 18h1a1 1 0 001-1v-4.382l6.553 3.276A1 1 0 0018 15V3z"/></svg>',
            'Announcements', 'announcements'); ?>
        <?php admin_nav('proposals.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>',
            'Proposals', 'proposals'); ?>
        <?php admin_nav('schedule.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>',
            'Scheduling', 'schedule'); ?>

        <div class="nav-section-label">System</div>
        <?php admin_nav_badge('notifications.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>',
            'Notifications', 'notifications', $unread); ?>
        <?php admin_nav('audit_logs.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5zm3 4a1 1 0 000 2h.01a1 1 0 100-2H7zm3 0a1 1 0 000 2h3a1 1 0 100-2h-3zm-3 4a1 1 0 100 2h.01a1 1 0 100-2H7zm3 0a1 1 0 100 2h3a1 1 0 100-2h-3z" clip-rule="evenodd"/></svg>',
            'Audit Logs', 'audit'); ?>
        <?php admin_nav('settings.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>',
            'Settings', 'settings'); ?>
        <?php admin_nav('profile.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>',
            'My Profile', 'profile'); ?>
    </nav>

    <div class="sidebar-user">
        <a href="profile.php" style="text-decoration:none;flex-shrink:0;" title="My Profile">
            <div class="user-avatar"><?= $sid_initials ?></div>
        </a>
        <div class="user-info">
            <a href="profile.php" style="text-decoration:none;color:inherit;">
                <div class="user-name"><?= $sid_name ?></div>
            </a>
            <div class="user-role"><?= $sid_role_lbl ?></div>
        </div>
        <a href="../logout.php" class="btn-logout" title="Logout">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
        </a>
    </div>
</aside>
