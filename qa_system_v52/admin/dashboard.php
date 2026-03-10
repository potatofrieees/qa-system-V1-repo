<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);

$active_nav = 'dashboard';

// Stats
$total_docs   = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE deleted_at IS NULL")->fetch_assoc()['c'];
$pending_rev  = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status IN ('submitted','under_review') AND deleted_at IS NULL")->fetch_assoc()['c'];
$approved     = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='approved' AND deleted_at IS NULL")->fetch_assoc()['c'];
$revision_req = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE status='revision_requested' AND deleted_at IS NULL")->fetch_assoc()['c'];
$total_users  = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE deleted_at IS NULL")->fetch_assoc()['c'];
$total_progs  = (int)$conn->query("SELECT COUNT(*) c FROM programs WHERE status='active'")->fetch_assoc()['c'];

$recent_docs = $conn->query("
    SELECT d.id, d.title, d.status, d.created_at,
           p.program_name, u.name AS uploader
    FROM documents d
    LEFT JOIN programs p ON p.id = d.program_id
    LEFT JOIN users u ON u.id = d.uploaded_by
    WHERE d.deleted_at IS NULL
    ORDER BY d.updated_at DESC LIMIT 8
");

$status_data = [];
$sr = $conn->query("SELECT status, COUNT(*) c FROM documents WHERE deleted_at IS NULL GROUP BY status");
while ($row = $sr->fetch_assoc()) $status_data[$row['status']] = (int)$row['c'];

$overdue_docs = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE deadline < CURDATE() AND status NOT IN ('approved','archived','rejected') AND deleted_at IS NULL")->fetch_assoc()['c'];
$uid         = (int)$_SESSION['user_id'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
$first_name  = htmlspecialchars(explode(' ', $_SESSION['name'] ?? 'User')[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Dashboard — QA System</title>
    <?php include 'head.php'; ?>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Dashboard</div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn" title="Notifications">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if ($notif_count > 0): ?><span class="notif-badge"><?= $notif_count ?></span><?php endif; ?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <div class="page-header">
            <div>
                <h1 class="page-heading">Good day, <?= $first_name ?>!</h1>
                <p class="page-subheading">Here's an overview of the QA document system.</p>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card blue">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $total_docs ?></div>
                <div class="stat-label">Total Documents</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $pending_rev ?></div>
                <div class="stat-label">Pending Review</div>
            </div>
            <div class="stat-card green">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $approved ?></div>
                <div class="stat-label">Approved</div>
            </div>
            <div class="stat-card red">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></div>
                <div class="stat-value"><?= $revision_req ?></div>
                <div class="stat-label">Needs Revision</div>
            </div>
            <div class="stat-card purple">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg></div>
                <div class="stat-value"><?= $total_users ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3z"/></svg></div>
                <div class="stat-value"><?= $total_progs ?></div>
                <div class="stat-label">Active Programs</div>
            </div>
        </div>

        <div class="two-col-layout">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Recent Documents</span>
                    <a href="documents.php" class="btn btn-ghost btn-sm">View All</a>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead>
                            <tr><th>Title</th><th>Program</th><th>Status</th><th>By</th><th>Date</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($recent_docs->num_rows === 0): ?>
                        <tr><td colspan="5"><div class="empty-state"><p>No documents yet.</p></div></td></tr>
                        <?php else: while ($d = $recent_docs->fetch_assoc()): ?>
                        <tr>
                            <td><span style="font-weight:500;font-size:.875rem;"><?= htmlspecialchars(mb_substr($d['title'], 0, 45)) ?></span></td>
                            <td class="text-sm text-muted"><?= htmlspecialchars($d['program_name'] ?? '—') ?></td>
                            <td><span class="badge badge-<?= htmlspecialchars($d['status']) ?>"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $d['status']))) ?></span></td>
                            <td class="text-sm"><?= htmlspecialchars($d['uploader'] ?? '—') ?></td>
                            <td class="text-sm text-muted"><?= date('M d, Y', strtotime($d['created_at'])) ?></td>
                        </tr>
                        <?php endwhile; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><span class="card-title">Status Breakdown</span></div>
                <div class="card-body">
                    <?php
                    $statuses = ['draft','submitted','under_review','revision_requested','approved','rejected','archived'];
                    $labels   = ['Draft','Submitted','Under Review','Revision Requested','Approved','Rejected','Archived'];
                    $colors   = ['#6b7a8d','#2563a8','#7c3aed','#d97706','#059669','#dc2626','#374151'];
                    foreach ($statuses as $i => $st):
                        $cnt = $status_data[$st] ?? 0;
                        $pct = $total_docs > 0 ? round($cnt / $total_docs * 100) : 0;
                    ?>
                    <div style="margin-bottom:14px;">
                        <div style="display:flex;justify-content:space-between;margin-bottom:5px;">
                            <span style="font-size:.8rem;color:var(--text);"><?= $labels[$i] ?></span>
                            <span style="font-size:.8rem;color:var(--muted);"><?= $cnt ?></span>
                        </div>
                        <div class="progress-bar-wrap">
                            <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $colors[$i] ?>;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
