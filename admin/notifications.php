<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);

$active_nav = 'notifications';
$uid        = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['mark_all_read'])) {
        $conn->query("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=$uid");
    }
    if (isset($_POST['clear_all'])) {
        $conn->query("DELETE FROM notifications WHERE user_id=$uid");
    }
    if (isset($_POST['mark_id']) && ctype_digit($_POST['mark_id'])) {
        $nid = (int)$_POST['mark_id'];
        $conn->query("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=$nid AND user_id=$uid");
        if (!empty($_POST['redirect_link'])) {
            $rlink = ltrim($_POST['redirect_link'], '/');
            if (!preg_match('/^https?:\/\//i', $rlink) && strpos($rlink, '..') === false) {
                header("Location: $rlink");
                exit;
            }
        }
    }
    if (isset($_POST['mark_unread_id']) && ctype_digit($_POST['mark_unread_id'])) {
        $nid = (int)$_POST['mark_unread_id'];
        $conn->query("UPDATE notifications SET is_read=0, read_at=NULL WHERE id=$nid AND user_id=$uid");
    }
    if (isset($_POST['delete_id']) && ctype_digit($_POST['delete_id'])) {
        $nid = (int)$_POST['delete_id'];
        $conn->query("DELETE FROM notifications WHERE id=$nid AND user_id=$uid");
    }
    $redir_filter = in_array($_GET['filter'] ?? '', ['all','unread']) ? ($_GET['filter'] ?? '') : '';
    $redir_type   = preg_match('/^[a-z_]+$/', $_GET['type'] ?? '') ? ($_GET['type'] ?? '') : '';
    $redir_pp     = in_array((int)($_GET['per_page'] ?? 0), [10,25,50]) ? (int)($_GET['per_page'] ?? 0) : 0;
    $redir = 'notifications.php';
    $redir_parts = [];
    if ($redir_filter) $redir_parts[] = 'filter='.$redir_filter;
    if ($redir_type)   $redir_parts[] = 'type='.$redir_type;
    if ($redir_pp)     $redir_parts[] = 'per_page='.$redir_pp;
    header("Location: $redir" . ($redir_parts ? '?'.implode('&', $redir_parts) : ''));
    exit;
}

// ── Filters ──────────────────────────────────────────────────
$filter   = in_array($_GET['filter'] ?? '', ['all','unread']) ? ($_GET['filter'] ?? 'all') : 'all';
$type_f   = preg_match('/^[a-z_]+$/', $_GET['type'] ?? '') ? ($_GET['type'] ?? '') : '';
$per_page = in_array((int)($_GET['per_page'] ?? 25), [10,25,50]) ? (int)($_GET['per_page'] ?? 25) : 25;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$where = "user_id=$uid";
if ($filter === 'unread') $where .= " AND is_read=0";
if ($type_f)              $where .= " AND type='" . $conn->real_escape_string($type_f) . "'";

