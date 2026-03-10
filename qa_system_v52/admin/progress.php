<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director','qa_staff']);
$active_nav = 'progress';

// ── Filters ───────────────────────────────────────────────────
$year_param_sent = array_key_exists('year', $_GET);
$year_f_raw      = $year_param_sent ? trim($_GET['year']) : '__FIRST_LOAD__';
$prog_f          = (int)($_GET['program'] ?? 0);

// Available academic years from actual documents
$years_q = $conn->query("SELECT DISTINCT academic_year FROM documents WHERE academic_year IS NOT NULL AND academic_year!='' ORDER BY academic_year DESC");
$years   = [];
while ($y = $years_q->fetch_assoc()) $years[] = $y['academic_year'];

if ($year_f_raw === '__FIRST_LOAD__') {
    $year_f = '';
} else {
    $year_f = $year_f_raw;
}
$year_f_safe = $conn->real_escape_string($year_f);

$year_sql = ($year_f !== '') ? "AND d.academic_year='$year_f_safe'" : '';
$prog_and = $prog_f ? "AND p.id=$prog_f" : '';

// ── Programs list (for dropdown) ──────────────────────────────
$programs_q = $conn->query("
    SELECT p.id, p.program_name, p.program_code, p.major,
           c.college_name, c.college_code
    FROM programs p
    JOIN colleges c ON c.id = p.college_id
    WHERE p.status = 'active'
    ORDER BY c.college_name, p.program_name
");

// ── All accreditation areas ───────────────────────────────────
$areas_q = $conn->query("SELECT id, area_code, area_name, sort_order FROM areas ORDER BY sort_order, area_name");
$areas = [];
while ($a = $areas_q->fetch_assoc()) $areas[] = $a;
$total_areas = count($areas);

// ── Coverage data: docs per (program, area) ───────────────────
$coverage_where = "d.deleted_at IS NULL $year_sql $prog_and";
$coverage_sql = "
    SELECT
        p.id   AS prog_id,
        p.program_name,
        p.program_code,
        p.major,
        c.id   AS college_id,
        c.college_name,
        c.college_code,
        d.area_id,
        COUNT(d.id) AS total,
        SUM(CASE WHEN d.status NOT IN ('draft','archived') THEN 1 ELSE 0 END) AS submitted,
        SUM(CASE WHEN d.status = 'approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN d.status = 'revision_requested' THEN 1 ELSE 0 END) AS needs_revision,
        SUM(CASE WHEN d.status IN ('submitted','under_review') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN d.deadline < CURDATE() AND d.status NOT IN ('approved','archived','rejected') THEN 1 ELSE 0 END) AS overdue
    FROM documents d
    JOIN programs p  ON p.id = d.program_id
    JOIN colleges c  ON c.id = p.college_id
    WHERE $coverage_where
    GROUP BY p.id, p.program_name, p.program_code, p.major, c.id, c.college_name, c.college_code, d.area_id
";
$cov_q   = $conn->query($coverage_sql);
$cov_rows = [];
if ($cov_q) {
    while ($row = $cov_q->fetch_assoc()) $cov_rows[] = $row;
}

// ── Build lookup arrays ───────────────────────────────────────
$cov_data  = [];  // [prog_id][area_id] => stats
$prog_info = [];  // [prog_id] => details

$pq2 = $conn->query("
    SELECT p.id, p.program_name, p.program_code, p.major,
           c.college_name, c.college_code
    FROM programs p
    JOIN colleges c ON c.id = p.college_id
    " . ($prog_f ? "WHERE p.id=$prog_f" : "WHERE p.status='active'") . "
    ORDER BY c.college_name, p.program_name
");
while ($pr = $pq2->fetch_assoc()) {
    $pid = (int)$pr['id'];
    $prog_info[$pid] = $pr;
    $cov_data[$pid]  = [];
}

foreach ($cov_rows as $row) {
    $pid = (int)$row['prog_id'];
    $aid = (int)$row['area_id'];
    if (!isset($cov_data[$pid])) $cov_data[$pid] = [];
    $cov_data[$pid][$aid] = [
        'total'          => (int)$row['total'],
        'submitted'      => (int)$row['submitted'],
        'approved'       => (int)$row['approved'],
        'needs_revision' => (int)$row['needs_revision'],
        'pending'        => (int)$row['pending'],
        'overdue'        => (int)$row['overdue'],
    ];
}

function areas_covered(array $area_map): int {
    $n = 0;
    foreach ($area_map as $s) { if ((int)($s['approved'] ?? 0) > 0) $n++; }
    return $n;
}
function areas_submitted(array $area_map): int {
    $n = 0;
    foreach ($area_map as $s) {
        if ((int)($s['submitted'] ?? 0) > 0 || (int)($s['approved'] ?? 0) > 0) $n++;
    }
    return $n;
}

// ── Global area map ───────────────────────────────────────────
$global_area_map = [];
foreach ($cov_data as $pid => $amap) {
    foreach ($amap as $aid => $s) {
        if (!isset($global_area_map[$aid])) $global_area_map[$aid] = ['approved'=>0,'submitted'=>0,'total'=>0,'overdue'=>0,'pending'=>0,'needs_revision'=>0];
        foreach ($s as $k => $v) $global_area_map[$aid][$k] += $v;
    }
}
$global_areas_covered   = areas_covered($global_area_map);
$global_areas_submitted = areas_submitted($global_area_map);

// ── Summary stats ─────────────────────────────────────────────
$stat_where = "d.deleted_at IS NULL $year_sql" . ($prog_f ? " AND p.id=$prog_f" : '');
$stat_join = "JOIN programs p ON p.id=d.program_id";
$total_docs    = (int)$conn->query("SELECT COUNT(*) c FROM documents d $stat_join WHERE $stat_where")->fetch_assoc()['c'];
$approved_docs = (int)$conn->query("SELECT COUNT(*) c FROM documents d $stat_join WHERE d.status='approved' AND $stat_where")->fetch_assoc()['c'];
$submitted_docs= (int)$conn->query("SELECT COUNT(*) c FROM documents d $stat_join WHERE d.status NOT IN ('draft','archived') AND $stat_where")->fetch_assoc()['c'];
$pending_review= (int)$conn->query("SELECT COUNT(*) c FROM documents d $stat_join WHERE d.status IN ('submitted','under_review') AND $stat_where")->fetch_assoc()['c'];
$overdue_docs  = (int)$conn->query("SELECT COUNT(*) c FROM documents d $stat_join WHERE d.deadline < CURDATE() AND d.status NOT IN ('approved','archived','rejected') AND $stat_where")->fetch_assoc()['c'];

$uid         = (int)($_SESSION['user_id'] ?? 0);
$notif_count = $uid ? (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'] : 0;

$export_qs_year = ($year_f !== '') ? '&year='.urlencode($year_f) : '';
$export_qs_prog = $prog_f ? '&program_id='.$prog_f : '';
$sel_prog_name  = ($prog_f && isset($prog_info[$prog_f])) ? $prog_info[$prog_f]['program_name'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Progress Tracking — QA System</title>
<?php include 'head.php'; ?>
<style>
.prog-cell { text-align:center; padding:8px 6px; min-width:72px; }
.area-th   { font-size:.7rem; max-width:80px; word-break:break-word; text-align:center; padding:8px 4px; line-height:1.3; }
.prog-head { background:var(--primary); color:white; padding:14px 20px; border-radius:10px 10px 0 0; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
.dept-row td  { font-size:.8rem; padding:9px 12px; }
.heat-0 { background:#f8f9fc; }
.heat-1 { background:#dbeafe; }
.heat-5 { background:#22c55e; color:white; }
.scard-prog { background:white; border:1.5px solid var(--border); border-radius:12px; padding:16px 20px; }
.scard-prog .n { font-size:1.8rem; font-weight:700; }
.scard-prog .l { font-size:.72rem; color:var(--muted); text-transform:uppercase; letter-spacing:.8px; margin-top:2px; }
.stat-tooltip { position:relative; cursor:default; }
.stat-tooltip:hover::after {
    content:attr(data-tip);
    position:absolute; bottom:calc(100% + 6px); left:50%; transform:translateX(-50%);
    background:#1e293b; color:white; padding:4px 10px; border-radius:6px;
    font-size:.72rem; white-space:nowrap; z-index:10; pointer-events:none;
}
.prog-badge {
    display:inline-block; font-size:.7rem; background:rgba(255,255,255,.2);
    border-radius:6px; padding:2px 8px; margin-top:3px;
}
/* Keep page-header filter aligned right even with long program names */
.prog-filter-form { flex-shrink:0; }
@media print { .sidebar,.topbar { display:none !important; } .main-content { margin:0 !important; } }
@media (max-width:900px) {
  .prog-filter-form { width:100%; }
  .prog-filter-form .filter-select { flex:1; min-width:120px; max-width:100% !important; }
}
@media (max-width:700px) {
  .scard-prog { padding:10px 12px; }
  .scard-prog .n { font-size:1.3rem; }
  .prog-head > div:last-child { flex-wrap:wrap; gap:12px !important; }
  .prog-head > div:last-child > div { text-align:left !important; }
}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">Progress Tracking</div>
    <div class="topbar-right">
      <button onclick="window.print()" class="btn btn-outline btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M5 4v3H4a2 2 0 00-2 2v3a2 2 0 002 2h1v2a1 1 0 001 1h8a1 1 0 001-1v-2h1a2 2 0 002-2V9a2 2 0 00-2-2h-1V4a1 1 0 00-1-1H6a1 1 0 00-1 1zm2 0h6v3H7V4zm-1 9v-1h8v1H6zm9-4a1 1 0 11-2 0 1 1 0 012 0z" clip-rule="evenodd"/></svg>
        Print
      </button>
      <a href="export_files.php?type=csv<?=$export_qs_year?><?=$export_qs_prog?>" class="btn btn-outline btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        Export CSV
      </a>
      <a href="export_files.php?type=zip<?=$export_qs_year?><?=$export_qs_prog?>" class="btn btn-outline btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
        Export ZIP
      </a>
      <a href="notifications.php" class="notif-btn">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        <?php if ($notif_count > 0): ?><span class="notif-badge"><?=$notif_count?></span><?php endif; ?>
      </a>
    </div>
  </div>

  <div class="page-body">
    <!-- Page header -->
    <div class="page-header">
      <div>
        <h1 class="page-heading">Accreditation Progress</h1>
        <p class="page-subheading">
          Program-level completion tracking —
          <strong><?= $year_f !== '' ? htmlspecialchars($year_f) : 'All Years' ?></strong><?= $sel_prog_name ? ' &middot; <strong>' . htmlspecialchars($sel_prog_name) . '</strong>' : '' ?>
        </p>
      </div>
      <!-- Filter form inline with heading (matches site-wide page-header pattern) -->
      <form method="GET" class="prog-filter-form">
        <select name="year" onchange="this.form.submit()" class="filter-select">
          <option value=""<?= $year_f === '' ? ' selected' : '' ?>>All Years</option>
          <?php foreach ($years as $y): ?>
          <option value="<?= htmlspecialchars($y) ?>"<?= $year_f === $y ? ' selected' : '' ?>><?= htmlspecialchars($y) ?></option>
          <?php endforeach; ?>
        </select>
        <select name="program" onchange="this.form.submit()" class="filter-select" style="min-width:240px;max-width:340px;">
          <option value="">All Programs</option>
          <?php
          $programs_q->data_seek(0);
          $last_college = '';
          while ($pr = $programs_q->fetch_assoc()):
            if ($pr['college_name'] !== $last_college):
              if ($last_college !== '') echo '</optgroup>';
              echo '<optgroup label="' . htmlspecialchars($pr['college_name']) . '">';
              $last_college = $pr['college_name'];
            endif;
            $display = htmlspecialchars($pr['program_name']);
            if (!empty($pr['major'])) $display .= ' (' . htmlspecialchars($pr['major']) . ')';
          ?>
          <option value="<?= $pr['id'] ?>"<?= $prog_f == $pr['id'] ? ' selected' : '' ?>><?= $display ?></option>
          <?php endwhile; if ($last_college !== '') echo '</optgroup>'; ?>
        </select>
        <?php if ($year_f !== '' || $prog_f): ?>
        <a href="progress.php" class="btn btn-ghost btn-sm" style="white-space:nowrap;">✕ Clear filters</a>
        <?php endif; ?>
      </form>
    </div>

    <?php
    $overall_area_pct = $total_areas > 0 ? round($global_areas_covered  / $total_areas * 100) : 0;
    $submit_area_pct  = $total_areas > 0 ? round($global_areas_submitted / $total_areas * 100) : 0;
    ?>

    <!-- Summary stat cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:24px;">
      <div class="scard-prog" style="border-color:var(--primary-light);">
        <div class="n" style="color:var(--primary);"><?= $total_areas ?></div>
        <div class="l">Defined Accreditation Areas</div>
      </div>
      <div class="scard-prog">
        <div class="n" style="color:var(--primary-light);"><?= $global_areas_submitted ?>/<?= $total_areas ?></div>
        <div class="l">Areas with Submissions<br><span style="color:var(--muted);font-size:.7rem;"><?= $submit_area_pct ?>% covered</span></div>
      </div>
      <div class="scard-prog">
        <div class="n" style="color:#d97706;"><?= $pending_review ?></div>
        <div class="l">Docs Pending Review</div>
      </div>
      <div class="scard-prog">
        <div class="n" style="color:var(--status-approved);"><?= $global_areas_covered ?>/<?= $total_areas ?></div>
        <div class="l">Areas Approved<br><span style="color:var(--muted);font-size:.7rem;"><?= $overall_area_pct ?>% complete</span></div>
      </div>
      <div class="scard-prog" style="border-color:<?= $overdue_docs > 0 ? '#fca5a5' : 'var(--border)' ?>;">
        <div class="n" style="color:<?= $overdue_docs > 0 ? '#dc2626' : 'var(--muted)' ?>;"><?= $overdue_docs ?></div>
        <div class="l">Overdue Docs</div>
      </div>
    </div>

    <!-- Overall progress bar -->
    <div class="card" style="padding:20px 24px;margin-bottom:24px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
        <span style="font-weight:600;font-size:.9rem;">
          Overall Area Coverage — <?= $year_f !== '' ? htmlspecialchars($year_f) : 'All Years' ?>
        </span>
        <span style="font-weight:700;font-size:1.1rem;color:<?= $overall_area_pct >= 80 ? 'var(--status-approved)' : ($overall_area_pct >= 50 ? 'var(--accent)' : '#dc2626') ?>">
          <?= $global_areas_covered ?> / <?= $total_areas ?> areas (<?= $overall_area_pct ?>%)
        </span>
      </div>
      <div style="height:14px;border-radius:7px;background:#e2e8f0;overflow:hidden;position:relative;">
        <div style="position:absolute;left:0;top:0;height:100%;border-radius:7px;background:#bfdbfe;width:<?= $submit_area_pct ?>%;"></div>
        <div style="position:absolute;left:0;top:0;height:100%;border-radius:7px;background:var(--status-approved);width:<?= $overall_area_pct ?>%;transition:width .8s;"></div>
      </div>
      <div style="display:flex;gap:20px;margin-top:12px;font-size:.78rem;color:var(--muted);flex-wrap:wrap;">
        <span>■ <span style="color:var(--status-approved);">Areas w/ Approved Doc (<?= $global_areas_covered ?>)</span></span>
        <span>■ <span style="color:#93c5fd;">Areas w/ Any Submission (<?= $global_areas_submitted ?>)</span></span>
        <span>■ <span style="color:#cbd5e1;">Not Started (<?= $total_areas - $global_areas_submitted ?>)</span></span>
      </div>
      <div style="margin-top:10px;font-size:.78rem;color:var(--muted);">
        Total documents: <?= $total_docs ?> &middot;
        Approved: <?= $approved_docs ?> &middot;
        Submitted (all): <?= $submitted_docs ?> &middot;
        Pending review: <?= $pending_review ?>
      </div>
    </div>

    <!-- Heatmap legend -->
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px;font-size:.78rem;color:var(--muted);">
      <strong style="color:var(--text);">Cell = area coverage:</strong>
      <span class="heat-0" style="padding:3px 10px;border-radius:4px;border:1px solid var(--border);">No docs</span>
      <span class="heat-1" style="padding:3px 10px;border-radius:4px;">Has docs, none approved yet</span>
      <span class="heat-5" style="padding:3px 10px;border-radius:4px;">✓ Approved</span>
      <span style="margin-left:4px;">· Total column = areas approved / <?= $total_areas ?> defined</span>
    </div>

    <?php if (empty($prog_info)): ?>
    <div class="card">
      <div class="empty-state" style="padding:60px 24px;">
        <p>No data found. Upload and submit documents to see progress here.</p>
      </div>
    </div>

    <?php else:
      foreach ($prog_info as $pid => $p_inf):
        $p_area_map  = $cov_data[$pid] ?? [];
        $p_covered   = areas_covered($p_area_map);
        $p_submitted = areas_submitted($p_area_map);
        $p_pct       = $total_areas > 0 ? round($p_covered  / $total_areas * 100) : 0;
        $p_sub_pct   = $total_areas > 0 ? round($p_submitted / $total_areas * 100) : 0;

        // Skip programs with no document data unless filtering to just that program
        $has_data = !empty($p_area_map) && array_sum(array_column($p_area_map, 'total')) > 0;
        if (!$has_data && !$prog_f) continue;
    ?>
    <div style="margin-bottom:28px;border-radius:12px;overflow:hidden;border:1.5px solid var(--border);">

      <!-- Program header -->
      <div class="prog-head">
        <div>
          <div style="font-weight:700;font-size:1rem;"><?= htmlspecialchars($p_inf['program_name']) ?></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px;">
            <?php if (!empty($p_inf['program_code'])): ?>
            <span class="prog-badge"><?= htmlspecialchars($p_inf['program_code']) ?></span>
            <?php endif; ?>
            <?php if (!empty($p_inf['major'])): ?>
            <span class="prog-badge">Major: <?= htmlspecialchars($p_inf['major']) ?></span>
            <?php endif; ?>
            <span class="prog-badge" style="background:rgba(255,255,255,.12);"><?= htmlspecialchars($p_inf['college_name']) ?></span>
          </div>
        </div>
        <div style="display:flex;gap:24px;align-items:center;">
          <div style="text-align:right;">
            <div style="font-size:1.4rem;font-weight:700;color:var(--accent);"><?= $p_pct ?>%</div>
            <div style="font-size:.7rem;opacity:.7;">Complete</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:1.4rem;font-weight:700;"><?= $p_covered ?>/<?= $total_areas ?></div>
            <div style="font-size:.7rem;opacity:.7;">Areas Approved</div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:1.4rem;font-weight:700;color:#86efac;"><?= $p_submitted ?>/<?= $total_areas ?></div>
            <div style="font-size:.7rem;opacity:.7;">Areas Submitted</div>
          </div>
        </div>
      </div>

      <!-- Progress bar -->
      <div style="background:white;padding:10px 16px;border-bottom:1px solid var(--border);">
        <div style="height:8px;border-radius:4px;background:#e2e8f0;overflow:hidden;position:relative;">
          <div style="position:absolute;left:0;top:0;height:100%;background:#bfdbfe;width:<?= $p_sub_pct ?>%;border-radius:4px;"></div>
          <div style="position:absolute;left:0;top:0;height:100%;background:var(--status-approved);width:<?= $p_pct ?>%;border-radius:4px;"></div>
        </div>
      </div>

      <!-- Area heatmap table -->
      <?php if (!empty($areas)): ?>
      <div style="overflow-x:auto;background:white;">
        <table style="border-collapse:collapse;width:100%;min-width:600px;">
          <thead>
            <tr style="background:var(--bg);">
              <th style="text-align:left;padding:10px 14px;font-size:.78rem;font-weight:600;border-bottom:1px solid var(--border);min-width:180px;position:sticky;left:0;background:var(--bg);z-index:1;">
                Program
              </th>
              <?php foreach ($areas as $area): ?>
              <th class="area-th" style="border-bottom:1px solid var(--border);border-left:1px solid var(--border);"
                  title="<?= htmlspecialchars($area['area_name']) ?>">
                <?php if ($area['area_code']): ?>
                <div style="color:var(--primary-light);margin-bottom:2px;"><?= htmlspecialchars($area['area_code']) ?></div>
                <?php endif; ?>
                <?= htmlspecialchars(mb_substr($area['area_name'], 0, 18)) ?><?= mb_strlen($area['area_name']) > 18 ? '…' : '' ?>
              </th>
              <?php endforeach; ?>
              <th class="area-th" style="border-bottom:1px solid var(--border);border-left:2px solid var(--primary-light);white-space:nowrap;">
                Covered<br><span style="font-weight:400;color:var(--muted);">/ <?= $total_areas ?></span>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr style="background:#f0f4f9;">
              <td style="padding:9px 14px;font-size:.78rem;font-weight:600;border-bottom:1px solid var(--border);color:var(--primary);position:sticky;left:0;background:#f0f4f9;z-index:1;">
                <?= htmlspecialchars($p_inf['program_code'] ?: $p_inf['program_name']) ?>
                <?php if (!empty($p_inf['major'])): ?>
                <div style="font-weight:400;color:var(--muted);font-size:.7rem;"><?= htmlspecialchars($p_inf['major']) ?></div>
                <?php endif; ?>
                <div style="font-weight:400;font-size:.68rem;color:var(--muted);margin-top:2px;"><?= $p_covered ?>/<?= $total_areas ?> covered</div>
              </td>
              <?php foreach ($areas as $area):
                $ad      = $p_area_map[(int)$area['id']] ?? ['total'=>0,'approved'=>0,'submitted'=>0,'overdue'=>0,'needs_revision'=>0];
                $covered = $ad['approved'] > 0;
                $has_sub = $ad['submitted'] > 0 || $ad['approved'] > 0;
                $heat    = !$has_sub ? 'heat-0' : ($covered ? 'heat-5' : 'heat-1');
                $tip     = $ad['total'] > 0
                    ? "Approved:{$ad['approved']}  Submitted:{$ad['submitted']}  Total:{$ad['total']}"
                      . ($ad['overdue']       > 0 ? "  ⚠{$ad['overdue']} overdue"        : '')
                      . ($ad['needs_revision'] > 0 ? "  ↩{$ad['needs_revision']} for revision" : '')
                    : 'No documents';
              ?>
              <td class="prog-cell <?= $heat ?> stat-tooltip"
                  data-tip="<?= htmlspecialchars($tip) ?>"
                  style="border-left:1px solid var(--border);border-bottom:1px solid var(--border);">
                <?php if ($covered): ?>
                  <div style="font-size:.85rem;font-weight:700;">✓</div>
                <?php elseif ($has_sub): ?>
                  <div style="font-size:.65rem;color:var(--primary);">in progress</div>
                <?php else: ?>
                  <span style="font-size:.7rem;color:#cbd5e1;">—</span>
                <?php endif; ?>
              </td>
              <?php endforeach; ?>
              <td class="prog-cell" style="border-left:2px solid var(--primary-light);border-bottom:1px solid var(--border);font-weight:700;">
                <div style="font-size:.9rem;"><?= $p_covered ?>/<?= $total_areas ?></div>
                <div style="font-size:.72rem;font-weight:700;color:<?= $p_pct >= 80 ? 'var(--status-approved)' : ($p_pct >= 50 ? 'var(--accent)' : '#dc2626') ?>;"><?= $p_pct ?>%</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; endif; ?>

  </div>
</div>
</body>
</html>
