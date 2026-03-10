<?php
session_start();
include '../database/db_connect.php';
$active_nav = 'proposals';
$me   = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';
    if ($act === 'review') {
        $pid      = (int)$_POST['proposal_id'];
        $decision = in_array($_POST['decision']??'',['approved','revision_requested','rejected']) ? $_POST['decision'] : null;
        $comments = $conn->real_escape_string(trim($_POST['comments']??''));
        if ($pid && $decision) {
            $new_status = $decision;
            $conn->query("UPDATE proposals SET status='$new_status',reviewed_by=$me,reviewed_at=NOW(),review_comments='$comments' WHERE id=$pid");
            $round = (int)$conn->query("SELECT COUNT(*) c FROM proposal_reviews WHERE proposal_id=$pid")->fetch_assoc()['c'] + 1;
            $conn->query("INSERT INTO proposal_reviews (proposal_id,reviewer_id,decision,comments,round) VALUES ($pid,$me,'$decision','$comments',$round)");
            // Notify student
            $prop = $conn->query("SELECT submitted_by,title FROM proposals WHERE id=$pid")->fetch_assoc();
            $label = ['approved'=>'Approved','revision_requested'=>'Revision Requested','rejected'=>'Rejected'][$decision];
            $msg = $conn->real_escape_string("Your proposal \"{$prop['title']}\" has been $label.");
            $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$prop['submitted_by']},'review_decision','Proposal $label','$msg')");
        }
        header("Location: proposals.php?msg=".urlencode("Decision recorded.")."&typ=s"); exit;
    }
    header("Location: proposals.php"); exit;
}

$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

$status_f = $_GET['status'] ?? '';
$search   = $conn->real_escape_string(trim($_GET['search'] ?? ''));
$w = ["p.deleted_at IS NULL"];
if ($status_f) $w[] = "p.status='{$conn->real_escape_string($status_f)}'";
if ($search)   $w[] = "(p.title LIKE '%$search%' OR u.name LIKE '%$search%' OR p.proposal_code LIKE '%$search%')";

$per_page_opts = [10,25,50];
$_pp=isset($_GET['per_page'])?(int)$_GET['per_page']:0; $per_page=in_array($_pp,$per_page_opts)?$_pp:25;
$page     = max(1,(int)($_GET['page']??1));
$wstr     = implode(' AND ',$w);
$total_p  = (int)$conn->query("SELECT COUNT(*) c FROM proposals p LEFT JOIN users u ON u.id=p.submitted_by WHERE $wstr")->fetch_assoc()['c'];
$p_pages  = max(1,(int)ceil($total_p/$per_page));
$page     = min($page,$p_pages);
$offset   = ($page-1)*$per_page;

$props = $conn->query("SELECT p.*,u.name submitter_name,u.email submitter_email,r.role_label submitter_role,prog.program_name,adv.name adviser_name
    FROM proposals p
    LEFT JOIN users u ON u.id=p.submitted_by
    LEFT JOIN roles r ON r.id=u.role_id
    LEFT JOIN programs prog ON prog.id=p.program_id
    LEFT JOIN users adv ON adv.id=p.adviser_id
    WHERE $wstr ORDER BY p.created_at DESC LIMIT $per_page OFFSET $offset");

$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];
$status_counts = [];
$sc = $conn->query("SELECT status, COUNT(*) c FROM proposals WHERE deleted_at IS NULL GROUP BY status");
if ($sc) while ($r=$sc->fetch_assoc()) $status_counts[$r['status']] = (int)$r['c'];

$status_colors = ['draft'=>'#6b7a8d','submitted'=>'#2563a8','under_review'=>'#7c3aed','revision_requested'=>'#d97706','approved'=>'#059669','rejected'=>'#dc2626','archived'=>'#374151'];

