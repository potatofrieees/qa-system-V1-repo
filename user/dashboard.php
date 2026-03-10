<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login();

$active_nav = 'dashboard';
$uid        = (int)$_SESSION['user_id'];

$my_total    = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE uploaded_by=$uid AND deleted_at IS NULL")->fetch_assoc()['c'];
$my_pending  = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE uploaded_by=$uid AND status IN ('submitted','under_review') AND deleted_at IS NULL")->fetch_assoc()['c'];
$my_approved = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE uploaded_by=$uid AND status='approved' AND deleted_at IS NULL")->fetch_assoc()['c'];
$my_revision = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE uploaded_by=$uid AND status='revision_requested' AND deleted_at IS NULL")->fetch_assoc()['c'];

$recent = $conn->query("
    SELECT d.id, d.title, d.status, d.deadline, d.updated_at, p.program_name
    FROM documents d
    LEFT JOIN programs p ON p.id = d.program_id
    WHERE d.uploaded_by=$uid AND d.deleted_at IS NULL
    ORDER BY d.updated_at DESC LIMIT 6
");

$notifs = $conn->query("SELECT * FROM notifications WHERE user_id=$uid AND is_read=0 ORDER BY created_at DESC LIMIT 5");
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];

$upcoming = $conn->query("
    SELECT title, deadline, status FROM documents
    WHERE uploaded_by=$uid AND deadline IS NOT NULL AND deadline >= CURDATE()
      AND status NOT IN ('approved','archived') AND deleted_at IS NULL
    ORDER BY deadline ASC LIMIT 5
");

$first_name = htmlspecialchars(explode(' ', $_SESSION['name'] ?? 'User')[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Dashboard — QA Portal</title>
    <?php include 'head.php'; ?>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">My Dashboard</div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if ($notif_count > 0): ?><span class="notif-badge"><?= $notif_count ?></span><?php endif; ?>
            </a>
            <a href="upload.php" class="btn btn-primary btn-sm">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                Upload
            </a>
        </div>
    </div>

    <div class="page-body">
        <div class="page-header">
            <div>
                <h1 class="page-heading">Hello, <?= $first_name ?>!</h1>
                <p class="page-subheading">Manage your accreditation documents below.</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $my_total ?></div>
                <div class="stat-label">Total Documents</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $my_pending ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $my_approved ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $my_revision ?></div>
                <div class="stat-label">Need Revision</div>
            </div>
        </div>

        <div class="two-col-layout">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Documents</span>
                    <a href="my_documents.php" class="btn btn-ghost btn-sm">View All</a>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Title</th><th>Program</th><th>Status</th><th>Deadline</th><th></th></tr></thead>
                        <tbody>
                        <?php if ($recent->num_rows === 0): ?>
                        <tr><td colspan="5"><div class="empty-state"><p>No documents yet. <a href="upload.php" style="color:var(--primary-light);">Upload one!</a></p></div></td></tr>
                        <?php else: while ($d = $recent->fetch_assoc()):
                            $days_left = $d['deadline'] ? ceil((strtotime($d['deadline']) - time()) / 86400) : null;
                            $warn = $days_left !== null && $days_left <= 7 && $days_left >= 0;
                        ?>
                        <tr>
                            <td style="font-weight:500;font-size:.875rem;"><?= htmlspecialchars(mb_substr($d['title'], 0, 45)) ?></td>
                            <td class="text-sm text-muted"><?= htmlspecialchars($d['program_name'] ?? '—') ?></td>
                            <td><span class="badge badge-<?= htmlspecialchars($d['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_',' ',$d['status']))) ?></span></td>
                            <td class="text-sm" style="<?= $warn ? 'color:var(--status-revision);font-weight:600;' : 'color:var(--muted)' ?>">
                                <?= $d['deadline'] ? date('M d', strtotime($d['deadline'])) . ($warn ? " ({$days_left}d)" : '') : '—' ?>
                            </td>
                            <td><a href="my_documents.php?highlight=<?= $d['id'] ?>" class="btn btn-ghost btn-sm">View</a></td>
                        </tr>
                        <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div style="display:flex;flex-direction:column;gap:16px;">
                <div class="card">
                    <div class="card-header">
                        <span class="card-title">Notifications</span>
                        <?php if ($notif_count > 0): ?><span class="badge badge-submitted"><?= $notif_count ?> new</span><?php endif; ?>
                    </div>
                    <div style="max-height:240px;overflow-y:auto;">
                    <?php if ($notifs->num_rows === 0): ?>
                        <div class="empty-state" style="padding:24px;">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                            <p>No new notifications</p>
                        </div>
                    <?php else: while ($notif = $notifs->fetch_assoc()): ?>
                    <div style="padding:12px 16px;border-bottom:1px solid var(--border);">
                        <div style="font-size:.82rem;font-weight:500;"><?= htmlspecialchars($notif['title'] ?? '') ?></div>
                        <div style="font-size:.75rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars(mb_substr($notif['message'], 0, 80)) ?></div>
                    </div>
                    <?php endwhile; endif; ?>
                    </div>
                    <?php if ($notif_count > 0): ?>
                    <div style="padding:10px 16px;border-top:1px solid var(--border);">
                        <a href="notifications.php" class="btn btn-ghost btn-sm" style="width:100%;justify-content:center;">View All</a>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card">
                    <div class="card-header"><span class="card-title">Upcoming Deadlines</span></div>
                    <div style="padding:12px 16px;">
                    <?php $has = false; while ($ud = $upcoming->fetch_assoc()): $has = true;
                        $dl = ceil((strtotime($ud['deadline']) - time()) / 86400);
                        $dc = $dl <= 3 ? 'var(--status-rejected)' : ($dl <= 7 ? 'var(--accent)' : 'var(--status-approved)');
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);">
                        <span style="font-size:.82rem;font-weight:500;"><?= htmlspecialchars(mb_substr($ud['title'], 0, 35)) ?></span>
                        <span style="font-size:.75rem;color:<?= $dc ?>;font-weight:700;"><?= $dl ?>d</span>
                    </div>
                    <?php endwhile; if (!$has): ?>
                    <p class="text-sm text-muted">No upcoming deadlines.</p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
