<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login();
require_once '../mail/emails.php';
$active_nav = 'documents';
$uid = (int)$_SESSION['user_id'];
$highlight_id = (int)($_GET['highlight'] ?? 0); // doc to scroll/highlight from notification

/* ── POST → Redirect (PRG) ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action']??'';
    $doc_id = (int)($_POST['doc_id']??0);

    if ($action==='submit') {
        $r = $conn->query("UPDATE documents SET status='submitted',submitted_at=NOW()
                           WHERE id=$doc_id AND uploaded_by=$uid AND status='draft'");
        if ($r && $conn->affected_rows>0) {
            $row = $conn->query("SELECT title FROM documents WHERE id=$doc_id")->fetch_assoc();
            $te  = $conn->real_escape_string($row['title']??'');
            $qa  = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                                 WHERE r.role_key IN ('qa_director','qa_staff') AND u.deleted_at IS NULL AND u.status='active'");
            // Notify + email QA staff
            $prog_name = $conn->query("SELECT p.program_name FROM documents d LEFT JOIN programs p ON p.id=d.program_id WHERE d.id=$doc_id")->fetch_assoc()['program_name']??'';
            while ($q=$qa->fetch_assoc()) {
                $qi=(int)$q['id'];
                $conn->query("INSERT INTO notifications (user_id,type,priority,title,message,link)
                              VALUES ($qi,'document_submitted','normal','New Document Submitted',
                              'A new document \"$te\" has been submitted for review.','reviews_queue.php?tab=submitted')");
                $quser = $conn->query("SELECT email,name FROM users WHERE id=$qi")->fetch_assoc();
                if ($quser) { mail_document_submitted($quser['email'], $quser['name'], $row['title'], $_SESSION['name']??'', $prog_name); }
            }
            $_ip_d = $conn->real_escape_string($_SERVER['REMOTE_ADDR']??'');
            $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address) VALUES ($uid,'DOC_SUBMITTED','documents',$doc_id,'Document submitted for review','$_ip_d')");
            $m='Document submitted for review!'; $t='s';
        } else { $m='Cannot submit — document must be in Draft status.'; $t='e'; }

    } elseif ($action==='resubmit') {
        // Allowed after revision_requested - bump version number
        $r = $conn->query("UPDATE documents SET status='submitted', submitted_at=NOW(),
                           current_version = current_version + 1
                           WHERE id=$doc_id AND uploaded_by=$uid AND status='revision_requested'");
        if ($r && $conn->affected_rows>0) {
            $row = $conn->query("SELECT title, current_version FROM documents WHERE id=$doc_id")->fetch_assoc();
            $te  = $conn->real_escape_string($row['title']??'');
            $new_ver = (int)($row['current_version'] ?? 1);
            $qa  = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id
                                 WHERE r.role_key IN ('qa_director','qa_staff') AND u.deleted_at IS NULL AND u.status='active'");
            while ($q=$qa->fetch_assoc()) {
                $qi=(int)$q['id'];
                $conn->query("INSERT INTO notifications (user_id,type,priority,title,message,link)
                              VALUES ($qi,'document_submitted','high','Revised Document Resubmitted',
                              'Revised document \"$te\" (v$new_ver) has been resubmitted for review.','reviews_queue.php?tab=submitted')");
                $quser = $conn->query("SELECT email,name FROM users WHERE id=$qi")->fetch_assoc();
                if ($quser) { mail_document_resubmitted($quser['email'], $quser['name'], $row['title'], $_SESSION['name']??''); }
            }
            $m='Document resubmitted for review!'; $t='s';
        } else { $m='Cannot resubmit — document must be in Revision Requested status.'; $t='e'; }

    } elseif ($action==='edit_draft') {
        // Edit metadata while still a draft
        $title = $conn->real_escape_string(trim($_POST['title']??''));
        $desc  = $conn->real_escape_string(trim($_POST['description']??''));
        $prog  = (int)($_POST['program_id']??0);
        $area  = (int)($_POST['area_id']??0);
        $lv    = (int)($_POST['accreditation_level_id']??0);
        $ay    = $conn->real_escape_string(trim($_POST['academic_year']??''));
        $sem   = in_array($_POST['semester']??'',['1st','2nd','Summer'])?$_POST['semester']:'1st';
        if (!$title) { $m='Title is required.'; $t='e'; }
        else {
            $conn->query("UPDATE documents SET title='$title',description='$desc',
                          program_id=".($prog?$prog:'NULL').",area_id=".($area?$area:'NULL').",
                          accreditation_level_id=".($lv?$lv:'NULL').",academic_year='$ay',semester='$sem'
                          WHERE id=$doc_id AND uploaded_by=$uid AND status='draft'");
            $m='Document updated.'; $t='s';
        }

    } elseif ($action==='replace_file') {
        // Re-upload file for revision_requested docs
        $check = $conn->query("SELECT id,document_code,status FROM documents WHERE id=$doc_id AND uploaded_by=$uid");
        $doc   = $check ? $check->fetch_assoc() : null;
        if (!$doc || !in_array($doc['status'],['draft','revision_requested'])) {
            $m='Cannot replace file for this document.'; $t='e';
        } elseif (empty($_FILES['document_file']['name'])||$_FILES['document_file']['error']!==UPLOAD_ERR_OK) {
            $m='Please select a valid file.'; $t='e';
        } else {
            $allowed=['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg'];
            $ext = strtolower(pathinfo($_FILES['document_file']['name'],PATHINFO_EXTENSION));
            if (!in_array($ext,$allowed)) { $m='File type not allowed.'; $t='e'; }
            elseif ($_FILES['document_file']['size']>20*1024*1024) { $m='File too large (max 20MB).'; $t='e'; }
            else {
                $safe = $doc['document_code'].'.'.$ext;
                $dest = '../uploads/'.$safe;
                if (!is_dir('../uploads/')) mkdir('../uploads/',0755,true);
                if (move_uploaded_file($_FILES['document_file']['tmp_name'],$dest)) {
                    $fn = $conn->real_escape_string($_FILES['document_file']['name']);
                    $ft = $conn->real_escape_string($_FILES['document_file']['type']);
                    $fs = (int)$_FILES['document_file']['size'];
                    $fp = $conn->real_escape_string($dest);
                    // Bump version and update main document record
                    $conn->query("UPDATE documents SET file_name='$fn',file_path='$fp',file_type='$ft',file_size=$fs,
                                  current_version = current_version + 1
                                  WHERE id=$doc_id AND uploaded_by=$uid");
                    // Insert new version record
                    $ver_row = $conn->query("SELECT current_version FROM documents WHERE id=$doc_id")->fetch_assoc();
                    $new_ver = (int)($ver_row['current_version'] ?? 1);
                    $conn->query("INSERT INTO document_versions
                                  (document_id, version_number, file_name, file_path, file_type, file_size, uploaded_by, remarks)
                                  VALUES ($doc_id, $new_ver, '$fn', '$fp', '$ft', $fs, $uid, 'File replaced by uploader')");
                    $m='File replaced successfully.'; $t='s';
                } else { $m='Upload failed — check server permissions.'; $t='e'; }
            }
        }

    } elseif ($action==='delete') {
        $r = $conn->query("UPDATE documents SET deleted_at=NOW() WHERE id=$doc_id AND uploaded_by=$uid AND status='draft'");
        if ($r && $conn->affected_rows>0) { $m='Document deleted.'; $t='s'; }
        else { $m='Cannot delete — only Draft documents can be deleted.'; $t='e'; }

    } else { $m='Unknown action.'; $t='e'; }

    $keep_pp = $per_page_keep = isset($_POST['per_page']) ? (int)$_POST['per_page'] : 10;
    header("Location: my_documents.php?msg=".urlencode($m)."&typ=$t".($keep_pp != 10 ? "&per_page=$keep_pp" : "")); exit;
}

/* ── Flash ────────────────────────────────────────────────────  */
$message  = htmlspecialchars(urldecode($_GET['msg']??''));
$msg_type = ($_GET['typ']??'')==='e'?'error':'success';

