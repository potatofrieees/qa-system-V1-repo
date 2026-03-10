<?php
session_start();
include '../database/db_connect.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$active_nav = 'proposals';
$me = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['form_action'] ?? '';
    if ($act === 'submit_proposal') {
        $title   = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $desc    = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $type    = in_array($_POST['type']??'',['research','capstone','thesis','internship','other'])?$_POST['type']:'other';
        $prog_id = (int)($_POST['program_id'] ?? 0) ?: 'NULL';
        $ay      = $conn->real_escape_string(trim($_POST['academic_year'] ?? ''));
        $code    = 'PROP-'.strtoupper(substr(md5(uniqid()),0,6));
        if ($title) {
            $conn->query("INSERT INTO proposals (proposal_code,title,description,type,submitted_by,program_id,academic_year,status,submitted_at) VALUES ('$code','$title','$desc','$type',$me,$prog_id,'$ay','submitted',NOW())");
            $pid = $conn->insert_id;
            if ($pid) {
                $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
                $nm=$conn->real_escape_string($_SESSION['name']??'');
                if ($admins) while ($a=$admins->fetch_assoc())
                    $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$a['id']},'system','New Proposal Submitted','$nm submitted a new proposal: ".addslashes($title)."')");
            }
            header("Location: proposals.php?msg=".urlencode("Proposal submitted! Code: $code")."&typ=s"); exit;
        }
    }
    if ($act === 'delete_proposal') {
        $pid = (int)$_POST['proposal_id'];
        $conn->query("UPDATE proposals SET deleted_at=NOW() WHERE id=$pid AND submitted_by=$me AND status='draft'");
        header("Location: proposals.php?msg=".urlencode("Proposal deleted.")."&typ=s"); exit;
    }
    header("Location: proposals.php"); exit;
}

$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ']??'')==='e'?'error':'success';
$today    = date('Y-m-d');
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];

$props_q = $conn->query("SELECT p.*,prog.program_name,u2.name reviewer_name FROM proposals p LEFT JOIN programs prog ON prog.id=p.program_id LEFT JOIN users u2 ON u2.id=p.reviewed_by WHERE p.submitted_by=$me AND p.deleted_at IS NULL ORDER BY p.created_at DESC LIMIT 100");
$programs_q = $conn->query("SELECT id,program_name FROM programs ORDER BY program_name");

$sc=['draft'=>['#f9fafb','#374151','#d1d5db'],'submitted'=>['#eff6ff','#1e40af','#93c5fd'],'under_review'=>['#fefce8','#92400e','#fde68a'],'revision_requested'=>['#fff7ed','#9a3412','#fdba74'],'approved'=>['#ecfdf5','#065f46','#6ee7b7'],'rejected'=>['#fef2f2','#991b1b','#fca5a5'],'archived'=>['#f9fafb','#374151','#d1d5db']];
?>
<!DOCTYPE html><html lang="en"><head><title>My Proposals — Student Portal</title><?php include 'head.php';?></head>
<body><?php include 'sidebar.php';?>
<div class="main-content">
<div class="topbar">
  <button class="sidebar-toggle" id="sidebar-toggle"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg></button>
  <div class="topbar-title">My Proposals</div>
  <div class="topbar-right">
    <button class="btn btn-primary btn-sm" onclick="openModal('newProposalModal')">+ Submit Proposal</button>
    <a href="notifications.php" class="notif-btn"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg><?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?></a>
  </div>
</div>
<div class="page-body">
<?php if($message):?><div class="alert alert-<?=$msg_type?>"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><?=$message?></div><?php endif;?>
<div class="page-header" style="margin-bottom:18px;"><div><h1 class="page-heading">My Proposals</h1><p class="page-subheading">Submit and track your research, thesis, and capstone proposals</p></div><button class="btn btn-primary" onclick="openModal('newProposalModal')">+ Submit New Proposal</button></div>
<div class="card"><div class="table-wrapper"><table>
<thead><tr><th>Code</th><th>Title</th><th>Type</th><th>Program</th><th>Status</th><th>Submitted</th><th>Reviewer Notes</th></tr></thead>
<tbody>
<?php if(!$props_q||$props_q->num_rows===0):?><tr><td colspan="7"><div class="empty-state"><p>No proposals yet. Click <strong>Submit New Proposal</strong> to get started.</p></div></td></tr>
<?php else: while($p=$props_q->fetch_assoc()): $c=$sc[$p['status']]??['#f9fafb','#374151','#d1d5db'];?>
<tr>
  <td><code style="font-size:.75rem;background:#f3f4f6;padding:2px 6px;border-radius:4px;"><?=htmlspecialchars($p['proposal_code']??'—')?></code></td>
  <td style="max-width:200px;"><div style="font-weight:600;font-size:.85rem;"><?=htmlspecialchars($p['title'])?></div><?php if($p['description']):?><div style="font-size:.72rem;color:var(--muted);margin-top:2px;"><?=htmlspecialchars(mb_substr($p['description'],0,60))?></div><?php endif;?></td>
  <td><span style="font-size:.75rem;background:#f0f4f9;color:var(--muted);padding:2px 8px;border-radius:12px;"><?=ucfirst($p['type'])?></span></td>
  <td style="font-size:.8rem;color:var(--muted);"><?=htmlspecialchars($p['program_name']??'—')?></td>
  <td><span style="background:<?=$c[0]?>;color:<?=$c[1]?>;border:1.5px solid <?=$c[2]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?=ucfirst(str_replace('_',' ',$p['status']))?></span></td>
  <td style="font-size:.8rem;color:var(--muted);"><?=$p['submitted_at']?date('M j, Y',strtotime($p['submitted_at'])):'—'?></td>
  <td style="font-size:.78rem;max-width:160px;color:var(--muted);"><?=htmlspecialchars($p['review_comments']??'—')?><?php if($p['reviewer_name']):?><div style="font-size:.7rem;color:#2563a8;margin-top:2px;">by <?=htmlspecialchars($p['reviewer_name'])?></div><?php endif;?></td>
</tr>
<?php endwhile; endif;?>
</tbody></table></div></div>
</div></div>
<!-- New Proposal Modal -->
<div class="modal-overlay" id="newProposalModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><span class="modal-title">Submit New Proposal</span><button type="button" class="modal-close" onclick="closeModal('newProposalModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="form_action" value="submit_proposal">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:13px;">
        <div class="field"><label>Title *</label><input type="text" name="title" required class="form-input" placeholder="Your proposal title"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Type</label><select name="type" class="form-input"><option value="research">Research</option><option value="capstone">Capstone</option><option value="thesis">Thesis</option><option value="internship">Internship</option><option value="other">Other</option></select></div>
          <div class="field"><label>Academic Year</label><input type="text" name="academic_year" class="form-input" placeholder="e.g. 2025-2026"></div>
        </div>
        <div class="field"><label>Program</label><select name="program_id" class="form-input"><option value="">— Select Program —</option><?php if($programs_q) while($pg=$programs_q->fetch_assoc()):?><option value="<?=$pg['id']?>"><?=htmlspecialchars($pg['program_name'])?></option><?php endwhile;?></select></div>
        <div class="field"><label>Description / Abstract</label><textarea name="description" rows="4" class="form-input" placeholder="Brief description of your proposal…" style="resize:vertical;"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('newProposalModal')">Cancel</button><button type="submit" class="btn btn-primary">Submit Proposal</button></div>
    </form>
  </div>
</div>
</body></html>
