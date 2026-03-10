<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director','qa_staff']);
$active_nav = 'reviews';

/* ── POST: delete review (director only) → PRG ─────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $act = $_POST['form_action']??'';
    if ($act==='delete_review' && $_SESSION['role']==='qa_director') {
        $rid = (int)($_POST['review_id']??0);
        if ($rid) $conn->query("DELETE FROM document_reviews WHERE id=$rid");
        header("Location: reviews.php?msg=".urlencode('Review deleted.')."&typ=s"); exit;
    }
    header("Location: reviews.php"); exit;
}

$message  = htmlspecialchars(urldecode($_GET['msg']??''));
$msg_type = ($_GET['typ']??'')==='e'?'error':'success';

/* ── Filters ─────────────────────────────────────────────────── */
$dec_f  = in_array($_GET['decision']??'',['approved','revision_requested','rejected'])?$_GET['decision']:'';
$prog_f = (int)($_GET['program']??0);
$search = $conn->real_escape_string(trim($_GET['search']??''));

$where = ["1=1"];
if ($dec_f)  $where[] = "dr.decision='$dec_f'";
if ($prog_f) $where[] = "d.program_id=$prog_f";
if ($search) $where[] = "(d.title LIKE '%$search%' OR u.name LIKE '%$search%')";

/* ── Pagination ───────────────────────────────── */
$per_page_opts = [10, 25, 50, 100];
$per_page_raw = (int)($_GET['per_page'] ?? 0); $per_page = in_array($per_page_raw, $per_page_opts) ? $per_page_raw : 25;
$page     = max(1,(int)($_GET['page']??1));

$count_sql = "SELECT COUNT(*) c FROM document_reviews dr LEFT JOIN documents d ON d.id=dr.document_id LEFT JOIN users u ON u.id=dr.reviewer_id WHERE ".implode(' AND ',$where);
$count_q   = $conn->query($count_sql);
$total_records = $count_q ? (int)$count_q->fetch_assoc()['c'] : 0;
$total_pages   = max(1,(int)ceil($total_records / $per_page));
$page          = min($page, $total_pages);
$offset        = ($page-1)*$per_page;