/* ── Data ─────────────────────────────────────────────────────  */
$status_f = $conn->real_escape_string($_GET['status']??'');
$per_page_raw = (int)($_GET['per_page'] ?? 10);
$per_page = in_array($per_page_raw, [10, 50, 100]) ? $per_page_raw : 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;
$where    = "d.uploaded_by=$uid AND d.deleted_at IS NULL";
if ($status_f) $where .= " AND d.status='$status_f'";

// If highlight_id is set and no explicit page given, find which page the doc is on
if ($highlight_id && !isset($_GET['page'])) {
    $pos_q = $conn->query("
        SELECT COUNT(*) c FROM documents d
        WHERE $where AND d.updated_at > (SELECT updated_at FROM documents WHERE id=$highlight_id AND uploaded_by=$uid AND deleted_at IS NULL LIMIT 1)
    ");
    if ($pos_q) {
        $pos = (int)$pos_q->fetch_assoc()['c']; // number of docs before this one
        $target_page = max(1, (int)floor($pos / $per_page) + 1);
        if ($target_page !== $page) {
            $page   = $target_page;
            $offset = ($page - 1) * $per_page;
        }
    }
}

$docs = $conn->query("
    SELECT d.*, p.program_name, al.level_name, a.area_name,
           (SELECT COUNT(*) FROM document_reviews dr WHERE dr.document_id=d.id) review_count
    FROM documents d
    LEFT JOIN programs p ON p.id=d.program_id
    LEFT JOIN accreditation_levels al ON al.id=d.accreditation_level_id
    LEFT JOIN areas a ON a.id=d.area_id
    WHERE $where ORDER BY d.updated_at DESC LIMIT $per_page OFFSET $offset
");
$total_filtered = (int)$conn->query("SELECT COUNT(*) c FROM documents d WHERE $where")->fetch_assoc()['c'];
$total_pages_d  = max(1, ceil($total_filtered / $per_page));

// Count by status for tab badges
$counts = [];
$cr = $conn->query("SELECT status,COUNT(*) c FROM documents WHERE uploaded_by=$uid AND deleted_at IS NULL GROUP BY status");
while ($cx=$cr->fetch_assoc()) $counts[$cx['status']]=(int)$cx['c'];
$total_docs = array_sum($counts);

$programs = $conn->query("SELECT id,program_name,program_code FROM programs WHERE status='active' ORDER BY program_name");
$areas    = $conn->query("SELECT id,area_name,area_code FROM areas ORDER BY sort_order,area_name");
$levels   = $conn->query("SELECT id,level_name FROM accreditation_levels ORDER BY level_order");

$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html><html lang="en"><head><title>My Documents — QA Portal</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">My Documents</div>
    <div class="topbar-right">
      <a href="notifications.php" class="notif-btn">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
      </a>
      <a href="upload.php" class="btn btn-primary btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        New Document
      </a>
    </div>
  </div>

  <div class="page-body">
    <?php if($message):?>
    <div class="alert alert-<?=$msg_type?>">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
      <?=$message?>
    </div>
    <?php endif;?>

    <div class="page-header">
      <div><h1 class="page-heading">My Documents</h1><p class="page-subheading"><?=$total_docs?> total documents</p></div>
    </div>

    <!-- Status tabs with counts -->
    <div style="display:flex;gap:6px;margin-bottom:20px;flex-wrap:wrap;">
      <a href="my_documents.php<?= $per_page!=10 ? '?per_page='.$per_page : '' ?>" class="btn <?=!$status_f?'btn-primary':'btn-ghost'?> btn-sm">All (<?=$total_docs?>)</a>
      <?php foreach(['draft','submitted','under_review','revision_requested','approved','rejected'] as $st):
        $cnt=$counts[$st]??0; if($cnt===0&&$status_f!==$st) continue; ?>
      <a href="my_documents.php?status=<?=$st?><?= $per_page!=10 ? '&per_page='.$per_page : '' ?>" class="btn <?=$status_f===$st?'btn-primary':'btn-ghost'?> btn-sm">
        <?=ucwords(str_replace('_',' ',$st))?><?php if($cnt):?> (<?=$cnt?>)<?php endif;?>
      </a>
      <?php endforeach;?>
    </div>

    <div class="card">
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <span style="font-size:.8rem;color:var(--muted);">Showing <?= min($offset+1,$total_filtered) ?>–<?= min($offset+$per_page,$total_filtered) ?> of <strong><?= $total_filtered ?></strong></span>
        <div style="display:flex;gap:6px;align-items:center;">
          <span style="font-size:.8rem;color:var(--muted);">Per page:</span>
          <?php foreach([10,50,100] as $pp): ?>
          <a href="?<?= http_build_query(array_merge($_GET,['per_page'=>$pp,'page'=>1])) ?>"
             class="btn btn-sm <?= $per_page==$pp?'btn-primary':'btn-ghost' ?>"><?=$pp?></a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Title / Code</th><th>Program</th><th>Level</th><th>Status</th><th>Deadline</th><th>Updated</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if(!$docs||$docs->num_rows===0):?>
          <tr><td colspan="7">
            <div class="empty-state">
              <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
              <p>No documents yet. <a href="upload.php" style="color:var(--primary-light);">Upload your first document →</a></p>
            </div>
          </td></tr>
          <?php else: while($d=$docs->fetch_assoc()):
            $now = time();
            $dl  = $d['deadline'] ? strtotime($d['deadline']) : null;
            $dl_overdue = $dl && $dl < $now && !in_array($d['status'],['approved','archived']);
            $dl_soon    = $dl && $dl >= $now && $dl < strtotime('+7 days');
          ?>
          <tr id="doc-row-<?=$d['id']?>" class="<?=$highlight_id && $highlight_id == $d['id'] ? 'doc-highlight' : ''?>">
            <td style="max-width:200px;">
              <div style="font-weight:500;font-size:.875rem;"><?=htmlspecialchars(mb_substr($d['title'],0,50))?></div>
              <?php if($d['document_code']):?><div class="text-sm text-muted"><?=htmlspecialchars($d['document_code'])?></div><?php endif;?>
            </td>
            <td class="text-sm"><?=htmlspecialchars($d['program_name']??'—')?></td>
            <td class="text-sm"><?=htmlspecialchars($d['level_name']??'—')?></td>
            <td>
              <span class="badge badge-<?=htmlspecialchars($d['status'])?>"><?=ucwords(str_replace('_',' ',$d['status']))?></span>
              <?php if($d['status']==='revision_requested'):?>
              <div style="font-size:.7rem;color:var(--status-revision);margin-top:3px;font-weight:600;">⚠ Action needed</div>
              <?php endif;?>
            </td>
            <td class="text-sm" style="<?=$dl_overdue?'color:var(--status-rejected);font-weight:700;':($dl_soon?'color:var(--status-revision);font-weight:600;':'color:var(--muted);')?>">
              <?php if($d['deadline']): ?>
                <?= date('M d, Y', strtotime($d['deadline'])) ?>
                <?php if($dl_overdue): ?><br><span style="font-size:.68rem;">OVERDUE</span><?php elseif($dl_soon): ?><br><span style="font-size:.68rem;">SOON</span><?php endif; ?>
              <?php else: ?>&#8212;<?php endif; ?>
            </td>
            <td class="text-sm text-muted"><?=date('M d, Y',strtotime($d['updated_at']))?></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap;">

                <?php // ── View file ──
                if ($d['file_path'] && file_exists($d['file_path'])):?>
                <a href="view_file.php?id=<?=$d['id']?>" target="_blank" class="btn btn-ghost btn-sm" title="View uploaded file">
                  <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                  View
                </a>
                <?php endif;?>

                <?php // ── Edit draft ──
                if ($d['status']==='draft'):?>
                <button class="btn btn-ghost btn-sm" onclick='openEditDraft(<?=json_encode([
                  "id"=>$d["id"],"title"=>$d["title"],"description"=>$d["description"],
                  "program_id"=>$d["program_id"],"area_id"=>$d["area_id"],
                  "accreditation_level_id"=>$d["accreditation_level_id"],
                  "academic_year"=>$d["academic_year"],"semester"=>$d["semester"]
                ])?>)'>Edit</button>
                <?php endif;?>

                <?php // ── Replace file (draft or revision) ──
                if (in_array($d['status'],['draft','revision_requested'])):?>
                <button class="btn btn-ghost btn-sm" onclick='openReplaceFile(<?=$d['id']?>,<?=json_encode($d['title'])?>)'>Replace File</button>
                <?php endif;?>

                <?php // ── Submit draft ──
                if ($d['status']==='draft'):?>
                <form method="POST" class="swal-confirm-form" data-title="Submit for Review?" data-text="Your document will be sent to QA for review." data-icon="question" data-confirm="Yes, Submit" data-cls="qa-btn-purple">
                  <input type="hidden" name="action" value="submit">
                  <input type="hidden" name="doc_id" value="<?=$d['id']?>">
                  <input type="hidden" name="per_page" value="<?=$per_page?>">
                  <button type="submit" class="btn btn-primary btn-sm">Submit</button>
                </form>
                <?php endif;?>

                <?php // ── Resubmit after revision ──
                if ($d['status']==='revision_requested'):?>
                <form method="POST" class="swal-confirm-form" data-title="Resubmit for Review?" data-text="Your revised document will be sent back to QA for review." data-icon="question" data-confirm="Yes, Resubmit" data-cls="qa-btn-purple">
                  <input type="hidden" name="action" value="resubmit">
                  <input type="hidden" name="doc_id" value="<?=$d['id']?>">
                  <input type="hidden" name="per_page" value="<?=$per_page?>">
                  <button type="submit" class="btn btn-primary btn-sm">Resubmit</button>
                </form>
                <?php endif;?>

                <?php // ── View reviews ──
                if ((int)$d['review_count']>0):?>
                <button class="btn btn-ghost btn-sm" onclick="viewReviews(<?=$d['id']?>,<?=json_encode($d['title'])?>)">
                  Reviews (<?=(int)$d['review_count']?>)
                </button>
                <?php endif;?>

                <?php // ── Delete draft only ──
                if ($d['status']==='draft'):?>
                <form method="POST" class="swal-confirm-form" data-title="Delete Document?" data-text="This document will be permanently deleted. This action cannot be undone." data-icon="warning" data-confirm="Yes, Delete" data-cls="qa-btn-red">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="doc_id" value="<?=$d['id']?>">
                  <input type="hidden" name="per_page" value="<?=$per_page?>">
                  <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                </form>
                <?php endif;?>

              </div>
            </td>
          </tr>
          <?php endwhile; endif;?>
          </tbody>
        </table>
      </div>
      <?php if ($total_pages_d > 1): ?>
      <div class="pagination">
        <?php
        $base_params = $_GET;
        unset($base_params['page']);
        $base_q = $base_params ? '&' . http_build_query($base_params) : '';
        ?>
        <a href="?page=1<?= $base_q ?>" class="page-link <?= $page<=1?'disabled':'' ?>" title="First">«</a>
        <a href="?page=<?= max(1,$page-1) ?><?= $base_q ?>" class="page-link <?= $page<=1?'disabled':'' ?>">‹ Prev</a>
        <?php
        $start = max(1, $page - 2);
        $end   = min($total_pages_d, $page + 2);
        if ($start > 1) echo '<span class="page-link disabled">…</span>';
        for ($p = $start; $p <= $end; $p++):
        ?>
        <a href="?page=<?= $p ?><?= $base_q ?>" class="page-link <?= $p===$page?'active':'' ?>"><?= $p ?></a>
        <?php endfor;
        if ($end < $total_pages_d) echo '<span class="page-link disabled">…</span>';
        ?>
        <a href="?page=<?= min($total_pages_d,$page+1) ?><?= $base_q ?>" class="page-link <?= $page>=$total_pages_d?'disabled':'' ?>">Next ›</a>
        <a href="?page=<?= $total_pages_d ?><?= $base_q ?>" class="page-link <?= $page>=$total_pages_d?'disabled':'' ?>" title="Last">»</a>
      </div>
      <p class="pagination-info">Page <?= $page ?> of <?= $total_pages_d ?> &nbsp;·&nbsp; <?= $total_filtered ?> documents</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- EDIT DRAFT MODAL -->