$notifs_count_filtered = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE $where")->fetch_assoc()['c'];
$notifs      = $conn->query("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
$total_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid")->fetch_assoc()['c'];
$per_page = max(1, $per_page); // Safety: prevent division by zero
$total_pages = ($per_page > 0 && $notifs_count_filtered > 0) ? max(1, (int)ceil($notifs_count_filtered / $per_page)) : 1;

$type_icons = [
    'document_submitted'    => ['icon'=>'📄','label'=>'Submitted'],
    'review_decision'       => ['icon'=>'✅','label'=>'Review Decision'],
    'revision_requested'    => ['icon'=>'🔄','label'=>'Revision'],
    'deadline_reminder'     => ['icon'=>'⏰','label'=>'Deadline'],
    'assignment'            => ['icon'=>'👤','label'=>'Assignment'],
    'system'                => ['icon'=>'⚙️','label'=>'System'],
    'general'               => ['icon'=>'📢','label'=>'General'],
    'account_access_request'=> ['icon'=>'🔑','label'=>'Access Request'],
];
$priority_colors = [
    'low'    => '#6b7a8d',
    'normal' => '#1a3a5c',
    'high'   => '#d97706',
    'urgent' => '#dc2626',
];

// Build base URL for pagination links
function notif_url(array $extra = []): string {
    global $filter, $type_f, $per_page;
    $params = [];
    if ($filter && $filter !== 'all') $params[] = 'filter='.$filter;
    if ($type_f)   $params[] = 'type='.$type_f;
    if ($per_page !== 25) $params[] = 'per_page='.$per_page;
    foreach ($extra as $k => $v) {
        // Replace or add
        $params = array_values(array_filter($params, function($p) use ($k) { return strpos($p, $k.'=') !== 0; }));
        if ($v !== null) $params[] = $k.'='.$v;
    }
    return 'notifications.php' . ($params ? '?'.implode('&', $params) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Notifications — QA System</title>
    <?php include 'head.php'; ?>
    <style>
    .notif-row { padding:18px 24px; border-bottom:1px solid var(--border); display:flex; gap:14px; align-items:flex-start; transition:background .15s; }
    .notif-row:last-child { border-bottom:none; }
    .notif-row.unread { background:var(--primary-xlight); }
    .notif-row:hover  { background:#f0f4fa; }
    .notif-icon { font-size:1.3rem; flex-shrink:0; width:32px; text-align:center; margin-top:2px; }
    .notif-body { flex:1; min-width:0; }
    .notif-title { font-size:.875rem; font-weight:600; color:var(--text); margin-bottom:3px; }
    .notif-title.read { font-weight:400; }
    .notif-msg  { font-size:.82rem; color:var(--muted); line-height:1.5; margin-bottom:8px; }
    .notif-meta { display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .notif-dot  { width:9px; height:9px; border-radius:50%; background:var(--primary-light); flex-shrink:0; margin-top:6px; }
    .notif-actions { display:flex; gap:6px; margin-top:8px; }
    .filter-pills { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; }
    .pagination { display:flex; gap:6px; align-items:center; justify-content:center; padding:16px; }
    .per-page-select { display:flex; align-items:center; gap:8px; font-size:.82rem; color:var(--muted); }
    .per-page-select select { padding:4px 8px; border:1.5px solid var(--border); border-radius:6px; font-size:.82rem; color:var(--text); background:white; cursor:pointer; }
    .notif-toolbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:20px; }
    .notif-toolbar-actions { display:flex; gap:6px; flex-wrap:wrap; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Notifications</div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if ($notif_count > 0): ?><span class="notif-badge"><?= $notif_count ?></span><?php endif; ?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <div class="page-header">
            <div>
                
                <h1 class="page-heading">Notifications</h1>
                <p class="page-subheading"><?= $notif_count ?> unread &middot; <?= $total_count ?> total</p>
            </div>
        </div>

        <!-- Filter pills -->
        <div class="filter-pills">
            <a href="notifications.php<?= $per_page !== 25 ? '?per_page='.$per_page : '' ?>" class="btn <?= $filter==='all'&&!$type_f ? 'btn-primary':'btn-ghost' ?> btn-sm">All <?php if($total_count): ?>(<?=$total_count?>)<?php endif; ?></a>
            <a href="<?= notif_url(['filter'=>'unread','page'=>null]) ?>" class="btn <?= $filter==='unread'&&!$type_f ? 'btn-primary':'btn-ghost' ?> btn-sm">
                Unread <?php if($notif_count>0): ?><span style="background:rgba(255,255,255,.35);padding:0 7px;border-radius:10px;margin-left:2px;"><?=$notif_count?></span><?php endif;?>
            </a>
            <span style="width:1px;height:20px;background:var(--border);display:inline-block;margin:0 4px;"></span>
            <?php foreach ($type_icons as $tkey => $ti): ?>
            <a href="<?= notif_url(['type'=>$tkey,'page'=>null]) ?>"
               class="btn <?= $type_f===$tkey ? 'btn-primary':'btn-ghost' ?> btn-sm">
                <?= $ti['icon'] ?> <?= $ti['label'] ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- Toolbar: bulk actions + per-page -->
        <div class="notif-toolbar">
            <div class="notif-toolbar-actions">
                <?php if ($notif_count > 0): ?>
                <form method="POST" style="display:inline;">
                    <button type="submit" name="mark_all_read" value="1" class="btn btn-outline btn-sm">
                        <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                        Mark All Read
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($total_count > 0): ?>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Clear ALL notifications? This cannot be undone.')">
                    <button type="submit" name="clear_all" value="1" class="btn btn-ghost btn-sm" style="color:var(--status-rejected);">
                        <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                        Clear All
                    </button>
                </form>
                <?php endif; ?>
            </div>

            <!-- Per-page selector -->
            <div class="per-page-select">
                <span>Show:</span>
                <select onchange="window.location='<?= notif_url(['per_page'=>'__PP__','page'=>null]) ?>'.replace('__PP__', this.value)">
                    <?php foreach ([10, 25, 50] as $pp): ?>
                    <option value="<?= $pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>><?= $pp ?> per page</option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card" style="overflow:hidden;">
            <?php if ($notifs === false || $notifs->num_rows === 0): ?>
            <div class="empty-state" style="padding:72px 24px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:48px;height:48px;margin:0 auto 12px;display:block;color:var(--border);">
                    <path stroke-linecap="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <p style="color:var(--muted);">No notifications found.</p>
                <?php if ($filter === 'unread'): ?>
                <a href="notifications.php" class="btn btn-ghost btn-sm" style="margin-top:12px;">View All</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php while ($n = $notifs->fetch_assoc()):
                $ti    = $type_icons[$n['type']] ?? ['icon'=>'📢','label'=>'Notification'];
                $pc    = $priority_colors[$n['priority'] ?? 'normal'] ?? '#1a3a5c';
                $unread = !(bool)$n['is_read'];
            ?>
            <div class="notif-row <?= $unread ? 'unread' : '' ?>">
                <div class="notif-icon"><?= $ti['icon'] ?></div>
                <div class="notif-body">
                    <div class="notif-title <?= $unread ? '' : 'read' ?>"><?= htmlspecialchars($n['title'] ?? '') ?></div>
                    <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                    <div class="notif-meta">
                        <span style="font-size:.72rem;color:var(--muted);"><?= date('M d, Y · H:i', strtotime($n['created_at'])) ?></span>
                        <?php if (($n['priority'] ?? 'normal') !== 'normal'): ?>
                        <span style="font-size:.68rem;font-weight:700;color:<?= $pc ?>;text-transform:uppercase;letter-spacing:.5px;"><?= $n['priority'] ?></span>
                        <?php endif; ?>
                        <span style="font-size:.72rem;color:var(--muted);"><?= $ti['label'] ?></span>
                    </div>
                    <div class="notif-actions">
                        <?php if ($n['link']): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="mark_id" value="<?= $n['id'] ?>">
                            <input type="hidden" name="redirect_link" value="<?= htmlspecialchars($n['link']) ?>">
                            <button type="submit" class="btn btn-outline btn-sm">View →</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($unread): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="mark_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">Mark Read</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="mark_unread_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm">Mark Unread</button>
                        </form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Delete this notification?')">
                            <input type="hidden" name="delete_id" value="<?= $n['id'] ?>">
                            <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--status-rejected);">Delete</button>
                        </form>
                    </div>
                </div>
                <?php if ($unread): ?><div class="notif-dot"></div><?php endif; ?>
            </div>
            <?php endwhile; ?>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= notif_url(['page'=>$page-1]) ?>" class="btn btn-ghost btn-sm">← Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1, $page-2); $p <= min($total_pages, $page+2); $p++): ?>
            <a href="<?= notif_url(['page'=>$p]) ?>" class="btn <?= $p===$page ? 'btn-primary' : 'btn-ghost' ?> btn-sm"><?= $p ?></a>
            <?php endfor; ?>
            <?php if ($page < $total_pages): ?>
            <a href="<?= notif_url(['page'=>$page+1]) ?>" class="btn btn-ghost btn-sm">Next →</a>
            <?php endif; ?>
            <span style="font-size:.8rem;color:var(--muted);">Page <?= $page ?> of <?= $total_pages ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
// Handle mark as unread for individual items (add to POST handler at top too)
// This is a POST action we need to handle - add inline script redirect notice
?>
<script>
// Handle mark_unread_id via fetch for smoother UX
document.querySelectorAll('form').forEach(f => {
    const inp = f.querySelector('input[name="mark_unread_id"]');
    if (!inp) return;
    f.addEventListener('submit', async function(e) {
        e.preventDefault();
        const nid = inp.value;
        const fd = new FormData();
        fd.append('mark_unread_id', nid);
        await fetch('notifications.php', { method: 'POST', body: fd });
        location.reload();
    });
});
</script>
</body>
</html>