$reviews = $conn->query("
    SELECT dr.*, d.title AS doc_title, d.id AS doc_id,
           u.name AS reviewer_name, r.role_label, p.program_name,
           up.name AS uploader_name
    FROM document_reviews dr
    LEFT JOIN documents d  ON d.id = dr.document_id
    LEFT JOIN users u      ON u.id = dr.reviewer_id
    LEFT JOIN roles r      ON r.id = u.role_id
    LEFT JOIN programs p   ON p.id = d.program_id
    LEFT JOIN users up     ON up.id = d.uploaded_by
    WHERE ".implode(' AND ',$where)."
    ORDER BY dr.reviewed_at DESC LIMIT $per_page OFFSET $offset
");

$programs    = $conn->query("SELECT id,program_name FROM programs WHERE status='active' ORDER BY program_name");

function rv_url(array $extra=[]): string {
    global $dec_f,$prog_f,$search,$per_page,$page;
    $p=['search'=>$search,'decision'=>$dec_f,'program'=>$prog_f,'per_page'=>$per_page,'page'=>$page];
    foreach($extra as $k=>$v) $p[$k]=$v;
    $p=array_filter($p, function($v){ return $v!==''&&$v!==0&&$v!==null; });
    return 'reviews.php'.($p?'?'.http_build_query($p):'');
}
$uid         = (int)$_SESSION['user_id'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
$is_director = ($_SESSION['role']??'')==='qa_director';
?>
<!DOCTYPE html><html lang="en"><head><title>Reviews — QA System</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Review History</div>
    <div class="topbar-right">
      <a href="notifications.php" class="notif-btn">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
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
    <p class="page-subheading" style="margin-bottom:16px;">All document review decisions — <?=$total_records?> records</p>

    <div class="card" style="margin-bottom:0;border-bottom-left-radius:0;border-bottom-right-radius:0;">
      <div class="filter-bar">
        <input type="text" class="search-input" id="rvSearch" placeholder="Search document or reviewer…" value="<?=htmlspecialchars($search)?>">
        <select id="rvDecision">
          <option value="">All Decisions</option>
          <option value="approved" <?=$dec_f==='approved'?'selected':''?>>Approved</option>
          <option value="revision_requested" <?=$dec_f==='revision_requested'?'selected':''?>>Revision Requested</option>
          <option value="rejected" <?=$dec_f==='rejected'?'selected':''?>>Rejected</option>
        </select>
        <select id="rvProgram">
          <option value="0">All Programs</option>
          <?php while($p=$programs->fetch_assoc()):?>
          <option value="<?=$p['id']?>" <?=$prog_f==$p['id']?'selected':''?>><?=htmlspecialchars($p['program_name'])?></option>
          <?php endwhile;?>
        </select>
        <button class="btn btn-outline btn-sm" onclick="applyRvFilter()">Filter</button>
      </div>
    </div>

    <div class="card" style="border-top:none;border-top-left-radius:0;border-top-right-radius:0;margin-top:0;">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Document</th><th>Uploaded By</th><th>Reviewer</th><th>Decision</th><th>Round</th><th>Date</th><th>Comments</th><?php if($is_director):?><th>Actions</th><?php endif;?></tr></thead>
          <tbody>
          <?php if(!$reviews||$reviews->num_rows===0):?>
          <tr><td colspan="<?=$is_director?8:7?>"><div class="empty-state"><p>No review records found.</p></div></td></tr>
          <?php else: while($rv=$reviews->fetch_assoc()):?>
          <tr>
            <td style="max-width:180px;">
              <div style="font-weight:500;font-size:.85rem;"><?=htmlspecialchars(mb_substr($rv['doc_title']??'—',0,40))?></div>
              <?php if($rv['program_name']):?><div class="text-sm text-muted"><?=htmlspecialchars($rv['program_name'])?></div><?php endif;?>
            </td>
            <td class="text-sm"><?=htmlspecialchars($rv['uploader_name']??'—')?></td>
            <td class="text-sm">
              <?=htmlspecialchars($rv['reviewer_name']??'—')?>
              <?php if($rv['role_label']):?><br><span class="text-sm text-muted" style="font-size:.7rem;"><?=htmlspecialchars($rv['role_label'])?></span><?php endif;?>
            </td>
            <td><span class="badge badge-<?=htmlspecialchars($rv['decision'])?>"><?=ucwords(str_replace('_',' ',$rv['decision']))?></span></td>
            <td class="text-sm text-muted">Round <?=(int)$rv['review_round']?></td>
            <td class="text-sm text-muted"><?=date('M d, Y',strtotime($rv['reviewed_at']))?><br><span style="font-size:.7rem;"><?=date('H:i',strtotime($rv['reviewed_at']))?></span></td>
            <td style="max-width:200px;">
              <?php if($rv['comments']):?>
              <button class="btn btn-ghost btn-sm" onclick="showComment(<?=json_encode($rv['comments'])?>)">View</button>
              <?php else:?><span class="text-sm text-muted">—</span><?php endif;?>
            </td>
            <?php if($is_director):?>
            <td>
              <form method="POST" class="swal-confirm-form" data-title="Delete Review Record?" data-text="This review record will be permanently deleted and cannot be recovered." data-icon="warning" data-confirm="Yes, Delete" data-cls="qa-btn-red">
                <input type="hidden" name="form_action" value="delete_review">
                <input type="hidden" name="review_id" value="<?=$rv['id']?>">
                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
              </form>
            </td>
            <?php endif;?>
          </tr>
          <?php endwhile; endif;?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <?php if($total_pages > 1 || $total_records > 10): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 24px;border-top:1px solid var(--border);">
        <div style="display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--muted);">
          Show
          <select onchange="window.location=this.value" style="padding:5px 28px 5px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:.82rem;">
            <?php foreach([10,25,50,100] as $opt): ?>
            <option value="<?=rv_url(['per_page'=>$opt,'page'=>1])?>"<?=$per_page==$opt?' selected':''?>><?=$opt?></option>
            <?php endforeach; ?>
          </select>
          per page &nbsp;·&nbsp; Showing <?=min($offset+1,$total_records)?>–<?=min($offset+$per_page,$total_records)?> of <?=$total_records?> records
        </div>
        <div class="pagination" style="padding:0;">
          <a href="<?=rv_url(['page'=>1])?>" class="page-link<?=$page<=1?' disabled':''?>">«</a>
          <a href="<?=rv_url(['page'=>max(1,$page-1)])?>" class="page-link<?=$page<=1?' disabled':''?>">‹</a>
          <?php
          $start=max(1,$page-2); $end=min($total_pages,$start+4);
          if($end-$start<4) $start=max(1,$end-4);
          for($p=$start;$p<=$end;$p++):
          ?><a href="<?=rv_url(['page'=>$p])?>" class="page-link<?=$p==$page?' active':''?>"><?=$p?></a><?php endfor; ?>
          <a href="<?=rv_url(['page'=>min($total_pages,$page+1)])?>" class="page-link<?=$page>=$total_pages?' disabled':''?>">›</a>
          <a href="<?=rv_url(['page'=>$total_pages])?>" class="page-link<?=$page>=$total_pages?' disabled':''?>">»</a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- View Comment Modal -->
<div class="modal-overlay" id="commentModal">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header"><span class="modal-title">Review Comments</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <div class="modal-body"><p id="commentText" style="font-size:.9rem;line-height:1.7;white-space:pre-wrap;"></p></div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Close</button>
    </div>
  </div>
</div>

<script>
function applyRvFilter(){
  window.location.href='reviews.php?search='+encodeURIComponent(document.getElementById('rvSearch').value)
    +'&decision='+document.getElementById('rvDecision').value
    +'&program='+document.getElementById('rvProgram').value;
}
document.getElementById('rvSearch').addEventListener('keydown',e=>{if(e.key==='Enter')applyRvFilter();});

// Live search with highlight
const rvInput = document.getElementById('rvSearch');
let rvDebounce = null;
const rvRows = document.querySelectorAll('tbody tr');
const rvOriginals = new Map();
rvRows.forEach(row => { rvOriginals.set(row, row.innerHTML); });

function rvLiveSearch() {
    const term = rvInput.value.trim().toLowerCase();
    rvRows.forEach(row => {
        if (!term) { if (rvOriginals.has(row)) row.innerHTML = rvOriginals.get(row); row.style.display=''; return; }
        const matches = row.textContent.toLowerCase().includes(term);
        row.style.display = matches ? '' : 'none';
        if (matches && rvOriginals.has(row)) {
            const div = document.createElement('tbody');
            div.innerHTML = rvOriginals.get(row);
            highlightNode(div, rvInput.value.trim());
            row.innerHTML = div.querySelector('tr') ? div.querySelector('tr').innerHTML : div.innerHTML;
        }
    });
}
function highlightNode(node, term) {
    if (node.nodeType === Node.TEXT_NODE) {
        const idx = node.textContent.toLowerCase().indexOf(term.toLowerCase());
        if (idx === -1) return;
        const before = document.createTextNode(node.textContent.slice(0, idx));
        const mark = document.createElement('mark');
        mark.style.cssText = 'background:#fef08a;border-radius:2px;padding:0 2px;font-weight:600;';
        mark.textContent = node.textContent.slice(idx, idx + term.length);
        const after = document.createTextNode(node.textContent.slice(idx + term.length));
        node.parentNode.insertBefore(before, node);
        node.parentNode.insertBefore(mark, node);
        node.parentNode.insertBefore(after, node);
        node.parentNode.removeChild(node);
        highlightNode(after, term);
    } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName !== 'SCRIPT') {
        Array.from(node.childNodes).forEach(c => highlightNode(c, term));
    }
}
rvInput.addEventListener('input', () => { clearTimeout(rvDebounce); rvDebounce = setTimeout(rvLiveSearch, 180); });
document.getElementById('rvDecision').addEventListener('change', applyRvFilter);
document.getElementById('rvProgram').addEventListener('change', applyRvFilter);
if (rvInput.value.trim()) rvLiveSearch();

function showComment(txt){
  document.getElementById('commentText').textContent=txt;
  document.getElementById('commentModal').classList.add('open');
}
</script>
</body></html>