<div class="modal-overlay" id="editDraftModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header"><span class="modal-title">Edit Draft Document</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <form method="POST"><input type="hidden" name="action" value="edit_draft"><input type="hidden" name="doc_id" id="ed_id">
      <div class="modal-body">
        <div class="form-row"><div class="field"><label>Title *</label><input type="text" name="title" id="ed_title" required></div></div>
        <div class="form-row"><div class="field"><label>Description</label><textarea name="description" id="ed_desc" rows="3"></textarea></div></div>
        <div class="form-row cols-2">
          <div class="field"><label>Program</label>
            <select name="program_id" id="ed_prog"><option value="0">None</option>
              <?php $programs->data_seek(0); while($p=$programs->fetch_assoc()):?>
              <option value="<?=$p['id']?>">[<?=htmlspecialchars($p['program_code'])?>] <?=htmlspecialchars($p['program_name'])?></option>
              <?php endwhile;?>
            </select>
          </div>
          <div class="field"><label>Accreditation Area</label>
            <select name="area_id" id="ed_area"><option value="0">None</option>
              <?php $areas->data_seek(0); while($a=$areas->fetch_assoc()):?>
              <option value="<?=$a['id']?>"><?=htmlspecialchars(($a['area_code']?'['.$a['area_code'].'] ':'').$a['area_name'])?></option>
              <?php endwhile;?>
            </select>
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="field"><label>Accreditation Level</label>
            <select name="accreditation_level_id" id="ed_level"><option value="0">None</option>
              <?php $levels->data_seek(0); while($l=$levels->fetch_assoc()):?>
              <option value="<?=$l['id']?>"><?=htmlspecialchars($l['level_name'])?></option>
              <?php endwhile;?>
            </select>
          </div>
          <div class="field"><label>Semester</label>
            <select name="semester" id="ed_sem">
              <option value="1st">1st Semester</option><option value="2nd">2nd Semester</option><option value="Summer">Summer</option>
            </select>
          </div>
        </div>
        <div class="form-row"><div class="field"><label>Academic Year</label><input type="text" name="academic_year" id="ed_ay" placeholder="e.g. 2024-2025"></div></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- REPLACE FILE MODAL -->
