<?php
session_start();
include '../database/db_connect.php';
$active_nav = 'announcements';

$me   = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

/* ── POST Actions ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'create') {
        $title   = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $body    = $conn->real_escape_string(trim($_POST['body'] ?? ''));
        $type    = in_array($_POST['type']??'',['general','urgent','deadline','event']) ? $_POST['type'] : 'general';
        $target  = in_array($_POST['target']??'',['all','admin','faculty','student']) ? $_POST['target'] : 'all';
        $pinned  = isset($_POST['pinned']) ? 1 : 0;
        $expires = !empty($_POST['expires_at']) ? "'".$conn->real_escape_string($_POST['expires_at'])."'" : 'NULL';
        if ($title && $body) {
            $conn->query("INSERT INTO announcements (title,body,type,target,pinned,expires_at,created_by) VALUES ('$title','$body','$type','$target',$pinned,$expires,$me)");
        }
        header("Location: announcements.php?msg=".urlencode('Announcement created.')."&typ=s"); exit;
    }
    if ($act === 'edit') {
        $id     = (int)($_POST['ann_id'] ?? 0);
        $title  = $conn->real_escape_string(trim($_POST['title'] ?? ''));
        $body   = $conn->real_escape_string(trim($_POST['body'] ?? ''));
        $type   = in_array($_POST['type']??'',['general','urgent','deadline','event']) ? $_POST['type'] : 'general';
        $target = in_array($_POST['target']??'',['all','admin','faculty','student']) ? $_POST['target'] : 'all';
        $pinned = isset($_POST['pinned']) ? 1 : 0;
        $active = isset($_POST['is_active']) ? 1 : 0;
        $expires = !empty($_POST['expires_at']) ? "'".$conn->real_escape_string($_POST['expires_at'])."'" : 'NULL';
        if ($id) $conn->query("UPDATE announcements SET title='$title',body='$body',type='$type',target='$target',pinned=$pinned,is_active=$active,expires_at=$expires WHERE id=$id");
        header("Location: announcements.php?msg=".urlencode('Announcement updated.')."&typ=s"); exit;
    }
    if ($act === 'delete') {
        $id = (int)($_POST['ann_id'] ?? 0);
        if ($id) $conn->query("DELETE FROM announcements WHERE id=$id");
        header("Location: announcements.php?msg=".urlencode('Announcement deleted.')."&typ=s"); exit;
    }
    if ($act === 'toggle') {
        $id = (int)($_POST['ann_id'] ?? 0);
        if ($id) $conn->query("UPDATE announcements SET is_active = NOT is_active WHERE id=$id");
        header("Location: announcements.php"); exit;
    }
    header("Location: announcements.php"); exit;
}

$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

/* ── Fetch ────────────────────────────────────────────────── */
$per_page_opts = [10, 25, 50];
$_pp=isset($_GET['per_page'])?(int)$_GET['per_page']:0; $per_page=in_array($_pp,$per_page_opts)?$_pp:10;
$page     = max(1,(int)($_GET['page']??1));
$total_ann = (int)$conn->query("SELECT COUNT(*) c FROM announcements")->fetch_assoc()['c'];
$ann_pages = max(1,(int)ceil($total_ann/$per_page));
$page      = min($page,$ann_pages);
$offset    = ($page-1)*$per_page;

$anns = $conn->query("SELECT a.*, u.name creator_name FROM announcements a LEFT JOIN users u ON u.id=a.created_by ORDER BY a.pinned DESC, a.created_at DESC LIMIT $per_page OFFSET $offset");
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];