function pp_url(array $extra=[]): string {
    global $status_f,$search,$per_page,$page;
    $p=['status'=>$status_f,'search'=>$search,'per_page'=>$per_page,'page'=>$page];
    foreach($extra as $k=>$v) $p[$k]=$v;
    $p=array_filter($p,function($v){return $v!==''&&$v!==0&&$v!==null;});
    return 'proposals.php'.($p?'?'.http_build_query($p):'');
}
?>
<!DOCTYPE html><html lang="en"><head><title>Proposals — QA System</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">Proposals</div>
    <div class="topbar-right">
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
      <div>
        <h1 class="page-heading">Proposals</h1>
        <p class="page-subheading"><?=$total_p?> proposals · project &amp; research submissions (all users)</p>
      </div>
    </div>

    <!-- Status summary cards -->
    <div class="stats-grid" style="margin-bottom:20px;">
      <?php foreach(['submitted'=>'Submitted','under_review'=>'Under Review','approved'=>'Approved','revision_requested'=>'For Revision','rejected'=>'Rejected'] as $sk=>$sl):
        $cnt=$status_counts[$sk]??0;$sc=$status_colors[$sk];?>
      <a href="proposals.php?status=<?=$sk?>" class="scard-prog" style="border-color:<?=$sc?>30;text-decoration:none;">
        <div class="n" style="color:<?=$sc?>;"><?=$cnt?></div>
        <div class="l"><?=$sl?></div>
      </a>
      <?php endforeach;?>
    </div>

    <!-- Filter bar -->
    <div class="card" style="margin-bottom:0;border-bottom-left-radius:0;border-bottom-right-radius:0;">
      <div class="filter-bar">
        <input type="text" class="search-input" id="ppSearch" placeholder="Search title, submitter, code…" value="<?=htmlspecialchars($search)?>">
        <select id="ppStatus">
          <option value="">All Statuses</option>
          <?php foreach(['submitted','under_review','revision_requested','approved','rejected'] as $s):?>
          <option value="<?=$s?>"<?=$status_f===$s?' selected':''?>><?=ucwords(str_replace('_',' ',$s))?></option>
          <?php endforeach;?>
        </select>
        <select id="ppPerPage" style="padding:8px 28px 8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
          <?php foreach([10,25,50] as $opt):?>
          <option value="<?=$opt?>"<?=$per_page==$opt?' selected':''?>><?=$opt?> / page</option>
          <?php endforeach;?>
        </select>
        <button class="btn btn-outline btn-sm" onclick="applyPPFilter()">Filter</button>
      </div>
    </div>
    <div class="card" style="border-top:none;border-top-left-radius:0;border-top-right-radius:0;margin-top:0;">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Proposal</th><th>Submitted By</th><th>Type</th><th>Program</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if(!$props||$props->num_rows===0):?>
          <tr><td colspan="7"><div class="empty-state"><p>No proposals found.</p></div></td></tr>
          <?php else: while($prop=$props->fetch_assoc()):
            $sc_val = $status_colors[$prop['status']] ?? '#6b7a8d';
          ?>
          <tr>
            <td style="max-width:200px;">
              <div style="font-weight:600;font-size:.85rem;"><?=htmlspecialchars(mb_substr($prop['title'],0,50))?></div>
              <?php if($prop['proposal_code']):?><div class="text-sm text-muted"><?=htmlspecialchars($prop['proposal_code'])?></div><?php endif;?>
            </td>
            <td class="text-sm"><div style="font-weight:600;"><?=htmlspecialchars($prop['submitter_name']??'—')?></div><?php if($prop['submitter_role']):?><span style="font-size:.7rem;background:#f0f4f9;color:var(--muted);padding:1px 7px;border-radius:12px;"><?=htmlspecialchars($prop['submitter_role'])?></span><?php endif;?></td>
            <td><span class="badge" style="background:#f0f4f9;color:var(--muted);"><?=ucfirst($prop['type'])?></span></td>
            <td class="text-sm text-muted"><?=htmlspecialchars($prop['program_name']??'—')?></td>
            <td><span class="badge" style="background:<?=$sc_val?>18;color:<?=$sc_val?>;"><?=ucwords(str_replace('_',' ',$prop['status']))?></span></td>
            <td class="text-sm text-muted"><?=$prop['submitted_at']?date('M j, Y',strtotime($prop['submitted_at'])):'—'?></td>
            <td>
              <div class="row-actions">
                <?php if(in_array($prop['status'],['submitted','under_review'])):?>
                <button class="btn btn-primary btn-sm" onclick="openReview(<?=json_encode(['id'=>$prop['id'],'title'=>$prop['title']])?>)">Review</button>
                <?php endif;?>
                <?php if($prop['file_path']):?>
                <a href="../uploads/<?=htmlspecialchars($prop['file_path'])?>" target="_blank" class="btn btn-ghost btn-sm">View File</a>
                <?php endif;?>
              </div>
            </td>
          </tr>
          <?php endwhile; endif;?>
          </tbody>
        </table>
      </div>
      <?php if($p_pages>1||$total_p>10):?>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 24px;border-top:1px solid var(--border);">
        <div style="font-size:.82rem;color:var(--muted);">Showing <?=min($offset+1,$total_p)?>–<?=min($offset+$per_page,$total_p)?> of <?=$total_p?> proposals</div>
        <div class="pagination" style="padding:0;">
          <a href="<?=pp_url(['page'=>1])?>" class="page-link<?=$page<=1?' disabled':''?>">«</a>
          <a href="<?=pp_url(['page'=>max(1,$page-1)])?>" class="page-link<?=$page<=1?' disabled':''?>">‹</a>
          <?php for($p=max(1,$page-2);$p<=min($p_pages,$page+2);$p++):?>
          <a href="<?=pp_url(['page'=>$p])?>" class="page-link<?=$p==$page?' active':''?>"><?=$p?></a>
          <?php endfor;?>
          <a href="<?=pp_url(['page'=>min($p_pages,$page+1)])?>" class="page-link<?=$page>=$p_pages?' disabled':''?>">›</a>
          <a href="<?=pp_url(['page'=>$p_pages])?>" class="page-link<?=$page>=$p_pages?' disabled':''?>">»</a>
        </div>
      </div>
      <?php endif;?>
    </div>
  </div>