<div class="modal-overlay" id="replaceFileModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><span class="modal-title">Replace File</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="replace_file"><input type="hidden" name="doc_id" id="rf_id">
      <div class="modal-body">
        <p class="text-sm text-muted" id="rf_title" style="margin-bottom:16px;"></p>
        <div class="field">
          <label>New File *</label>
          <input type="file" name="document_file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg">
          <span class="text-sm text-muted" style="margin-top:4px;display:block;">PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX — max 20MB</span>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Upload New File</button>
      </div>
    </form>
  </div>
</div>

<!-- VIEW REVIEWS MODAL -->
<div class="modal-overlay" id="reviewsModal">
  <div class="modal" style="max-width:600px;">
    <div class="modal-header">
      <span class="modal-title" id="reviewsTitle">Document Reviews</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body" id="reviewsBody"><p class="text-sm text-muted">Loading…</p></div>
  </div>
</div>

<script>
function openEditDraft(d){
  document.getElementById('ed_id').value=d.id;
  document.getElementById('ed_title').value=d.title||'';
  document.getElementById('ed_desc').value=d.description||'';
  document.getElementById('ed_prog').value=d.program_id||'0';
  document.getElementById('ed_area').value=d.area_id||'0';
  document.getElementById('ed_level').value=d.accreditation_level_id||'0';
  document.getElementById('ed_sem').value=d.semester||'1st';
  document.getElementById('ed_ay').value=d.academic_year||'';
  document.getElementById('editDraftModal').classList.add('open');
}
function openReplaceFile(id,title){
  document.getElementById('rf_id').value=id;
  document.getElementById('rf_title').textContent='Document: '+title;
  document.getElementById('replaceFileModal').classList.add('open');
}
function viewReviews(id,title){
  document.getElementById('reviewsTitle').textContent=title;
  document.getElementById('reviewsBody').innerHTML='<p class="text-sm text-muted">Loading…</p>';
  document.getElementById('reviewsModal').classList.add('open');
  fetch('get_reviews.php?doc_id='+encodeURIComponent(id))
    .then(r=>r.text())
    .then(h=>{document.getElementById('reviewsBody').innerHTML=h||'<p class="text-sm text-muted">No reviews yet.</p>';})
    .catch(()=>{document.getElementById('reviewsBody').innerHTML='<p class="text-sm text-muted">Could not load reviews.</p>';});
}
</script>

