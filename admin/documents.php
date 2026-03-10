<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);
require_once '../mail/emails.php';

$active_nav = 'documents';
$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

// ── POST handler ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_action = $_POST['form_action'] ?? $_POST['action'] ?? '';
    $doc_id      = (int)($_POST['doc_id'] ?? 0);
    $reviewer    = (int)$_SESSION['user_id'];
    $_ip = $conn->real_escape_string($_SERVER['REMOTE_ADDR']??'');
    $_uid = (int)$_SESSION['user_id'];

    // ── Review decision ──────────────────────────────────────
    if (in_array($form_action, ['approve','reject','request_revision','under_review'])) {
        $comments = $conn->real_escape_string(trim($_POST['comments'] ?? ''));
        $status_map = [
            'approve'          => 'approved',
            'reject'           => 'rejected',
            'request_revision' => 'revision_requested',
            'under_review'     => 'under_review',
        ];
        $new_status = $status_map[$form_action];
        $extra_sql  = $new_status === 'approved' ? ', approved_at=NOW()' : '';
        $conn->query("UPDATE documents SET status='$new_status' $extra_sql WHERE id=$doc_id");

        if (in_array($form_action, ['approve','reject','request_revision'])) {
            $rr    = $conn->query("SELECT COALESCE(MAX(review_round),0)+1 nr FROM document_reviews WHERE document_id=$doc_id");
            $round = (int)$rr->fetch_assoc()['nr'];
            $conn->query("INSERT INTO document_reviews (document_id, reviewer_id, decision, comments, review_round)
                          VALUES ($doc_id, $reviewer, '$new_status', '$comments', $round)");

            // Notify uploader
            $dd = $conn->query("SELECT uploaded_by, title FROM documents WHERE id=$doc_id")->fetch_assoc();
            if ($dd && $dd['uploaded_by']) {
                $notif_uid = (int)$dd['uploaded_by'];
                $doc_title = $conn->real_escape_string($dd['title']);
                $raw_notif_comments = trim($_POST['comments'] ?? '');
                $comment_preview    = $raw_notif_comments ? ' — ' . mb_substr($raw_notif_comments, 0, 120) . (strlen($raw_notif_comments) > 120 ? '…' : '') : '';
                $notif_msg = $conn->real_escape_string("Your document \"{$dd['title']}\" was marked as: " . ucwords(str_replace('_',' ',$new_status)) . ".{$comment_preview}");
                $type      = $form_action === 'request_revision' ? 'revision_requested' : 'review_decision';
                $notif_title_map = [
                    'approved'           => '✅ Document Approved',
                    'rejected'           => '❌ Document Rejected',
                    'revision_requested' => '🔄 Revision Requested',
                ];
                $notif_title_str = $conn->real_escape_string($notif_title_map[$new_status] ?? 'Document Review Update');
                $conn->query("INSERT INTO notifications (user_id, type, title, message, link, priority)
                              VALUES ($notif_uid, '$type', '$notif_title_str', '$notif_msg', 'my_documents.php?highlight=$doc_id',
                              '" . ($form_action === 'request_revision' ? 'high' : 'normal') . "')");
                // Email the uploader
                $uploader = $conn->query("SELECT email, name FROM users WHERE id=$notif_uid")->fetch_assoc();
                if ($uploader) {
                    $raw_comments = trim($_POST['comments'] ?? '');
                    mail_review_decision($uploader['email'], $uploader['name'], $dd['title'], $new_status, $raw_comments);
                }
            }
        }
        $_status_e = $conn->real_escape_string($new_status);
        $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address) VALUES ($_uid,'DOC_".strtoupper(str_replace('_','',$new_status))."','documents',$doc_id,'Review decision: $_status_e on doc #$doc_id','$_ip')");
        $m = ucwords(str_replace('_',' ',$new_status)) . " applied successfully.";
        header("Location: documents.php?msg=".urlencode($m)."&typ=s".
               ($_GET['search']??''?'&search='.urlencode($_GET['search']??''):'').
               ($_GET['status']??''?'&status='.urlencode($_GET['status']??''):'').
               ($_GET['program']??''?'&program='.urlencode($_GET['program']??''):''));
        exit;
    }

    // ── Set deadline ─────────────────────────────────────────
    elseif ($form_action === 'set_deadline') {
        $raw_deadline = trim($_POST['deadline'] ?? '');
        // Validate date format (YYYY-MM-DD) before storing
        $deadline = null;
        if ($raw_deadline) {
            $d = DateTime::createFromFormat('Y-m-d', $raw_deadline);
            if ($d && $d->format('Y-m-d') === $raw_deadline) {
                $deadline = $conn->real_escape_string($raw_deadline);
            }
        }
        $dl_sql   = $deadline ? "'$deadline'" : 'NULL';
        $conn->query("UPDATE documents SET deadline=$dl_sql WHERE id=$doc_id");

        // Notify uploader about new deadline
        if ($deadline) {
            $dd = $conn->query("SELECT uploaded_by, title FROM documents WHERE id=$doc_id")->fetch_assoc();
            if ($dd && $dd['uploaded_by']) {
                $notif_uid = (int)$dd['uploaded_by'];
                $doc_title = $conn->real_escape_string($dd['title']);
                $dl_fmt    = date('F d, Y', strtotime($deadline));
                $notif_msg = $conn->real_escape_string("A submission deadline of {$dl_fmt} has been set for your document \"{$dd['title']}\".");
                $conn->query("INSERT INTO notifications (user_id, type, title, message, link, priority)
                              VALUES ($notif_uid, 'deadline_reminder', 'Deadline Set', '$notif_msg', 'my_documents.php?highlight=$doc_id', 'high')");
                // Email the uploader
                $uploader = $conn->query("SELECT email, name FROM users WHERE id=$notif_uid")->fetch_assoc();
                if ($uploader) { mail_deadline_set($uploader['email'], $uploader['name'], $dd['title'], $deadline); }
            }
        }
        header("Location: documents.php?msg=".urlencode('Deadline set successfully.')."&typ=s"); exit;
    }

    // ── Edit document metadata ───────────────────────────────
    elseif ($form_action === 'edit_document') {
        $title      = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $desc       = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $prog_id    = (int)($_POST['program_id'] ?? 0);
        $area_id    = (int)($_POST['area_id']    ?? 0);
        $level_id   = (int)($_POST['accreditation_level_id'] ?? 0);
        $acad_year  = $conn->real_escape_string(trim($_POST['academic_year'] ?? ''));
        $semester   = in_array($_POST['semester']??'', ['1st','2nd','Summer']) ? $_POST['semester'] : '1st';
        $prog_sql   = $prog_id  ? $prog_id  : 'NULL';
        $area_sql   = $area_id  ? $area_id  : 'NULL';
        $level_sql  = $level_id ? $level_id : 'NULL';

        $conn->query("UPDATE documents SET
            title='$title', description='$desc', program_id=$prog_sql,
            area_id=$area_sql, accreditation_level_id=$level_sql,
            academic_year='$acad_year', semester='$semester'
            WHERE id=$doc_id");
        header("Location: documents.php?msg=".urlencode('Document updated.')."&typ=s"); exit;
    }

    // ── Delete document ──────────────────────────────────────
    elseif ($form_action === 'delete_document') {
        // Fetch uploader info BEFORE soft-delete so the row is still visible
        $dd = $conn->query("SELECT uploaded_by, title FROM documents WHERE id=$doc_id AND deleted_at IS NULL")->fetch_assoc();
        $conn->query("UPDATE documents SET deleted_at=NOW() WHERE id=$doc_id");
        if ($dd && $dd['uploaded_by']) {
            $notif_uid = (int)$dd['uploaded_by'];
            $doc_title = $conn->real_escape_string($dd['title']);
            $conn->query("INSERT INTO notifications (user_id, type, title, message, link)
                          VALUES ($notif_uid, 'system', 'Document Removed',
                          'Your document \"$doc_title\" has been removed by an administrator.', 'my_documents.php')");
        }
        header("Location: documents.php?msg=".urlencode('Document deleted.')."&typ=s"); exit;
    }
    header("Location: documents.php"); exit;
}

// ── Filters ───────────────────────────────────────────────────
$search    = trim($_GET['search'] ?? '');
$status_f  = $_GET['status']   ?? '';
$prog_f    = (int)($_GET['program'] ?? 0);
$college_f = (int)($_GET['college'] ?? 0);
$per_page_raw = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$per_page  = in_array($per_page_raw, [10, 50, 100]) ? $per_page_raw : 50;
$page      = max(1, (int)($_GET['page'] ?? 1));
$offset    = ($page - 1) * $per_page;

// ── CSV Export handler ────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $where_e = ["d.deleted_at IS NULL"];
    if ($search)    $where_e[] = "(d.title LIKE '%" . $conn->real_escape_string($search) . "%' OR d.document_code LIKE '%" . $conn->real_escape_string($search) . "%')";
    if ($status_f)  $where_e[] = "d.status = '" . $conn->real_escape_string($status_f) . "'";
    if ($prog_f)    $where_e[] = "d.program_id = $prog_f";
    if ($college_f) $where_e[] = "c.id = $college_f";
    $exp = $conn->query("
        SELECT d.title, d.document_code, d.status, d.academic_year, d.semester, d.file_name,
               p.program_name, c.college_name,
               u.name AS uploader_name, al.level_name, a.area_name, d.created_at
        FROM documents d
        LEFT JOIN programs p ON p.id = d.program_id
        LEFT JOIN colleges c ON c.id = dp.college_id
        LEFT JOIN users u ON u.id = d.uploaded_by
        LEFT JOIN accreditation_levels al ON al.id = d.accreditation_level_id
        LEFT JOIN areas a ON a.id = d.area_id
        WHERE " . implode(' AND ', $where_e) . "
        ORDER BY c.college_name, d.updated_at DESC
    ");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="documents_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['College','Department','Program','Title','Document Code','Status','Academic Year','Semester','Area','Level','Uploaded By','File Name','Created']);
    while ($e = $exp->fetch_assoc()) {
        fputcsv($out, [
            $e['college_name'] ?? '', $e['program_name'] ?? '',
            $e['title'], $e['document_code'] ?? '',
            ucwords(str_replace('_',' ',$e['status'])),
            $e['academic_year'] ?? '', $e['semester'] ?? '',
            $e['area_name'] ?? '', $e['level_name'] ?? '',
            $e['uploader_name'] ?? '', $e['file_name'] ?? '', $e['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$where = ["d.deleted_at IS NULL"];
if ($search)    $where[] = "(d.title LIKE '%" . $conn->real_escape_string($search) . "%' OR d.document_code LIKE '%" . $conn->real_escape_string($search) . "%')";
if ($status_f)  $where[] = "d.status = '" . $conn->real_escape_string($status_f) . "'";
if ($prog_f)    $where[] = "d.program_id = $prog_f";
if ($college_f) $where[] = "c.id = $college_f";

$docs = $conn->query("
    SELECT d.*, p.program_name, u.name AS uploader_name, al.level_name, a.area_name,
           c.college_name, c.id AS college_id_val
    FROM documents d
    LEFT JOIN programs p  ON p.id  = d.program_id
    LEFT JOIN colleges c  ON c.id  = p.college_id
    LEFT JOIN users u     ON u.id  = d.uploaded_by
    LEFT JOIN accreditation_levels al ON al.id = d.accreditation_level_id
    LEFT JOIN areas a     ON a.id  = d.area_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY d.updated_at DESC LIMIT $per_page OFFSET $offset
");

// Count total for pagination
$count_q = $conn->query("
    SELECT COUNT(*) c FROM documents d
    LEFT JOIN programs p  ON p.id  = d.program_id
    LEFT JOIN colleges c  ON c.id  = p.college_id
    WHERE " . implode(' AND ', $where)
);
$total_count = ($count_q && ($count_row = $count_q->fetch_assoc())) ? (int)$count_row['c'] : 0;
$total_pages = ($per_page > 0 && $total_count > 0) ? (int)ceil($total_count / $per_page) : 1;

$programs = $conn->query("SELECT id, program_name FROM programs WHERE status='active' ORDER BY program_name");
$all_programs = $conn->query("SELECT id, program_name, program_code FROM programs WHERE status='active' ORDER BY program_name");
$all_areas    = $conn->query("SELECT id, area_name, area_code FROM areas ORDER BY sort_order, area_name");
$all_levels   = $conn->query("SELECT id, level_name FROM accreditation_levels ORDER BY level_order");
$all_colleges = $conn->query("SELECT id, college_code, college_name FROM colleges WHERE status='active' ORDER BY college_name");

$uid         = (int)$_SESSION['user_id'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Documents — QA System</title>
    <?php include 'head.php'; ?>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Document Management</div>
        <div class="topbar-right">
            <button type="button" class="btn btn-outline btn-sm" onclick="openModal('exportModal')">
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                Export
            </button>
            <a href="notifications.php" class="notif-btn" title="Notifications">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if ($notif_count > 0): ?><span class="notif-badge"><?= $notif_count ?></span><?php endif; ?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>" id="flash-alert">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                
                <h1 class="page-heading">Documents</h1>
                <p class="page-subheading">Review, edit, and manage accreditation documents</p>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card" style="margin-bottom:0;border-bottom-left-radius:0;border-bottom-right-radius:0;">
            <div class="filter-bar">
                <input type="text" class="search-input" placeholder="Search title or code…" id="searchInput"
                       value="<?= htmlspecialchars($search) ?>">
                <select id="statusFilter">
                    <option value="">All Statuses</option>
                    <?php foreach (['draft','submitted','under_review','revision_requested','approved','rejected','archived'] as $st): ?>
                    <option value="<?= $st ?>" <?= $status_f === $st ? 'selected' : '' ?>>
                        <?= ucwords(str_replace('_', ' ', $st)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <select id="programFilter">
                    <option value="">All Programs</option>
                    <?php while ($p = $programs->fetch_assoc()): ?>
                    <option value="<?= $p['id'] ?>" <?= $prog_f == $p['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p['program_name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
                <select id="collegeFilter">
                    <option value="">All Colleges</option>
                    <?php $all_colleges->data_seek(0); while ($col = $all_colleges->fetch_assoc()): ?>
                    <option value="<?= $col['id'] ?>" <?= $college_f == $col['id'] ? 'selected' : '' ?>>
                        [<?= htmlspecialchars($col['college_code']) ?>] <?= htmlspecialchars($col['college_name']) ?>
                    </option>
                    <?php endwhile; ?>
                </select>
                <select id="perPageFilter" style="padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;font-family:inherit;">
                    <option value="10" <?= $per_page==10?'selected':'' ?>>10 / page</option>
                    <option value="50" <?= $per_page==50?'selected':'' ?>>50 / page</option>
                    <option value="100" <?= $per_page==100?'selected':'' ?>>100 / page</option>
                </select>
                <button class="btn btn-outline btn-sm" onclick="applyFilters()" id="filterBtn">
                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z" clip-rule="evenodd"/></svg>
                    Filter
                </button>
            </div>
        </div>

        <div class="card" style="border-top-left-radius:0;border-top-right-radius:0;margin-top:0;border-top:none;">
            <div style="padding:10px 16px;font-size:.8rem;color:var(--muted);border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                <span>Showing <?= min($offset+1,$total_count) ?>–<?= min($offset+$per_page,$total_count) ?> of <strong><?= $total_count ?></strong> documents</span>
                <?php if($total_pages>1): ?>
                <div style="display:flex;gap:4px;">
                    <?php if($page>1): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page-1])) ?>" class="btn btn-ghost btn-sm">← Prev</a><?php endif; ?>
                    <?php for($pg=max(1,$page-2);$pg<=min($total_pages,$page+2);$pg++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET,['page'=>$pg])) ?>" class="btn btn-sm <?= $pg==$page?'btn-primary':'btn-ghost' ?>"><?=$pg?></a>
                    <?php endfor; ?>
                    <?php if($page<$total_pages): ?><a href="?<?= http_build_query(array_merge($_GET,['page'=>$page+1])) ?>" class="btn btn-ghost btn-sm">Next →</a><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Program</th>
                            <th>Status</th>
                            <th>Uploaded By</th>
                            <th>Deadline</th>
                            <th>File</th>
                            <th style="min-width:200px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($docs->num_rows === 0): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
                            <p>No documents found matching your filters.</p>
                        </div>
                    </td></tr>
                    <?php else: while ($d = $docs->fetch_assoc()):
                        $is_overdue = $d['deadline'] && strtotime($d['deadline']) < time() && !in_array($d['status'], ['approved','archived']);
                        $deadline_class = $is_overdue ? 'color:var(--status-rejected);font-weight:700;' : 'color:var(--muted);';
                    ?>
                    <tr>
                        <td style="max-width:220px;">
                            <div style="font-weight:500;font-size:.875rem;line-height:1.3;">
                                <a href="doc_view.php?id=<?= $d['id'] ?>" style="color:var(--primary);text-decoration:none;" title="View full document details">
                                    <?= htmlspecialchars(mb_substr($d['title'], 0, 55)) ?>
                                </a>
                            </div>
                            <?php if ($d['document_code']): ?>
                            <div class="text-sm text-muted"><?= htmlspecialchars($d['document_code']) ?></div>
                            <?php endif; ?>
                            <?php if ($d['area_name']): ?>
                            <div class="text-sm text-muted"><?= htmlspecialchars($d['area_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm"><?= htmlspecialchars($d['program_name'] ?? '—') ?></td>
                        <td>
                            <span class="badge badge-<?= htmlspecialchars($d['status']) ?>">
                                <?= htmlspecialchars(ucwords(str_replace('_',' ',$d['status']))) ?>
                            </span>
                        </td>
                        <td class="text-sm"><?= htmlspecialchars($d['uploader_name'] ?? '—') ?></td>
                        <td class="text-sm" style="<?= $deadline_class ?>">
                            <?php if ($d['deadline']): ?>
                                <?= date('M d, Y', strtotime($d['deadline'])) ?>
                                <?php if ($is_overdue): ?><br><span style="font-size:.68rem;">OVERDUE</span><?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--muted);">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($d['file_path']): ?>
                            <button type="button" class="btn btn-ghost btn-sm" title="View file" onclick='openFileViewer(<?= json_encode(["id"=>$d["id"],"title"=>$d["title"],"file_name"=>$d["file_name"],"file_type"=>$d["file_type"]]) ?>)'>
                                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                View
                            </button>
                            <?php elseif ($d['file_name']): ?>
                            <span class="text-sm text-muted" title="File not found on server">No file</span>
                            <?php else: ?>
                            <span class="text-sm text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:5px;flex-wrap:wrap;">
                                <!-- Start Review -->
                                <?php if ($d['status'] === 'submitted'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="form_action" value="under_review">
                                    <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                    <input type="hidden" name="comments" value="">
                                    <button type="submit" class="btn btn-outline btn-sm">Start Review</button>
                                </form>
                                <?php endif; ?>

                                <!-- Review / Decide -->
                                <?php if (in_array($d['status'], ['submitted','under_review','revision_requested'])): ?>
                                <button class="btn btn-primary btn-sm" onclick='openReview(<?= json_encode([
                                    "id"     => $d["id"],
                                    "title"  => $d["title"],
                                    "status" => $d["status"]
                                ]) ?>)'>Review</button>
                                <?php endif; ?>

                                <!-- Set Deadline -->
                                <button class="btn btn-ghost btn-sm" onclick='openDeadline(<?= json_encode([
                                    "id"       => $d["id"],
                                    "title"    => $d["title"],
                                    "deadline" => $d["deadline"]
                                ]) ?>)' title="Set submission deadline">
                                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
                                    Deadline
                                </button>

                                <!-- Edit -->
                                <button class="btn btn-ghost btn-sm" onclick='openEdit(<?= json_encode([
                                    "id"                      => $d["id"],
                                    "title"                   => $d["title"],
                                    "description"             => $d["description"],
                                    "program_id"              => $d["program_id"],
                                    "area_id"                 => $d["area_id"],
                                    "accreditation_level_id"  => $d["accreditation_level_id"],
                                    "academic_year"           => $d["academic_year"],
                                    "semester"                => $d["semester"],
                                ]) ?>)' title="Edit document">
                                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                    Edit
                                </button>

                                <!-- Delete -->
                                <form method="POST" style="display:inline;" class="swal-confirm-form" data-title="Delete Document?" data-text="This document will be permanently deleted and cannot be recovered." data-icon="warning" data-confirm="Yes, Delete" data-cls="qa-btn-red">
                                    <input type="hidden" name="form_action" value="delete_document">
                                    <input type="hidden" name="doc_id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" title="Delete document">
                                        <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                        Delete
                                    </button>
                                </form>
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


