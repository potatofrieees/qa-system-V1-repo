<?php
/**
 * User Sidebar
 * USAGE: <?php include 'sidebar.php'; ?> placed AFTER <body> tag
 */
$unread = 0;
if (isset($conn) && isset($_SESSION['user_id'])) {
    $sid_uid = (int)$_SESSION['user_id'];
    $sid_r   = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$sid_uid AND is_read=0");
    if ($sid_r) $unread = (int)$sid_r->fetch_assoc()['c'];
}

$sid_name     = htmlspecialchars($_SESSION['name'] ?? 'User');
$sid_role_lbl = htmlspecialchars($_SESSION['role_label'] ?? '');
$sid_name_raw = $_SESSION['name'] ?? 'U';
$sid_parts    = explode(' ', trim($sid_name_raw));
$sid_initials = strtoupper(substr($sid_parts[0], 0, 1) . (isset($sid_parts[1]) ? substr($sid_parts[1], 0, 1) : ''));

function user_nav($href, $svg, $label, $key) {
    global $active_nav;
    $cls = ($active_nav === $key) ? 'nav-link active' : 'nav-link';
    echo "<a href='{$href}' class='{$cls}'>{$svg} <span>{$label}</span></a>";
}
function user_nav_badge($href, $svg, $label, $key, $badge) {
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
            <span>QA Portal</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Menu</div>
        <?php user_nav('dashboard.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>',
            'Dashboard', 'dashboard'); ?>
        <?php user_nav('my_documents.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>',
            'My Documents', 'documents'); ?>
        <?php user_nav('upload.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>',
            'Upload Document', 'upload'); ?>
        <?php user_nav('my_proposals.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/></svg>',
            'My Proposals', 'my_proposals'); ?>
        <?php user_nav('appointments.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>',
            'Appointments', 'appointments'); ?>
        <?php user_nav_badge('notifications.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>',
            'Notifications', 'notifications', $unread); ?>
        <?php user_nav('profile.php',
            '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg>',
            'My Profile', 'profile'); ?>
    </nav>

    <div class="sidebar-user">
        <a href="profile.php" style="text-decoration:none;" title="My Profile">
            <div class="user-avatar"><?= $sid_initials ?></div>
        </a>
        <div class="user-info">
            <div class="user-name"><?= $sid_name ?></div>
            <div class="user-role"><?= $sid_role_lbl ?></div>
        </div>
        <a href="../logout.php" class="btn-logout" title="Logout">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
        </a>
    </div>
</aside>
