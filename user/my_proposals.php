<?php
session_start();
include '../database/db_connect.php';
$active_nav = 'my_proposals';
$me = (int)$_SESSION['user_id'];

/* ── POST ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'submit_proposal') {
        $title   = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $desc    = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $type    = in_array($_POST['type']??'',['research','capstone','thesis','internship','other'])?$_POST['type']:'research';
        $prog_id = (int)($_POST['program_id'] ?? 0);
        $yr      = $conn->real_escape_string(trim($_POST['academic_year'] ?? ''));
        $deadline= !empty($_POST['deadline']) ? "'".$conn->real_escape_string($_POST['deadline'])."'" : 'NULL';
        $status  = isset($_POST['save_draft']) ? 'draft' : 'submitted';
        $sub_at  = $status === 'submitted' ? ',submitted_at=NOW()' : '';

        // Generate proposal code
        $code = 'PROP-'.strtoupper(substr(uniqid(),7));

        // Handle file upload
        $fp = ''; $fn = ''; $fs = 0;
        if (!empty($_FILES['proposal_file']['name']) && $_FILES['proposal_file']['error'] === 0) {
            $orig  = basename($_FILES['proposal_file']['name']);
            $ext   = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            $allowed = ['pdf','doc','docx','ppt','pptx','zip'];
            if (in_array($ext, $allowed)) {
                $fn = 'PROP-'.strtoupper(substr(uniqid(),7)).'.'.$ext;
                $fp = $fn;
                $fs = $_FILES['proposal_file']['size'];
                move_uploaded_file($_FILES['proposal_file']['tmp_name'], '../uploads/'.$fn);
            }
        }

        if ($title) {
            $conn->query("INSERT INTO proposals (proposal_code,title,description,type,submitted_by,program_id,academic_year,deadline,status,file_path,file_name,file_size$sub_at) VALUES ('$code','$title','$desc','$type',$me,".($prog_id?$prog_id:'NULL').",'$yr',$deadline,'$status'".($fp?",'$fp','$fn',$fs":",NULL,NULL,0").")");
            $pid = $conn->insert_id;
            // Notify admin
            $conn->query("SELECT id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active' LIMIT 5");
            $m2 = $conn->real_escape_string("New proposal submitted: $title");
            $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
            if ($admins) while($adm=$admins->fetch_assoc())
                $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$adm['id']},'document_submitted','New Proposal','$m2')");
        }
        $msg = $status==='draft' ? 'Proposal saved as draft.' : 'Proposal submitted successfully!';
        header("Location: my_proposals.php?msg=".urlencode($msg)."&typ=s"); exit;
    }

    if ($act === 'delete_proposal') {
        $pid = (int)$_POST['proposal_id'];
        $conn->query("UPDATE proposals SET deleted_at=NOW() WHERE id=$pid AND submitted_by=$me AND status='draft'");
        header("Location: my_proposals.php?msg=".urlencode('Draft deleted.')."&typ=s"); exit;
    }
    header("Location: my_proposals.php"); exit;
}

$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

$per_page_opts=[10,25,50];
$_pp = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$per_page = in_array($_pp, $per_page_opts) ? $_pp : 10;
$page=max(1,(int)($_GET['page']??1));
$total_my=(int)$conn->query("SELECT COUNT(*) c FROM proposals WHERE submitted_by=$me AND deleted_at IS NULL")->fetch_assoc()['c'];
$my_pages=max(1,(int)ceil($total_my / max(1,$per_page)));$page=min($page,$my_pages);$offset=($page-1)*$per_page;

$my_proposals=$conn->query("SELECT p.*,prog.program_name,adv.name adviser_name FROM proposals p LEFT JOIN programs prog ON prog.id=p.program_id LEFT JOIN users adv ON adv.id=p.adviser_id WHERE p.submitted_by=$me AND p.deleted_at IS NULL ORDER BY p.created_at DESC LIMIT $per_page OFFSET $offset");
$programs=$conn->query("SELECT id,program_name,program_code FROM programs WHERE status='active' ORDER BY program_name");
$notif_count=(int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];
$status_colors=['draft'=>'#6b7a8d','submitted'=>'#2563a8','under_review'=>'#7c3aed','revision_requested'=>'#d97706','approved'=>'#059669','rejected'=>'#dc2626'];
?>
<!DOCTYPE html><html lang="en"><head><title>My Proposals — QA System</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">My Proposals</div>
    <div class="topbar-right">
      <button class="btn btn-primary btn-sm" onclick="openModal('submitModal')">+ Submit Proposal</button>
      <a href="notifications.php" class="notif-btn">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
      </a>
    </div>
  </div>
  <div class="page-body">
    <?php if($message):?>
    <div class="alert alert-<?=$msg_type?>"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><?=$message?></div>
    <?php endif;?>
    <div class="page-header">
      <div><h1 class="page-heading">My Proposals</h1><p class="page-subheading"><?=$total_my?> proposals submitted</p></div>
      <div style="display:flex;gap:8px;">
        <select onchange="window.location='my_proposals.php?per_page='+this.value+'&page=1'" style="padding:8px 28px 8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
          <?php foreach([10,25,50] as $opt):?><option value="<?=$opt?>"<?=$per_page==$opt?' selected':''?>><?=$opt?> / page</option><?php endforeach;?>
        </select>
      </div>
    </div>

    <?php if(!$my_proposals||$my_proposals->num_rows===0):?>
    <div class="card"><div class="empty-state">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/></svg>
      <p>No proposals yet. Click <strong>Submit Proposal</strong> to get started!</p>
    </div></div>
    <?php else:?>
    <div style="display:flex;flex-direction:column;gap:12px;">
    <?php while($prop=$my_proposals->fetch_assoc()): $sc=$status_colors[$prop['status']]??'#6b7a8d';?>
    <div class="card" style="border-left:4px solid <?=$sc?>;">
      <div style="padding:16px 20px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <span class="badge" style="background:<?=$sc?>18;color:<?=$sc?>;"><?=ucwords(str_replace('_',' ',$prop['status']))?></span>
              <span class="badge" style="background:#f0f4f9;color:var(--muted);"><?=ucfirst($prop['type'])?></span>
              <?php if($prop['proposal_code']):?><span style="font-size:.72rem;color:var(--muted);"><?=$prop['proposal_code']?></span><?php endif;?>
            </div>
            <h3 style="font-size:.95rem;font-weight:700;margin-bottom:4px;"><?=htmlspecialchars($prop['title'])?></h3>
            <?php if($prop['description']):?><p style="font-size:.83rem;color:var(--muted);margin-bottom:6px;"><?=htmlspecialchars(mb_substr($prop['description'],0,120))?><?=mb_strlen($prop['description'])>120?'…':''?></p><?php endif;?>
            <?php if($prop['review_comments']&&in_array($prop['status'],['revision_requested','rejected','approved'])):?>
            <div style="background:<?=$sc?>10;border:1px solid <?=$sc?>30;border-radius:8px;padding:10px 12px;margin-top:8px;font-size:.82rem;">
              <strong>Feedback:</strong> <?=htmlspecialchars($prop['review_comments'])?>
            </div>
            <?php endif;?>
            <div style="font-size:.75rem;color:var(--muted);margin-top:8px;">
              <?=$prop['program_name']?htmlspecialchars($prop['program_name']).' · ':''?>
              Submitted <?=$prop['submitted_at']?date('M j, Y',strtotime($prop['submitted_at'])):date('M j, Y',strtotime($prop['created_at']))?>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            <?php if($prop['file_path']):?>
            <a href="../uploads/<?=htmlspecialchars($prop['file_path'])?>" target="_blank" class="btn btn-ghost btn-sm">View File</a>
            <?php endif;?>
            <?php if($prop['status']==='draft'):?>
            <form method="POST" class="swal-confirm-form" data-title="Delete draft?" data-icon="warning" data-confirm="Delete" data-cls="qa-btn-red">
              <input type="hidden" name="form_action" value="delete_proposal">
              <input type="hidden" name="proposal_id" value="<?=$prop['id']?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
            <?php endif;?>
          </div>
        </div>
      </div>
    </div>
    <?php endwhile;?>
    </div>
    <!-- Pagination -->
    <?php if($my_pages>1||$total_my>10):?>
    <div class="pagination" style="padding:20px 0;">
      <a href="my_proposals.php?per_page=<?=$per_page?>&page=1" class="page-link<?=$page<=1?' disabled':''?>">«</a>
      <a href="my_proposals.php?per_page=<?=$per_page?>&page=<?=max(1,$page-1)?>" class="page-link<?=$page<=1?' disabled':''?>">‹</a>
      <?php for($p=max(1,$page-2);$p<=min($my_pages,$page+2);$p++):?>
      <a href="my_proposals.php?per_page=<?=$per_page?>&page=<?=$p?>" class="page-link<?=$p==$page?' active':''?>"><?=$p?></a>
      <?php endfor;?>
      <a href="my_proposals.php?per_page=<?=$per_page?>&page=<?=min($my_pages,$page+1)?>" class="page-link<?=$page>=$my_pages?' disabled':''?>">›</a>
      <a href="my_proposals.php?per_page=<?=$per_page?>&page=<?=$my_pages?>" class="page-link<?=$page>=$my_pages?' disabled':''?>">»</a>
    </div>
    <?php endif;?>
    <?php endif;?>
  </div>
</div>

<!-- SUBMIT MODAL -->
<div class="modal-overlay" id="submitModal">
  <div class="modal" style="max-width:600px;">
    <div class="modal-header"><span class="modal-title">Submit Proposal</span>
      <button type="button" class="modal-close" onclick="closeModal('submitModal')">✕</button>
    </div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="form_action" value="submit_proposal">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
        <div class="field"><label>Proposal Title *</label><input type="text" name="title" required class="form-input" placeholder="Full title of your proposal"></div>
        <div class="field"><label>Description / Abstract</label><textarea name="description" rows="3" class="form-input" placeholder="Brief description or abstract…" style="resize:vertical;"></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Type *</label>
            <select name="type" class="form-input" required>
              <option value="research">Research Paper</option>
              <option value="capstone">Capstone Project</option>
              <option value="thesis">Thesis</option>
              <option value="internship">Internship Report</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="field"><label>Program</label>
            <select name="program_id" class="form-input">
              <option value="">— Select Program —</option>
              <?php $programs->data_seek(0); while($pr=$programs->fetch_assoc()):?>
              <option value="<?=$pr['id']?>"><?=htmlspecialchars($pr['program_name'])?></option>
              <?php endwhile;?>
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Academic Year</label><input type="text" name="academic_year" placeholder="e.g. 2025-2026" class="form-input"></div>
          <div class="field"><label>Deadline (optional)</label><input type="date" name="deadline" class="form-input"></div>
        </div>
        <div class="field">
          <label>Attach File (PDF, DOC, DOCX, PPT, ZIP)</label>
          <input type="file" name="proposal_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.zip" class="form-input" style="padding:6px;">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('submitModal')">Cancel</button>
        <button type="submit" name="save_draft" class="btn btn-ghost">💾 Save Draft</button>
        <button type="submit" class="btn btn-primary">📤 Submit for Review</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>