</div>

<!-- REVIEW MODAL -->
<div class="modal-overlay" id="reviewModal">
  <div class="modal" style="max-width:520px;">
    <div class="modal-header"><span class="modal-title">Review Proposal</span>
      <button type="button" class="modal-close" onclick="closeModal('reviewModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="review">
      <input type="hidden" name="proposal_id" id="rvPropId">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
        <p style="font-size:.9rem;font-weight:600;" id="rvPropTitle"></p>
        <div class="field"><label>Decision *</label>
          <select name="decision" class="form-input" required>
            <option value="">— Select —</option>
            <option value="approved">✅ Approved</option>
            <option value="revision_requested">🔄 Request Revision</option>
            <option value="rejected">❌ Rejected</option>
          </select>
        </div>
        <div class="field"><label>Comments</label>
          <textarea name="comments" rows="4" class="form-input" placeholder="Feedback for the submitter…" style="resize:vertical;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('reviewModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Decision</button>
      </div>
    </form>
  </div>
</div>

<script>
function applyPPFilter(){
    window.location.href='proposals.php?search='+encodeURIComponent(document.getElementById('ppSearch').value)
        +'&status='+document.getElementById('ppStatus').value
        +'&per_page='+document.getElementById('ppPerPage').value+'&page=1';
}
document.getElementById('ppSearch').addEventListener('keydown',e=>{if(e.key==='Enter')applyPPFilter();});
function openReview(p){
    document.getElementById('rvPropId').value=p.id;
    document.getElementById('rvPropTitle').textContent=p.title;
    openModal('reviewModal');
}
</script>
</body></html>