<!-- File Viewer Modal -->
<div class="modal-overlay" id="fileViewerModal" style="z-index:1100;">
    <div class="modal" style="max-width:860px;width:95vw;height:90vh;display:flex;flex-direction:column;">
        <div class="modal-header">
            <span class="modal-title" id="fileViewerTitle">Document Preview</span>
            <div style="display:flex;gap:8px;align-items:center;">
                <a id="fileViewerDownload" href="#" class="btn btn-outline btn-sm">
                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg> Download
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
<script>
function openFileViewer(doc) {
    const url = 'view_file.php?id=' + doc.id;
    const ext = (doc.file_name || '').split('.').pop().toLowerCase();
    document.getElementById('fileViewerTitle').textContent = doc.title || 'Document Preview';
    document.getElementById('fileViewerDownload').href = url + '&download=1';
    document.getElementById('fileViewerNewTab').href = url;
    const body = document.getElementById('fileViewerBody');
    body.innerHTML = '';
    if (ext === 'pdf') {
        const iframe = document.createElement('iframe');
        iframe.src = url;
        iframe.style.cssText = 'width:100%;height:100%;border:none;';
        body.appendChild(iframe);
    } else if (['png','jpg','jpeg','gif','webp'].includes(ext)) {
        const img = document.createElement('img');
        img.src = url;
        img.style.cssText = 'max-width:100%;max-height:100%;object-fit:contain;';
        body.appendChild(img);
    } else {
        body.innerHTML = '<div style="text-align:center;color:white;padding:40px;"><svg viewBox="0 0 20 20" fill="currentColor" style="width:64px;height:64px;margin:0 auto 16px;display:block;opacity:.5;"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg><p style="margin-bottom:20px;">Preview not available for .' + ext + ' files</p><a href="' + url + '&download=1" class="btn btn-primary">Download File</a></div>';
    }
    if(typeof openModal==='function') openModal('fileViewerModal');
    else { document.getElementById('fileViewerModal').classList.add('open'); }
}