$type_colors = ['general'=>'#2563a8','urgent'=>'#dc2626','deadline'=>'#d97706','event'=>'#059669'];
$type_icons  = ['general'=>'📢','urgent'=>'🚨','deadline'=>'⏰','event'=>'📅'];
?>
<!DOCTYPE html><html lang="en"><head><title>Announcements — QA System</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">Announcements</div>
    <div class="topbar-right">
      <button class="btn btn-primary btn-sm" onclick="openModal('createModal')">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M10 5a1 1 0 011 1v3h3a1 1 0 110 2h-3v3a1 1 0 11-2 0v-3H6a1 1 0 110-2h3V6a1 1 0 011-1z" clip-rule="evenodd"/></svg>
        New Announcement
      </button>
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
        <h1 class="page-heading">Announcements</h1>
        <p class="page-subheading"><?=$total_ann?> announcements · broadcast to users</p>
      </div>
      <div style="display:flex;gap:8px;">
        <select onchange="window.location=this.value" style="padding:8px 28px 8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
          <?php foreach([10,25,50] as $opt):?>
          <option value="announcements.php?per_page=<?=$opt?>&page=1"<?=$per_page==$opt?' selected':''?>><?=$opt?> / page</option>
          <?php endforeach;?>
        </select>
      </div>
    </div>

    <?php if(!$anns||$anns->num_rows===0):?>
    <div class="card"><div class="empty-state"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M18 3a1 1 0 00-1.447-.894L8.763 6H5a3 3 0 000 6h.28l1.771 5.316A1 1 0 008 18h1a1 1 0 001-1v-4.382l6.553 3.276A1 1 0 0018 15V3z"/></svg><p>No announcements yet. Create the first one!</p></div></div>
    <?php else:?>
    <div style="display:flex;flex-direction:column;gap:12px;">
    <?php while($ann=$anns->fetch_assoc()):
      $tc = $type_colors[$ann['type']] ?? '#2563a8';
      $ti = $type_icons[$ann['type']] ?? '📢';
      $expired = $ann['expires_at'] && strtotime($ann['expires_at']) < time();
    ?>
    <div class="card" style="border-left:4px solid <?=$tc?>;<?=!$ann['is_active']||$expired?'opacity:.6;':''?>">
      <div style="padding:16px 20px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
              <span style="font-size:1.1rem;"><?=$ti?></span>
              <?php if($ann['pinned']):?><span style="font-size:.72rem;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:12px;font-weight:600;">📌 Pinned</span><?php endif;?>
              <span style="font-size:.72rem;background:<?=$tc?>18;color:<?=$tc?>;padding:2px 8px;border-radius:12px;font-weight:600;text-transform:capitalize;"><?=$ann['type']?></span>
              <span style="font-size:.72rem;background:#f0f4f9;color:var(--muted);padding:2px 8px;border-radius:12px;">For: <?=ucfirst($ann['target'])?></span>
              <?php if(!$ann['is_active']):?><span style="font-size:.72rem;background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:12px;">Inactive</span><?php endif;?>
              <?php if($expired):?><span style="font-size:.72rem;background:#fef2f2;color:#dc2626;padding:2px 8px;border-radius:12px;">Expired</span><?php endif;?>
            </div>
            <h3 style="font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:6px;"><?=htmlspecialchars($ann['title'])?></h3>
            <p style="font-size:.85rem;color:var(--muted);line-height:1.6;margin-bottom:8px;"><?=nl2br(htmlspecialchars(mb_substr($ann['body'],0,200))).(mb_strlen($ann['body'])>200?'…':'')?></p>
            <div style="font-size:.75rem;color:var(--muted);">
              Posted by <strong><?=htmlspecialchars($ann['creator_name']??'—')?></strong>
              · <?=date('M j, Y g:i A', strtotime($ann['created_at']))?>
              <?php if($ann['expires_at']):?> · Expires: <?=date('M j, Y',strtotime($ann['expires_at']))?><?php endif;?>
            </div>
          </div>
          <div style="display:flex;gap:6px;flex-shrink:0;">
            <button class="btn btn-ghost btn-sm" onclick="editAnn(<?=json_encode($ann)?>)">Edit</button>
            <form method="POST" class="swal-confirm-form" data-title="Delete Announcement?" data-text="This cannot be undone." data-icon="warning" data-confirm="Delete" data-cls="qa-btn-red">
              <input type="hidden" name="form_action" value="delete">
              <input type="hidden" name="ann_id" value="<?=$ann['id']?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php endwhile;?>
    </div>

    <!-- Pagination -->
    <?php if($ann_pages>1||$total_ann>10):?>
    <div style="display:flex;align-items:center;justify-content:center;gap:4px;padding:20px 0;" class="pagination">
      <a href="announcements.php?per_page=<?=$per_page?>&page=1" class="page-link<?=$page<=1?' disabled':''?>">«</a>
      <a href="announcements.php?per_page=<?=$per_page?>&page=<?=max(1,$page-1)?>" class="page-link<?=$page<=1?' disabled':''?>">‹</a>
      <?php for($p=max(1,$page-2);$p<=min($ann_pages,$page+2);$p++):?>
      <a href="announcements.php?per_page=<?=$per_page?>&page=<?=$p?>" class="page-link<?=$p==$page?' active':''?>"><?=$p?></a>
      <?php endfor;?>
      <a href="announcements.php?per_page=<?=$per_page?>&page=<?=min($ann_pages,$page+1)?>" class="page-link<?=$page>=$ann_pages?' disabled':''?>">›</a>
      <a href="announcements.php?per_page=<?=$per_page?>&page=<?=$ann_pages?>" class="page-link<?=$page>=$ann_pages?' disabled':''?>">»</a>
    </div>
    <?php endif;?>
    <?php endif;?>
  </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header"><span class="modal-title">New Announcement</span>
      <button type="button" class="modal-close" onclick="closeModal('createModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="create">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
        <div class="field"><label>Title *</label><input type="text" name="title" required class="form-input" placeholder="Announcement title"></div>
        <div class="field"><label>Message *</label><textarea name="body" rows="4" required class="form-input" placeholder="Write your announcement here…" style="resize:vertical;"></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Type</label>
            <select name="type" class="form-input">
              <option value="general">📢 General</option>
              <option value="urgent">🚨 Urgent</option>
              <option value="deadline">⏰ Deadline</option>
              <option value="event">📅 Event</option>
            </select>
          </div>
          <div class="field"><label>Target Audience</label>
            <select name="target" class="form-input">
              <option value="all">All Users</option>
              <option value="admin">Admin / Staff</option>
              <option value="faculty">Faculty</option>
              <option value="student">Students</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Expiry Date (optional)</label><input type="datetime-local" name="expires_at" class="form-input"></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer;">
          <input type="checkbox" name="pinned"> <span>📌 Pin this announcement (show at top)</span>
        </label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Post Announcement</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-header"><span class="modal-title">Edit Announcement</span>
      <button type="button" class="modal-close" onclick="closeModal('editModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="edit">
      <input type="hidden" name="ann_id" id="editId">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
        <div class="field"><label>Title *</label><input type="text" name="title" id="editTitle" required class="form-input"></div>
        <div class="field"><label>Message *</label><textarea name="body" id="editBody" rows="4" required class="form-input" style="resize:vertical;"></textarea></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Type</label>
            <select name="type" id="editType" class="form-input">
              <option value="general">📢 General</option>
              <option value="urgent">🚨 Urgent</option>
              <option value="deadline">⏰ Deadline</option>
              <option value="event">📅 Event</option>
            </select>
          </div>
          <div class="field"><label>Target</label>
            <select name="target" id="editTarget" class="form-input">
              <option value="all">All Users</option>
              <option value="admin">Admin / Staff</option>
              <option value="faculty">Faculty</option>
              <option value="student">Students</option>
            </select>
          </div>
        </div>
        <div class="field"><label>Expiry Date</label><input type="datetime-local" name="expires_at" id="editExpires" class="form-input"></div>
        <div style="display:flex;gap:20px;">
          <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer;"><input type="checkbox" name="pinned" id="editPinned"> 📌 Pinned</label>
          <label style="display:flex;align-items:center;gap:8px;font-size:.85rem;cursor:pointer;"><input type="checkbox" name="is_active" id="editActive"> ✅ Active</label>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function editAnn(a) {
    document.getElementById('editId').value     = a.id;
    document.getElementById('editTitle').value  = a.title;
    document.getElementById('editBody').value   = a.body;
    document.getElementById('editType').value   = a.type;
    document.getElementById('editTarget').value = a.target;
    document.getElementById('editExpires').value= a.expires_at ? a.expires_at.replace(' ','T').slice(0,16) : '';
    document.getElementById('editPinned').checked = a.pinned == 1;
    document.getElementById('editActive').checked = a.is_active == 1;
    openModal('editModal');
}
</script>
</body></html>