<!-- File Viewer Modal -->
<div class="modal-overlay" id="fileViewerModal" style="z-index:1100;">
    <div class="modal" style="max-width:860px;width:95vw;height:90vh;display:flex;flex-direction:column;">
        <div class="modal-header">
            <span class="modal-title" id="fileViewerTitle">Document Preview</span>
            <div style="display:flex;gap:8px;align-items:center;">
                <a id="fileViewerDownload" href="#" download class="btn btn-outline btn-sm">
                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    Download
                </a>
                <a id="fileViewerNewTab" href="#" target="_blank" class="btn btn-outline btn-sm">Open in Tab</a>
                <button type="button" class="modal-close" onclick="closeModal('fileViewerModal')">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </button>
            </div>
        </div>
        <div id="fileViewerBody" style="flex:1;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#2d3748;"></div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════ MODALS ══ -->

<!-- Review Modal -->
<div class="modal-overlay" id="reviewModal">
    <div class="modal" style="max-width:540px;">
        <div class="modal-header">
            <span class="modal-title" id="reviewTitle">Review Document</span>
            <button type="button" class="modal-close" onclick="closeModal('reviewModal')">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" id="reviewAction" value="">
                <input type="hidden" name="doc_id"      id="reviewDocId"  value="">
                <div id="reviewStatusBadge" style="margin-bottom:16px;"></div>
                <div class="field">
                    <label>Review Comments <span style="color:var(--muted);font-weight:400;">(required for revision/reject)</span></label>
                    <textarea name="comments" rows="5" placeholder="Enter your detailed review comments…" style="resize:vertical;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('reviewModal')">Cancel</button>
                <button type="button" class="btn btn-sm" style="background:#dc2626;color:white;"
                    onclick="confirmReview('reject')">
                    ✗ Reject
                </button>
                <button type="button" class="btn btn-sm" style="background:#d97706;color:white;"
                    onclick="confirmReview('request_revision')">
                    ↩ Request Revision
                </button>
                <button type="button" class="btn btn-sm" style="background:#059669;color:white;"
                    onclick="confirmReview('approve')">
                    ✓ Approve
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Deadline Modal -->
<div class="modal-overlay" id="deadlineModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title">Set Submission Deadline</span>
            <button type="button" class="modal-close" onclick="closeModal('deadlineModal')">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" value="set_deadline">
                <input type="hidden" name="doc_id" id="dlDocId">
                <p class="text-sm text-muted" id="dlDocName" style="margin-bottom:16px;"></p>
                <div class="field">
                    <label>Submission Deadline</label>
                    <input type="date" name="deadline" id="dlDate">
                    <span class="text-sm text-muted" style="margin-top:4px;display:block;">The uploader will be notified of this deadline.</span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('deadlineModal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmSetDeadline()">Set Deadline</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal" style="max-width:660px;">
        <div class="modal-header">
            <span class="modal-title">Edit Document</span>
            <button type="button" class="modal-close" onclick="closeModal('editModal')">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" value="edit_document">
                <input type="hidden" name="doc_id" id="editDocId">
                <div class="form-row">
                    <div class="field">
                        <label>Title *</label>
                        <input type="text" name="title" id="editTitle" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label>Description</label>
                        <textarea name="description" id="editDesc" rows="3"></textarea>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="field">
                        <label>Program</label>
                        <select name="program_id" id="editProgram">
                            <option value="0">None</option>
                            <?php $all_programs->data_seek(0); while ($p = $all_programs->fetch_assoc()): ?>
                            <option value="<?= $p['id'] ?>">[<?= htmlspecialchars($p['program_code']) ?>] <?= htmlspecialchars($p['program_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Area</label>
                        <select name="area_id" id="editArea">
                            <option value="0">None</option>
                            <?php $all_areas->data_seek(0); while ($a = $all_areas->fetch_assoc()): ?>
                            <option value="<?= $a['id'] ?>"><?= $a['area_code'] ? '['.htmlspecialchars($a['area_code']).'] ':'' ?><?= htmlspecialchars($a['area_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="field">
                        <label>Accreditation Level</label>
                        <select name="accreditation_level_id" id="editLevel">
                            <option value="0">None</option>
                            <?php $all_levels->data_seek(0); while ($lv = $all_levels->fetch_assoc()): ?>
                            <option value="<?= $lv['id'] ?>"><?= htmlspecialchars($lv['level_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Semester</label>
                        <select name="semester" id="editSemester">
                            <option value="1st">1st Semester</option>
                            <option value="2nd">2nd Semester</option>
                            <option value="Summer">Summer</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field">
                        <label>Academic Year</label>
                        <input type="text" name="academic_year" id="editAcadYear" placeholder="e.g. 2024-2025">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="confirmEditDoc()">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Export Modal -->
<div class="modal-overlay" id="exportModal">
    <div class="modal" style="max-width:600px;">
        <div class="modal-header">
            <span class="modal-title">Export Documents</span>
            <button type="button" class="modal-close" onclick="closeModal('exportModal')">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <p class="text-sm text-muted" style="margin-bottom:16px;">
                Select which colleges to export. Choose <strong>CSV</strong> for a spreadsheet of document metadata, or <strong>ZIP</strong> to download the actual submitted files organized into folders by college → department → program.
            </p>

            <!-- College checkboxes -->
            <div class="field">
                <label style="margin-bottom:8px;display:flex;align-items:center;justify-content:space-between;">
                    Colleges to Include
                    <span style="display:flex;gap:8px;">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAllColleges(true)">All</button>
                        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleAllColleges(false)">None</button>
                    </span>
                </label>
                <div id="collegeCheckboxes" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;max-height:200px;overflow-y:auto;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;">
                    <?php $all_colleges->data_seek(0); while ($col = $all_colleges->fetch_assoc()): ?>
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.875rem;">
                        <input type="checkbox" class="college-check" value="<?= $col['id'] ?>" checked
                               style="width:16px;height:16px;cursor:pointer;">
                        <span>[<?= htmlspecialchars($col['college_code']) ?>] <?= htmlspecialchars($col['college_name']) ?></span>
                    </label>
                    <?php endwhile; ?>
                </div>
            </div>

            <!-- Status filter for export -->
            <div class="form-row cols-2" style="margin-top:12px;">
                <div class="field">
                    <label>Status Filter</label>
                    <select id="exportStatus">
                        <option value="">All Statuses</option>
                        <?php foreach (['draft','submitted','under_review','revision_requested','approved','rejected','archived'] as $st): ?>
                        <option value="<?= $st ?>"><?= ucwords(str_replace('_',' ',$st)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Export Type</label>
                    <select id="exportType">
                        <option value="csv">📊 CSV Spreadsheet (metadata only)</option>
                        <option value="zip">📦 ZIP Archive (actual files + folders)</option>
                    </select>
                </div>
            </div>

            <div id="exportInfo" style="margin-top:12px;padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;font-size:.8rem;color:#1e40af;display:none;"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline" onclick="closeModal('exportModal')">Cancel</button>
            <button type="button" class="btn btn-outline btn-sm" id="exportCountBtn" onclick="previewExportCount()">
                Preview Count
            </button>
            <button type="button" class="btn btn-primary" id="exportDownloadBtn" onclick="doExport()">
                <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                Download Export
            </button>
        </div>
    </div>
</div>

<script>
// ── Modal helpers (also defined in main.js but redefined here as safety fallback) ──
function openModal(id)  { const el = document.getElementById(id); if (el) el.classList.add('open'); }
function closeModal(id) { const el = document.getElementById(id); if (el) el.classList.remove('open'); }

function openReview(d) {
    document.getElementById('reviewDocId').value = d.id;
    document.getElementById('reviewTitle').textContent = d.title;
    document.getElementById('reviewStatusBadge').innerHTML =
        '<span class="badge badge-' + d.status + '">' + d.status.replace(/_/g,' ') + '</span>';
    openModal('reviewModal');
}
function openDeadline(d) {
    document.getElementById('dlDocId').value = d.id;
    document.getElementById('dlDocName').textContent = d.title;
    document.getElementById('dlDate').value = d.deadline || '';
    openModal('deadlineModal');
}
function openEdit(d) {
    document.getElementById('editDocId').value    = d.id;
    document.getElementById('editTitle').value    = d.title       || '';
    document.getElementById('editDesc').value     = d.description || '';
    document.getElementById('editProgram').value  = d.program_id  || '0';
    document.getElementById('editArea').value     = d.area_id     || '0';
    document.getElementById('editLevel').value    = d.accreditation_level_id || '0';
    document.getElementById('editSemester').value = d.semester    || '1st';
    document.getElementById('editAcadYear').value = d.academic_year || '';
    openModal('editModal');
}
function openFileViewer(doc) {
    const url = 'view_file.php?id=' + doc.id;
    const ext = (doc.file_name || '').split('.').pop().toLowerCase();
    document.getElementById('fileViewerTitle').textContent = doc.title || 'Document Preview';
    document.getElementById('fileViewerDownload').href = url + '&download=1';
    document.getElementById('fileViewerDownload').download = doc.file_name || 'document';
    document.getElementById('fileViewerNewTab').href = url;
    const body = document.getElementById('fileViewerBody');
    body.innerHTML = '<div style="color:#aaa;font-size:.9rem;">Loading…</div>';

    if (['pdf'].includes(ext)) {
        const iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.style.cssText = 'width:100%;height:100%;border:none;background:white;';
        iframe.onload = () => {};
        body.innerHTML = '';
        body.appendChild(iframe);
    } else if (['png','jpg','jpeg','gif','webp','svg'].includes(ext)) {
        const img = document.createElement('img');
        img.src = url;
        img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;border-radius:4px;';
        img.onerror = () => {
            body.innerHTML = `<div style="text-align:center;color:#aaa;padding:40px;">
                <p>Could not load image.</p>
                <a href="${url}" download="${doc.file_name || 'file'}" class="btn btn-primary" style="margin-top:12px;">Download File</a>
            </div>`;
        };
        body.innerHTML = '';
        body.appendChild(img);
    } else {
        body.innerHTML = `<div style="text-align:center;color:white;padding:40px;">
            <svg viewBox="0 0 20 20" fill="currentColor" style="width:64px;height:64px;margin:0 auto 16px;opacity:.5;"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
            <p style="font-size:1rem;margin-bottom:8px;color:white;">${escHtml(doc.file_name || 'Document')}</p>
            <p style="font-size:.85rem;opacity:.7;margin-bottom:20px;color:#ccc;">Preview not available for .${escHtml(ext)} files</p>
            <a href="${url}&download=1" download="${escHtml(doc.file_name || 'document')}" class="btn btn-primary">⬇ Download File</a>
        </div>`;
    }
    openModal('fileViewerModal');
}

function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
}

function applyFilters() {
    const s   = encodeURIComponent(document.getElementById('searchInput').value);
    const st  = encodeURIComponent(document.getElementById('statusFilter').value);
    const pr  = encodeURIComponent(document.getElementById('programFilter').value);
    const col = encodeURIComponent(document.getElementById('collegeFilter')?.value || '');
    const pp  = encodeURIComponent(document.getElementById('perPageFilter')?.value || '50');
    window.location.href = 'documents.php?search='+s+'&status='+st+'&program='+pr+'&college='+col+'&per_page='+pp;
}

// ── Live search with highlight ─────────────────────────────────
const searchInput = document.getElementById('searchInput');
let searchDebounce = null;

// Store original cell HTML so we can restore it
const tableRows = document.querySelectorAll('tbody tr');
const originalCells = new Map();
tableRows.forEach(row => {
    const titleCell = row.querySelector('td:first-child');
    if (titleCell) originalCells.set(row, titleCell.innerHTML);
});

function highlightText(html, term) {
    if (!term) return html;
    // Work on text nodes only — avoid breaking tags
    const div = document.createElement('div');
    div.innerHTML = html;
    walkAndHighlight(div, term);
    return div.innerHTML;
}

function walkAndHighlight(node, term) {
    if (node.nodeType === Node.TEXT_NODE) {
        const idx = node.textContent.toLowerCase().indexOf(term.toLowerCase());
        if (idx === -1) return;
        const before = document.createTextNode(node.textContent.slice(0, idx));
        const mark = document.createElement('mark');
        mark.style.cssText = 'background:#fef08a;border-radius:2px;padding:0 2px;font-weight:600;';
        mark.textContent = node.textContent.slice(idx, idx + term.length);
        const after = document.createTextNode(node.textContent.slice(idx + term.length));
        const parent = node.parentNode;
        parent.insertBefore(before, node);
        parent.insertBefore(mark, node);
        parent.insertBefore(after, node);
        parent.removeChild(node);
        // Recurse into the 'after' text for multiple matches
        walkAndHighlight(after, term);
    } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName !== 'SCRIPT' && node.tagName !== 'STYLE') {
        Array.from(node.childNodes).forEach(child => walkAndHighlight(child, term));
    }
}

function liveSearch() {
    const term = searchInput.value.trim();

    // Restore originals first
    tableRows.forEach(row => {
        const titleCell = row.querySelector('td:first-child');
        if (titleCell && originalCells.has(row)) {
            titleCell.innerHTML = originalCells.get(row);
        }
        row.style.display = '';
    });

    if (!term) return;

    const termLow = term.toLowerCase();
    tableRows.forEach(row => {
        const text = row.textContent.toLowerCase();
        if (text.includes(termLow)) {
            // Highlight in first cell (title/code)
            const titleCell = row.querySelector('td:first-child');
            if (titleCell) {
                titleCell.innerHTML = highlightText(originalCells.get(row) || titleCell.innerHTML, term);
            }
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

searchInput.addEventListener('input', () => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(liveSearch, 180);
});

// Enter / select dropdowns still trigger full server-side filter
searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') applyFilters();
});
document.getElementById('statusFilter').addEventListener('change', applyFilters);
document.getElementById('programFilter').addEventListener('change', applyFilters);
document.getElementById('collegeFilter')?.addEventListener('change', applyFilters);
document.getElementById('perPageFilter')?.addEventListener('change', applyFilters);

// Run once on load in case there's a pre-filled search value
if (searchInput.value.trim()) liveSearch();

// ── Export modal functions ────────────────────────────────────
function toggleAllColleges(state) {
    document.querySelectorAll('.college-check').forEach(cb => cb.checked = state);
}


// ── SweetAlert confirmations for modal actions ────────────────

function confirmReview(action) {
    var form     = document.querySelector('#reviewModal form');
    var comments = form.querySelector('textarea[name="comments"]');

    // Validation first
    if (!comments || !comments.value.trim()) {
        Swal.fire({
            icon: 'warning', title: 'Comments Required',
            html: 'Please enter your review comments before submitting.',
            confirmButtonText: 'OK',
            customClass: { popup: 'qa-popup', confirmButton: 'qa-btn qa-btn-purple' },
            buttonsStyling: false,
        });
        return;
    }

    var configs = {
        approve: {
            title: 'Approve Document?',
            html:  'The document will be marked as <strong>Approved</strong> and the uploader will be notified.',
            icon:  'success',
            confirmText: 'Yes, Approve',
            cls:   'qa-btn-green',
        },
        request_revision: {
            title: 'Request Revision?',
            html:  'The document will be sent back for <strong>Revision</strong> and the uploader will be notified.',
            icon:  'warning',
            confirmText: 'Yes, Request Revision',
            cls:   'qa-btn-purple',
        },
        reject: {
            title: 'Reject Document?',
            html:  'The document will be marked as <strong>Rejected</strong> and the uploader will be notified.',
            icon:  'error',
            confirmText: 'Yes, Reject',
            cls:   'qa-btn-red',
        },
    };

    var cfg = configs[action] || { title: 'Submit Review?', html: '', icon: 'question', confirmText: 'Yes, Submit', cls: 'qa-btn-purple' };

    Swal.fire({
        title: cfg.title, html: cfg.html, icon: cfg.icon,
        showCancelButton: true,
        confirmButtonText: cfg.confirmText,
        cancelButtonText: 'Go Back',
        reverseButtons: true, focusCancel: true,
        customClass: { popup: 'qa-popup', confirmButton: 'qa-btn ' + cfg.cls, cancelButton: 'qa-btn qa-btn-gray' },
        buttonsStyling: false,
    }).then(function(result) {
        if (!result.isConfirmed) return;
        document.getElementById('reviewAction').value = action;
        HTMLFormElement.prototype.submit.call(form);
    });
}

function confirmSetDeadline() {
    var form  = document.querySelector('#deadlineModal form');
    var date  = document.getElementById('dlDate').value;
    var title = document.getElementById('dlDocName').textContent || 'this document';

    var html = date
        ? 'Set deadline of <strong>' + date + '</strong> for <em>' + title + '</em>? The uploader will be notified.'
        : 'Clear the deadline for <em>' + title + '</em>?';
    var confirmText = date ? 'Yes, Set Deadline' : 'Yes, Clear Deadline';
    var icon = date ? 'question' : 'warning';

    Swal.fire({
        title: 'Set Submission Deadline?', html: html, icon: icon,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: 'Cancel',
        reverseButtons: true, focusCancel: true,
        customClass: { popup: 'qa-popup', confirmButton: 'qa-btn qa-btn-purple', cancelButton: 'qa-btn qa-btn-gray' },
        buttonsStyling: false,
    }).then(function(result) {
        if (!result.isConfirmed) return;
        HTMLFormElement.prototype.submit.call(form);
    });
}

function confirmEditDoc() {
    var form = document.querySelector('#editModal form');
    Swal.fire({
        title: 'Save Changes?', html: 'Save the changes to this document?', icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Save Changes',
        cancelButtonText: 'Cancel',
        reverseButtons: true, focusCancel: true,
        customClass: { popup: 'qa-popup', confirmButton: 'qa-btn qa-btn-purple', cancelButton: 'qa-btn qa-btn-gray' },
        buttonsStyling: false,
    }).then(function(result) {
        if (!result.isConfirmed) return;
        HTMLFormElement.prototype.submit.call(form);
    });
}

function buildExportUrl(type) {
    const ids    = [...document.querySelectorAll('.college-check:checked')].map(c => c.value);
    const status = document.getElementById('exportStatus').value;
    let url = 'export_files.php?type=' + encodeURIComponent(type);
    ids.forEach(id => url += '&college_ids[]=' + encodeURIComponent(id));
    if (status) url += '&status=' + encodeURIComponent(status);
    return url;
}

function showExportInfo(msg, isError) {
    const info = document.getElementById('exportInfo');
    info.style.background = isError ? '#fef2f2' : '#eff6ff';
    info.style.border      = isError ? '1px solid #fecaca' : '1px solid #bfdbfe';
    info.style.color       = isError ? '#dc2626' : '#1e40af';
    info.innerHTML = msg;
    info.style.display = 'block';
}

function previewExportCount() {
    const ids = [...document.querySelectorAll('.college-check:checked')];
    if (ids.length === 0) { showExportInfo('Please select at least one college first.', true); return; }
    const btn = document.getElementById('exportCountBtn');
    btn.disabled = true; btn.textContent = 'Counting…';
    document.getElementById('exportInfo').style.display = 'none';

    fetch(buildExportUrl('count'))
        .then(r => r.json())
        .then(data => {
            if (data.error) { showExportInfo(data.error, true); return; }
            const type = document.getElementById('exportType').value;
            const cols = Object.entries(data.colleges || {})
                .map(([k,v]) => `<strong>${escHtml(k)}</strong>: ${v} file${v!==1?'s':''}`)
                .join(' &nbsp;·&nbsp; ');
            let html = `<strong>${data.count}</strong> file${data.count!==1?'s':''} will be exported.`;
            if (cols) html += `<br><span style="font-size:.78rem;opacity:.9;">${cols}</span>`;
            if (type === 'zip') html += `<br><span style="font-size:.75rem;opacity:.75;">📁 ZIP will be organized as: College Name / Department / Program / files</span>`;
            showExportInfo(html, false);
        })
        .catch(() => showExportInfo('Could not fetch count. You can still proceed with the download.', false))
        .finally(() => { btn.disabled = false; btn.textContent = 'Preview Count'; });
}

function resetExportBtn() {
    const btn = document.getElementById('exportDownloadBtn');
    btn.disabled = false;
    btn.innerHTML = `<svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg> Download Export`;
}

function doExport() {
    const ids = [...document.querySelectorAll('.college-check:checked')].map(c => c.value);
    if (ids.length === 0) { showExportInfo('Please select at least one college to export.', true); return; }

    const type = document.getElementById('exportType').value;
    const url  = buildExportUrl(type);
    const btn  = document.getElementById('exportDownloadBtn');
    btn.disabled = true;
    btn.textContent = type === 'zip' ? '⏳ Building ZIP…' : '⏳ Preparing CSV…';

    if (type === 'csv') {
        // Direct link download — most reliable for CSV
        const a = document.createElement('a');
        a.href = url;
        a.download = 'documents_export_' + new Date().toISOString().slice(0,10) + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(() => { resetExportBtn(); closeModal('exportModal'); }, 1500);
        return;
    }

    // ZIP: use fetch to detect server-side errors, then trigger download
    fetch(url, { credentials: 'same-origin' })
        .then(res => {
            const ct = res.headers.get('Content-Type') || '';
            if (!res.ok || ct.includes('application/json')) {
                return res.json().then(d => { throw new Error(d.error || 'Export failed.'); });
            }
            if (!ct.includes('zip') && !ct.includes('octet-stream')) {
                // Unexpected content — try to read as text for error message
                return res.text().then(t => { throw new Error(t.substring(0, 200) || 'Unexpected server response.'); });
            }
            return res.blob();
        })
        .then(blob => {
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'QA_Documents_' + new Date().toISOString().slice(0,10) + '.zip';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(a.href), 10000);
            showExportInfo('✅ Download started! Files are organized by College → Department → Program inside the ZIP.', false);
            setTimeout(() => closeModal('exportModal'), 2000);
        })
        .catch(err => showExportInfo('❌ ' + (err.message || 'Export failed. Please try again.'), true))
        .finally(() => resetExportBtn());
}
</script>
</body>
</html>