// ── Highlight & scroll to document from notification ─────────
<?php if ($highlight_id): ?>
(function(){
    const row = document.getElementById('doc-row-<?= $highlight_id ?>');
    if (row) {
        row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Remove after animation
        setTimeout(() => row.classList.remove('doc-highlight'), 4000);
    }
})();
<?php endif; ?>

// ── SweetAlert confirmations for edit/upload modals ──────────
(function() {
    var modals = [
        { id: 'editDraftModal',   label: 'Save Changes?',   html: 'Save your edits to this document?',    confirmText: 'Yes, Save'   },
        { id: 'replaceFileModal', label: 'Upload New File?', html: 'Replace the current file with the new upload?', confirmText: 'Yes, Upload' },
    ];
    modals.forEach(function(m) {
        var modal = document.getElementById(m.id);
        if (!modal) return;
        var form = modal.querySelector('form');
        if (!form || form._swalWired) return;
        form._swalWired = true;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.type = 'button';
            btn.addEventListener('click', function() {
                Swal.fire({
                    title: m.label, html: m.html, icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: m.confirmText,
                    cancelButtonText: 'Cancel',
                    reverseButtons: true, focusCancel: true,
                    customClass: { popup: 'qa-popup', confirmButton: 'qa-btn qa-btn-purple', cancelButton: 'qa-btn qa-btn-gray' },
                    buttonsStyling: false,
                }).then(function(r) {
                    if (r.isConfirmed) HTMLFormElement.prototype.submit.call(form);
                });
            });
        }
    });
})();
</script>
<style>
@keyframes docPulse {
    0%   { background: #dbeafe; box-shadow: inset 4px 0 0 #2563eb; }
    60%  { background: #eff6ff; box-shadow: inset 4px 0 0 #93c5fd; }
    100% { background: transparent; box-shadow: none; }
}
.doc-highlight {
    animation: docPulse 4s ease forwards;
}
</style>
</body></html>
