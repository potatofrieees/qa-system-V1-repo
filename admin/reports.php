<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);

$active_nav = 'reports';

$dept_progress = $conn->query("
    SELECT c.college_name,
           COUNT(doc.id) total,
           SUM(CASE WHEN doc.status='approved' THEN 1 ELSE 0 END) approved,
           SUM(CASE WHEN doc.status NOT IN ('draft','archived') THEN 1 ELSE 0 END) submitted,
           SUM(CASE WHEN doc.status IN ('revision_requested','rejected') THEN 1 ELSE 0 END) needs_action,
           doc.academic_year
    FROM colleges c
    JOIN programs p ON p.college_id = c.id
    JOIN documents doc ON doc.program_id = p.id AND doc.deleted_at IS NULL
    GROUP BY c.id, c.college_name, doc.academic_year
    HAVING total > 0
    ORDER BY c.college_name
");

$monthly = $conn->query("
    SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) cnt
    FROM documents
    WHERE submitted_at IS NOT NULL AND submitted_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY month ORDER BY month
");
$monthly_data = [];
while ($m = $monthly->fetch_assoc()) $monthly_data[] = $m;

$by_level = $conn->query("
    SELECT al.level_name, COUNT(d.id) cnt
    FROM accreditation_levels al
    LEFT JOIN documents d ON d.accreditation_level_id = al.id AND d.deleted_at IS NULL
    GROUP BY al.id ORDER BY al.level_order
");
$level_labels = []; $level_counts = [];
while ($lv = $by_level->fetch_assoc()) {
    $level_labels[] = $lv['level_name'];
    $level_counts[] = (int)$lv['cnt'];
}

$uid         = (int)$_SESSION['user_id'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reports — QA System</title>
    <?php include 'head.php'; ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Reports &amp; Analytics</div>
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
                
                <h1 class="page-heading">Reports &amp; Analytics</h1>
                <p class="page-subheading">Accreditation document progress overview</p>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
            <div class="card">
                <div class="card-header"><span class="card-title">Monthly Submissions (12 months)</span></div>
                <div class="card-body"><canvas id="monthlyChart" height="200"></canvas></div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Documents by Accreditation Level</span></div>
                <div class="card-body"><canvas id="levelChart" height="200"></canvas></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><span class="card-title">College Progress</span></div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr><th>College</th><th>Year</th><th>Total</th><th>Submitted</th><th>Approved</th><th>Needs Action</th><th>Approval Rate</th></tr>
                    </thead>
                    <tbody>
                    <?php if ($dept_progress->num_rows === 0): ?>
                    <tr><td colspan="8"><div class="empty-state"><p>No data yet.</p></div></td></tr>
                    <?php else: while ($dp = $dept_progress->fetch_assoc()):
                        $pct = $dp['total'] > 0 ? round((int)$dp['approved'] / (int)$dp['total'] * 100) : 0;
                    ?>
                    <tr>
                        <td style="font-weight:500;"><?= htmlspecialchars($dp['college_name']) ?></td>
                        <td class="text-sm text-muted"><?= htmlspecialchars($dp['academic_year'] ?? 'N/A') ?></td>
                        <td><?= (int)$dp['total'] ?></td>
                        <td><?= (int)$dp['submitted'] ?></td>
                        <td style="color:var(--status-approved);font-weight:600;"><?= (int)$dp['approved'] ?></td>
                        <td style="color:var(--status-revision);font-weight:600;"><?= (int)$dp['needs_action'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <div class="progress-bar-wrap" style="width:80px;">
                                    <div class="progress-bar green" style="width:<?= $pct ?>%;"></div>
                                </div>
                                <span class="text-sm"><?= $pct ?>%</span>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
const monthlyData = <?= json_encode($monthly_data) ?>;
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: monthlyData.map(m => m.month),
        datasets: [{ label: 'Submitted', data: monthlyData.map(m => parseInt(m.cnt)),
            backgroundColor: 'rgba(37,99,168,0.7)', borderRadius: 6 }]
    },
    options: { responsive: true, plugins: { legend: { display: false } },
               scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
});
new Chart(document.getElementById('levelChart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode($level_labels) ?>,
        datasets: [{ data: <?= json_encode($level_counts) ?>,
            backgroundColor: ['#6b7a8d','#2563a8','#7c3aed','#059669','#e8a020'], borderWidth: 0 }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
</script>
</body>
</html>
