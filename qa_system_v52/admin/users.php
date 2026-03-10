<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director']);
require_once '../mail/emails.php';
$active_nav = 'users';

/* ── Current user's role level for permission checks ─────────
   Lower number = higher authority (e.g. Director = 1, Staff = 5)
   ─────────────────────────────────────────────────────────── */
$me       = (int)($_SESSION['user_id'] ?? 0);
if (!$me) { header('Location: ../login.php'); exit; }
$me_res   = $conn->query("SELECT r.level FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=$me AND u.deleted_at IS NULL");
$me_row   = $me_res ? $me_res->fetch_assoc() : null;
$me_level = (int)($me_row['level'] ?? 99);

// Helper closure: get role level of any user by id
$get_level = function(int $user_id) use ($conn): int {
    $r = $conn->query("SELECT r.level FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=$user_id");
    return $r ? (int)($r->fetch_assoc()['level'] ?? 99) : 99;
};

/* ── POST → Redirect (PRG pattern) ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act    = $_POST['form_action'] ?? '';
    $uid_p  = (int)($_POST['user_id'] ?? 0);
    $name   = $conn->real_escape_string(trim($_POST['name']        ?? ''));
    $email  = $conn->real_escape_string(trim($_POST['email']       ?? ''));
    $emp_id = $conn->real_escape_string(trim($_POST['employee_id'] ?? ''));
    $rid    = (int)($_POST['role_id']    ?? 0);
    $cid    = (int)($_POST['college_id'] ?? 0);
    $pid    = (int)($_POST['program_id'] ?? 0);
    $cs     = $cid ? $cid : 'NULL';
    $ps     = $pid > 0 ? $pid : 'NULL';

    if ($act === 'create') {
        $pw = $_POST['password'] ?? '';
        if (!$name || !$email || !$rid || strlen($pw) < 6) {
            $m = 'Name, email, role and password (min 6 chars) required.'; $t = 'e';
        } else {
            // Block creating a user with higher authority than yourself
            $new_role_level = (int)($conn->query("SELECT level FROM roles WHERE id=$rid")->fetch_assoc()['level'] ?? 99);
            if ($new_role_level < $me_level) {
                $m = 'You cannot create a user with a higher authority than yourself.'; $t = 'e';
                header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
            }
            $dup = $conn->query("SELECT id FROM users WHERE email='$email' AND deleted_at IS NULL");
            if ($dup && $dup->num_rows > 0) { $m = 'Email already in use.'; $t = 'e'; }
            else {
                $h = $conn->real_escape_string(password_hash($pw, PASSWORD_BCRYPT));
                $r = $conn->query("INSERT INTO users (employee_id,name,email,password,role_id,college_id,program_id,status)
                                   VALUES ('$emp_id','$name','$email','$h',$rid,$cs,$ps,'active')");
                if ($r) {
                    $new_uid = $conn->insert_id;
                    $rl = $conn->query("SELECT role_label FROM roles WHERE id=$rid")->fetch_assoc()['role_label'] ?? '';
                    mail_welcome($email, $name, $rl, $pw);
                    $_ip2 = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
                    $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address)
                                  VALUES ($me,'USER_CREATED','users',$new_uid,'Created user: $name ($email)','$_ip2')");
                    $m = 'User created and welcome email sent.'; $t = 's';
                } else { $m = 'DB Error: '.$conn->error; $t = 'e'; }
            }
        }

    } elseif ($act === 'update') {
        $stat = in_array($_POST['status'] ?? '', ['active','inactive','suspended']) ? $_POST['status'] : 'active';

        // Block editing a user with higher authority than yourself
        if ($uid_p && $get_level($uid_p) < $me_level) {
            $m = 'You do not have permission to edit a higher-ranked user.'; $t = 'e';
            header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
        }

        if (!$uid_p || !$name || !$email || !$rid) { $m = 'Name, email and role required.'; $t = 'e'; }
        else {
            $dup = $conn->query("SELECT id FROM users WHERE email='$email' AND id!=$uid_p AND deleted_at IS NULL");
            if ($dup && $dup->num_rows > 0) { $m = 'Another user already has that email.'; $t = 'e'; }
            else {
                $old_row = $conn->query("SELECT status FROM users WHERE id=$uid_p")->fetch_assoc();
                $conn->query("UPDATE users SET employee_id='$emp_id',name='$name',email='$email',
                              role_id=$rid,college_id=$cs,program_id=$ps,status='$stat' WHERE id=$uid_p");
                if ($old_row && $old_row['status'] !== $stat) {
                    $actor = $conn->real_escape_string($_SESSION['name'] ?? 'Administrator');
                    $status_messages = [
                        'active'    => "Your account has been activated by $actor. You can now log in to the system.",
                        'inactive'  => "Your account has been deactivated by $actor. You will not be able to log in until your account is reactivated. Contact your administrator if you believe this is an error.",
                        'suspended' => "Your account has been suspended by $actor. Access to the system has been temporarily blocked. Contact your administrator for more information.",
                    ];
                    $status_titles = [
                        'active'    => 'Account Activated',
                        'inactive'  => 'Account Deactivated',
                        'suspended' => 'Account Suspended',
                    ];
                    $status_priority = [
                        'active'    => 'high',
                        'inactive'  => 'urgent',
                        'suspended' => 'urgent',
                    ];
                    $notif_msg_raw = $status_messages[$stat] ?? "Your account status has been changed to: ".ucfirst($stat)." by $actor.";
                    $notif_msg   = $conn->real_escape_string($notif_msg_raw);
                    $notif_title = $conn->real_escape_string($status_titles[$stat] ?? 'Account Status Changed');
                    $notif_pri   = $status_priority[$stat] ?? 'high';
                    $conn->query("INSERT INTO notifications (user_id, type, title, message, priority, created_at)
                                  VALUES ($uid_p, 'system', '$notif_title', '$notif_msg', '$notif_pri', NOW())");
                    mail_account_status($email, $name, $stat);
                }
                $_ip3 = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
                $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address)
                              VALUES ($me,'USER_UPDATED','users',$uid_p,'Updated user #$uid_p: $name','$_ip3')");
                $m = 'User updated successfully.'; $t = 's';
            }
        }

    } elseif ($act === 'reset_password') {
        // Block resetting password of a higher-ranked user
        if ($uid_p && $get_level($uid_p) < $me_level) {
            $m = 'You do not have permission to reset the password of a higher-ranked user.'; $t = 'e';
            header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
        }
        $pw = $_POST['new_password'] ?? '';
        if (!$uid_p || strlen($pw) < 6) { $m = 'Password must be at least 6 characters.'; $t = 'e'; }
        else {
            $h = $conn->real_escape_string(password_hash($pw, PASSWORD_BCRYPT));
            $conn->query("UPDATE users SET password='$h', failed_attempts=0 WHERE id=$uid_p");
            $ur = $conn->query("SELECT email, name FROM users WHERE id=$uid_p")->fetch_assoc();
            if ($ur) { mail_admin_reset_password($ur['email'], $ur['name'], $pw); }
            $m = 'Password reset and notification email sent.'; $t = 's';
        }

    } elseif ($act === 'deactivate') {
        // Block deactivating a higher-ranked user
        if ($uid_p && $get_level($uid_p) < $me_level) {
            $m = 'You do not have permission to deactivate a higher-ranked user.'; $t = 'e';
            header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
        }
        if ($uid_p === $me) { $m = 'You cannot deactivate your own account.'; $t = 'e'; }
        else {
            $target = $conn->query("SELECT name, email, status FROM users WHERE id=$uid_p AND deleted_at IS NULL")->fetch_assoc();
            if (!$target) { $m = 'User not found.'; $t = 'e'; }
            elseif ($target['status'] === 'inactive') { $m = 'Account is already deactivated.'; $t = 'e'; }
            else {
                $conn->query("UPDATE users SET status='inactive' WHERE id=$uid_p");
                $actor = $conn->real_escape_string($_SESSION['name'] ?? 'Administrator');
                $notif_msg = $conn->real_escape_string("Your account has been deactivated by $actor. You will not be able to log in until your account is reactivated. Contact your administrator if you believe this is an error.");
                $conn->query("INSERT INTO notifications (user_id, type, title, message, priority, created_at)
                              VALUES ($uid_p, 'system', 'Account Deactivated', '$notif_msg', 'urgent', NOW())");
                mail_account_status($target['email'], $target['name'], 'inactive');
                $_ip4 = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
                $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address)
                              VALUES ($me,'USER_DEACTIVATED','users',$uid_p,'Deactivated account of: ".addslashes($target['name'])."','$_ip4')");
                $m = 'User account deactivated. They have been notified.'; $t = 's';
            }
        }

    } elseif ($act === 'reactivate') {
        // Block reactivating a higher-ranked user
        if ($uid_p && $get_level($uid_p) < $me_level) {
            $m = 'You do not have permission to reactivate a higher-ranked user.'; $t = 'e';
            header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
        }
        if ($uid_p === $me) { $m = 'Cannot change your own status here.'; $t = 'e'; }
        else {
            $target = $conn->query("SELECT name, email FROM users WHERE id=$uid_p AND deleted_at IS NULL")->fetch_assoc();
            if (!$target) { $m = 'User not found.'; $t = 'e'; }
            else {
                $conn->query("UPDATE users SET status='active', failed_attempts=0, locked_until=NULL WHERE id=$uid_p");
                $actor = $conn->real_escape_string($_SESSION['name'] ?? 'Administrator');
                $notif_msg = $conn->real_escape_string("Your account has been reactivated by $actor. You can now log in to the system.");
                $conn->query("INSERT INTO notifications (user_id, type, title, message, priority, created_at)
                              VALUES ($uid_p, 'system', 'Account Reactivated', '$notif_msg', 'high', NOW())");
                mail_account_status($target['email'], $target['name'], 'active');
                $_ip4 = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
                $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address)
                              VALUES ($me,'USER_REACTIVATED','users',$uid_p,'Reactivated account of: ".addslashes($target['name'])."','$_ip4')");
                $m = 'User account reactivated successfully.'; $t = 's';
            }
        }

    } elseif ($act === 'delete_user') {
        // Block deleting a higher-ranked user
        if ($uid_p && $get_level($uid_p) < $me_level) {
            $m = 'You do not have permission to delete a higher-ranked user.'; $t = 'e';
            header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
        }
        if ($uid_p === $me) { $m = 'You cannot delete your own account.'; $t = 'e'; }
        else {
            $target = $conn->query("SELECT name, email FROM users WHERE id=$uid_p AND deleted_at IS NULL")->fetch_assoc();
            if (!$target) { $m = 'User not found or already deleted.'; $t = 'e'; }
            else {
                // Soft delete — sets deleted_at timestamp, all data stays in the database
                $conn->query("UPDATE users SET deleted_at=NOW(), status='inactive' WHERE id=$uid_p");
                $_ip5 = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
                $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address)
                              VALUES ($me,'USER_DELETED','users',$uid_p,'Soft-deleted user: ".addslashes($target['name'])." (".addslashes($target['email']).")','$_ip5')");
                $m = 'User deleted. Their data is retained in the database.'; $t = 's';
            }
        }
    } else { $m = 'Unknown action.'; $t = 'e'; }

    header('Location: users.php?msg='.urlencode($m).'&typ='.$t); exit;
}

/* ── Flash ───────────────────────────────────────────────────  */
$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

/* ── Filters ─────────────────────────────────────────────────  */
$su = $conn->real_escape_string(trim($_GET['search'] ?? ''));
$rf = (int)($_GET['role'] ?? 0);
$sw = "u.deleted_at IS NULL";
if ($su) $sw .= " AND (u.name LIKE '%$su%' OR u.email LIKE '%$su%' OR u.employee_id LIKE '%$su%')";
if ($rf) $sw .= " AND u.role_id=$rf";

/* ── Pagination ───────────────────────────────────────────── */
$u_per_page_opts = [10, 25, 50, 100];
$u_per_page_raw = (int)($_GET['per_page'] ?? 0); $u_per_page = in_array($u_per_page_raw, $u_per_page_opts) ? $u_per_page_raw : 25;
$u_page     = max(1,(int)($_GET['page']??1));
$u_total_q  = $conn->query("SELECT COUNT(*) c FROM users u WHERE $sw");
$u_total_all= $u_total_q ? (int)$u_total_q->fetch_assoc()['c'] : 0;
$u_pages    = max(1,(int)ceil($u_total_all / $u_per_page));
$u_page     = min($u_page, $u_pages);
$u_offset   = ($u_page-1)*$u_per_page;

function u_url(array $extra=[]): string {
    global $su,$rf,$u_per_page,$u_page;
    $p=['search'=>$su,'role'=>$rf,'per_page'=>$u_per_page,'page'=>$u_page];
    foreach($extra as $k=>$v) $p[$k]=$v;
    $p=array_filter($p,function($v){return $v!==''&&$v!==0&&$v!==null;});
    return 'users.php'.($p?'?'.http_build_query($p):'');
}

/* ── Main queries (no department/major) ─────────────────────── */
$users = $conn->query("SELECT u.*, r.role_label, r.level AS role_level,
    c.college_name, p.program_name, p.program_code
    FROM users u
    LEFT JOIN roles r ON r.id=u.role_id
    LEFT JOIN colleges c ON c.id=u.college_id
    LEFT JOIN programs p ON p.id=u.program_id
    WHERE $sw ORDER BY r.level, u.name ASC LIMIT $u_per_page OFFSET $u_offset");

$roles        = $conn->query("SELECT id, role_label, role_key, level FROM roles ORDER BY level");
$colleges     = $conn->query("SELECT id, college_name FROM colleges WHERE status='active' ORDER BY college_name");
$programs_all = $conn->query("SELECT p.id, p.program_name, p.program_code, p.college_id
                               FROM programs p
                               WHERE p.status='active' ORDER BY p.program_name");
$total        = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE deleted_at IS NULL")->fetch_assoc()['c'];
$notif_count  = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html><html lang="en"><head><title>Users — QA System</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">User Management</div>
    <div class="topbar-right">
      <a href="notifications.php" class="notif-btn">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
        <?php if($notif_count > 0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
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

    <?php
    $access_req_notifs = $conn->query("SELECT n.*, n.message as req_msg FROM notifications n
        WHERE n.type='account_access_request' AND n.is_read=0
        ORDER BY n.created_at DESC LIMIT 10");
    $access_req_count = $access_req_notifs ? $access_req_notifs->num_rows : 0;
    if ($access_req_count > 0):
    ?>
    <div class="alert alert-warning" style="display:flex;flex-direction:column;gap:10px;align-items:flex-start;margin-bottom:16px;">
      <div style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:.9rem;width:100%;justify-content:space-between;">
        <span>⚠️ <?=$access_req_count?> Account Access Request<?=$access_req_count > 1 ? 's' : ''?> Pending</span>
      </div>
      <?php $access_req_notifs->data_seek(0); while($req = $access_req_notifs->fetch_assoc()): ?>
      <div style="width:100%;background:rgba(255,255,255,.6);border-radius:8px;padding:10px 14px;font-size:.83rem;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <span style="flex:1;"><?=htmlspecialchars($req['req_msg'])?></span>
        <span style="color:var(--muted);font-size:.75rem;white-space:nowrap;"><?=date('M d, g:i A', strtotime($req['created_at']))?></span>
        <form method="POST" action="../admin/notifications.php" style="display:inline;">
          <input type="hidden" name="mark_id" value="<?=$req['id']?>">
          <button type="submit" class="btn btn-sm btn-outline" style="white-space:nowrap;">Mark Seen</button>
        </form>
      </div>
      <?php endwhile; ?>
    </div>
    <?php endif; ?>

    <div class="page-header">
      <div><h1 class="page-heading">Users</h1><p class="page-subheading"><?=$u_total_all?> accounts matching filter &middot; <?=$total?> total</p></div>
      <button class="btn btn-primary" onclick="openCreate()">
        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M8 9a3 3 0 100-6 3 3 0 000 6zM8 11a6 6 0 016 6H2a6 6 0 016-6zM16 7a1 1 0 10-2 0v1h-1a1 1 0 100 2h1v1a1 1 0 102 0v-1h1a1 1 0 100-2h-1V7z"/></svg>
        Add User
      </button>
    </div>

    <div class="card" style="margin-bottom:0;border-bottom-left-radius:0;border-bottom-right-radius:0;">
      <div class="filter-bar">
        <input type="text" class="search-input" id="uSearch" placeholder="Search name, email, employee ID…" value="<?=htmlspecialchars($su)?>">
        <select id="uRole">
          <option value="0">All Roles</option>
          <?php $roles->data_seek(0); while($r = $roles->fetch_assoc()):?>
          <option value="<?=$r['id']?>" <?=$rf == $r['id'] ? 'selected' : ''?>><?=htmlspecialchars($r['role_label'])?></option>
          <?php endwhile;?>
        </select>
        <select id="uPerPage" style="padding:8px 28px 8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
          <option value="10" <?=$u_per_page==10?'selected':''?>>10 / page</option>
          <option value="25" <?=$u_per_page==25?'selected':''?>>25 / page</option>
          <option value="50" <?=$u_per_page==50?'selected':''?>>50 / page</option>
          <option value="100" <?=$u_per_page==100?'selected':''?>>100 / page</option>
        </select>
        <button class="btn btn-outline btn-sm" onclick="applyFilter()">Filter</button>
      </div>
    </div>

    <div class="card" style="border-top:none;border-top-left-radius:0;border-top-right-radius:0;margin-top:0;">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Employee ID</th><th>Name</th><th>Email</th><th>Role</th><th>College / Program</th><th>Status</th><th>Last Login</th><th>Actions</th></tr></thead>
          <tbody>
          <?php if(!$users || $users->num_rows === 0):?>
          <tr><td colspan="8"><div class="empty-state"><p>No users found.</p></div></td></tr>
          <?php else: while($u = $users->fetch_assoc()):
            $is_higher_rank = (int)($u['role_level'] ?? 99) < $me_level;
          ?>
          <tr>
            <td class="text-sm text-muted"><?=htmlspecialchars($u['employee_id'] ?: '—')?></td>
            <td style="font-weight:500;"><?=htmlspecialchars($u['name'])?></td>
            <td class="text-sm"><?=htmlspecialchars($u['email'])?></td>
            <td><span class="badge badge-submitted" style="font-size:.72rem;"><?=htmlspecialchars($u['role_label'] ?? '—')?></span></td>
            <td class="text-sm"><?php
              if (!empty($u['program_name'])) {
                  echo htmlspecialchars($u['program_code'].' — '.$u['program_name']);
              } elseif (!empty($u['college_name'])) {
                  echo htmlspecialchars($u['college_name']);
              } else {
                  echo '—';
              }
            ?></td>
            <td><span class="badge badge-<?=$u['status']==='active'?'approved':($u['status']==='suspended'?'under_review':'rejected')?>"><?=ucfirst($u['status'])?></span></td>
            <td class="text-sm text-muted"><?=$u['last_login_at'] ? date('M d, Y', strtotime($u['last_login_at'])) : 'Never'?></td>
            <td>
              <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;">
                <?php if($is_higher_rank): ?>
                  <span style="font-size:.72rem;color:var(--muted);font-style:italic;padding:2px 6px;background:var(--bg);border-radius:6px;border:1px solid var(--border);">🔒 Protected</span>
                <?php else: ?>
                  <button class="btn btn-ghost btn-sm" onclick='openEdit(<?=json_encode([
                    'id'          => $u['id'],
                    'name'        => $u['name'],
                    'email'       => $u['email'],
                    'employee_id' => $u['employee_id'],
                    'role_id'     => $u['role_id'],
                    'college_id'  => $u['college_id'],
                    'program_id'  => $u['program_id'] ?? null,
                    'status'      => $u['status'],
                  ])?>)'>Edit</button>
                  <button class="btn btn-ghost btn-sm" onclick='openReset(<?=$u['id']?>,<?=json_encode($u['name'])?>)'>Reset PW</button>
                  <?php if($u['id'] !== $me): ?>
                    <?php if($u['status'] === 'active' || $u['status'] === 'suspended'): ?>
                    <form method="POST" class="swal-confirm-form" data-title="Deactivate User?" data-text="This user will not be able to log in until reactivated." data-icon="warning" data-confirm="Yes, Deactivate" data-cls="qa-btn-red">
                      <input type="hidden" name="form_action" value="deactivate">
                      <input type="hidden" name="user_id" value="<?=$u['id']?>">
                      <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
                    </form>
                    <?php else: ?>
                    <form method="POST" class="swal-confirm-form" data-title="Reactivate User?" data-text="This will restore login access for this user." data-icon="question" data-confirm="Yes, Reactivate" data-cls="qa-btn-green">
                      <input type="hidden" name="form_action" value="reactivate">
                      <input type="hidden" name="user_id" value="<?=$u['id']?>">
                      <button type="submit" class="btn btn-sm" style="background:#059669;color:white;">Reactivate</button>
                    </form>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if($u['id'] !== $me): ?>
                  <form method="POST" class="swal-confirm-form"
                        data-title="Delete User?"
                        data-text="This will permanently remove the user's access. Their data will be retained in the database and can be recovered by an administrator."
                        data-icon="warning"
                        data-confirm="Yes, Delete User"
                        data-cls="qa-btn-red">
                    <input type="hidden" name="form_action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?=$u['id']?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Soft delete — data kept in database">Delete</button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endwhile; endif;?>
          </tbody>
        </table>
      </div>
      <!-- Pagination -->
      <?php if($u_pages > 1 || $u_total_all > 10): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;padding:14px 24px;border-top:1px solid var(--border);">
        <div style="font-size:.82rem;color:var(--muted);">
          Showing <?=min($u_offset+1,$u_total_all)?>–<?=min($u_offset+$u_per_page,$u_total_all)?> of <?=$u_total_all?> users
        </div>
        <div class="pagination" style="padding:0;">
          <a href="<?=u_url(['page'=>1])?>" class="page-link<?=$u_page<=1?' disabled':''?>">«</a>
          <a href="<?=u_url(['page'=>max(1,$u_page-1)])?>" class="page-link<?=$u_page<=1?' disabled':''?>">‹</a>
          <?php $s=max(1,$u_page-2);$e=min($u_pages,$s+4);for($p=$s;$p<=$e;$p++):?>
          <a href="<?=u_url(['page'=>$p])?>" class="page-link<?=$p==$u_page?' active':''?>"><?=$p?></a>
          <?php endfor;?>
          <a href="<?=u_url(['page'=>min($u_pages,$u_page+1)])?>" class="page-link<?=$u_page>=$u_pages?' disabled':''?>">›</a>
          <a href="<?=u_url(['page'=>$u_pages])?>" class="page-link<?=$u_page>=$u_pages?' disabled':''?>">»</a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- CREATE MODAL -->
<div class="modal-overlay" id="createModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header"><span class="modal-title">Add New User</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <form method="POST"><input type="hidden" name="form_action" value="create">
      <div class="modal-body">
        <div class="form-row cols-2">
          <div class="field"><label>Employee ID</label><input type="text" name="employee_id" placeholder="Optional"></div>
          <div class="field"><label>Full Name *</label><input type="text" name="name" required></div>
        </div>
        <div class="form-row cols-2">
          <div class="field"><label>Email *</label><input type="email" name="email" required></div>
          <div class="field"><label>Password * (min 6 chars)</label><input type="password" name="password" required minlength="6"></div>
        </div>
        <div class="form-row cols-2">
          <div class="field"><label>Role *</label>
            <select name="role_id" required><option value="">Select role…</option>
              <?php $roles->data_seek(0); while($r = $roles->fetch_assoc()):
                if ((int)$r['level'] < $me_level) continue;
              ?>
              <option value="<?=$r['id']?>"><?=htmlspecialchars($r['role_label'])?></option>
              <?php endwhile;?>
            </select>
          </div>
          <div class="field"><label>College</label>
            <select name="college_id" id="cu_college" onchange="filterProgramsByCollege(this.value,'cu_prog','cu_program_wrap')">
              <option value="0">None</option>
              <?php $colleges->data_seek(0); while($c = $colleges->fetch_assoc()):?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['college_name'])?></option>
              <?php endwhile;?>
            </select>
          </div>
        </div>
        <div class="form-row" id="cu_program_wrap">
          <div class="field"><label>Program</label>
            <select name="program_id" id="cu_prog">
              <option value="0">Select program…</option>
              <?php if(isset($programs_all)){$programs_all->data_seek(0); while($p = $programs_all->fetch_assoc()):?>
              <option value="<?=$p['id']?>" data-college="<?=$p['college_id']?>">[<?=htmlspecialchars($p['program_code'])?>] <?=htmlspecialchars($p['program_name'])?></option>
              <?php endwhile;}?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:620px;">
    <div class="modal-header"><span class="modal-title">Edit User</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <form method="POST"><input type="hidden" name="form_action" value="update"><input type="hidden" name="user_id" id="eu_id">
      <div class="modal-body">
        <div class="form-row cols-2">
          <div class="field"><label>Employee ID</label><input type="text" name="employee_id" id="eu_emp"></div>
          <div class="field"><label>Full Name *</label><input type="text" name="name" id="eu_name" required></div>
        </div>
        <div class="form-row cols-2">
          <div class="field"><label>Email *</label><input type="email" name="email" id="eu_email" required></div>
          <div class="field"><label>Status</label>
            <select name="status" id="eu_status">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="suspended">Suspended</option>
            </select>
          </div>
        </div>
        <div class="form-row cols-2">
          <div class="field"><label>Role *</label>
            <select name="role_id" id="eu_role" required><option value="">Select…</option>
              <?php $roles->data_seek(0); while($r = $roles->fetch_assoc()):
                if ((int)$r['level'] < $me_level) continue;
              ?>
              <option value="<?=$r['id']?>"><?=htmlspecialchars($r['role_label'])?></option>
              <?php endwhile;?>
            </select>
          </div>
          <div class="field"><label>College</label>
            <select name="college_id" id="eu_college" onchange="filterProgramsByCollege(this.value,'eu_prog','eu_program_wrap')">
              <option value="0">None</option>
              <?php $colleges->data_seek(0); while($c = $colleges->fetch_assoc()):?>
              <option value="<?=$c['id']?>"><?=htmlspecialchars($c['college_name'])?></option>
              <?php endwhile;?>
            </select>
          </div>
        </div>
        <div class="form-row" id="eu_program_wrap">
          <div class="field"><label>Program</label>
            <select name="program_id" id="eu_prog">
              <option value="0">Select program…</option>
              <?php if(isset($programs_all)){$programs_all->data_seek(0); while($p = $programs_all->fetch_assoc()):?>
              <option value="<?=$p['id']?>" data-college="<?=$p['college_id']?>">[<?=htmlspecialchars($p['program_code'])?>] <?=htmlspecialchars($p['program_name'])?></option>
              <?php endwhile;}?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- RESET PASSWORD MODAL -->
<div class="modal-overlay" id="resetModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header"><span class="modal-title">Reset Password</span>
      <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
    </div>
    <form method="POST"><input type="hidden" name="form_action" value="reset_password"><input type="hidden" name="user_id" id="rp_id">
      <div class="modal-body">
        <p class="text-sm text-muted" id="rp_name" style="margin-bottom:16px;"></p>
        <div class="field"><label>New Password * (min 6 chars)</label>
          <input type="password" name="new_password" required minlength="6" placeholder="Enter new password">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Reset Password</button>
      </div>
    </form>
  </div>
</div>

<!-- Hidden master program lists for college-filtering -->
<select id="_prog_master_cu_prog" style="display:none;">
  <?php if(isset($programs_all)){$programs_all->data_seek(0); while($p = $programs_all->fetch_assoc()):?>
  <option value="<?=$p['id']?>" data-college="<?=$p['college_id']?>">[<?=htmlspecialchars($p['program_code'])?>] <?=htmlspecialchars($p['program_name'])?></option>
  <?php endwhile;}?>
</select>
<select id="_prog_master_eu_prog" style="display:none;">
  <?php if(isset($programs_all)){$programs_all->data_seek(0); while($p = $programs_all->fetch_assoc()):?>
  <option value="<?=$p['id']?>" data-college="<?=$p['college_id']?>">[<?=htmlspecialchars($p['program_code'])?>] <?=htmlspecialchars($p['program_name'])?></option>
  <?php endwhile;}?>
</select>

<script>
function applyFilter() {
    window.location.href = 'users.php?search=' + encodeURIComponent(document.getElementById('uSearch').value)
                         + '&role=' + document.getElementById('uRole').value;
}
document.getElementById('uSearch').addEventListener('keydown', e => { if (e.key === 'Enter') applyFilter(); });
document.getElementById('uRole').addEventListener('change', applyFilter);

// Live search with highlight
const uInput     = document.getElementById('uSearch');
let   uDebounce  = null;
const uRows      = document.querySelectorAll('tbody tr');
const uOriginals = new Map();
uRows.forEach(row => { uOriginals.set(row, row.innerHTML); });

function highlightNode(node, term) {
    if (node.nodeType === Node.TEXT_NODE) {
        const idx = node.textContent.toLowerCase().indexOf(term.toLowerCase());
        if (idx === -1) return;
        const before = document.createTextNode(node.textContent.slice(0, idx));
        const mark   = document.createElement('mark');
        mark.style.cssText = 'background:#fef08a;border-radius:2px;padding:0 2px;font-weight:600;';
        mark.textContent   = node.textContent.slice(idx, idx + term.length);
        const after  = document.createTextNode(node.textContent.slice(idx + term.length));
        node.parentNode.insertBefore(before, node);
        node.parentNode.insertBefore(mark,   node);
        node.parentNode.insertBefore(after,  node);
        node.parentNode.removeChild(node);
        highlightNode(after, term);
    } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName !== 'SCRIPT') {
        Array.from(node.childNodes).forEach(c => highlightNode(c, term));
    }
}

function uLiveSearch() {
    const term    = uInput.value.trim();
    const termLow = term.toLowerCase();
    uRows.forEach(row => {
        if (uOriginals.has(row)) row.innerHTML = uOriginals.get(row);
        if (!term) { row.style.display = ''; return; }
        if (row.textContent.toLowerCase().includes(termLow)) {
            row.style.display = '';
            highlightNode(row, term);
        } else {
            row.style.display = 'none';
        }
    });
}
uInput.addEventListener('input', () => { clearTimeout(uDebounce); uDebounce = setTimeout(uLiveSearch, 180); });
if (uInput.value.trim()) uLiveSearch();

function openCreate() { document.getElementById('createModal').classList.add('open'); }

function openEdit(u) {
    document.getElementById('eu_id').value      = u.id;
    document.getElementById('eu_emp').value     = u.employee_id || '';
    document.getElementById('eu_name').value    = u.name;
    document.getElementById('eu_email').value   = u.email;
    document.getElementById('eu_role').value    = u.role_id || '';
    document.getElementById('eu_college').value = u.college_id || '0';
    document.getElementById('eu_status').value  = u.status || 'active';
    // Filter programs by college, then restore saved program
    filterProgramsByCollege(u.college_id || '0', 'eu_prog', 'eu_program_wrap');
    setTimeout(function() {
        var ps = document.getElementById('eu_prog');
        if (ps && u.program_id) ps.value = u.program_id;
    }, 50);
    document.getElementById('editModal').classList.add('open');
}

function openReset(id, name) {
    document.getElementById('rp_id').value = id;
    document.getElementById('rp_name').textContent = 'Resetting password for: ' + name;
    document.getElementById('resetModal').classList.add('open');
}

// Filter Program dropdown by selected College
function filterProgramsByCollege(cid, progSelId, progWrapId) {
    var progSel  = document.getElementById(progSelId);
    var progWrap = document.getElementById(progWrapId);
    var master   = document.getElementById('_prog_master_' + progSelId);
    if (!progSel || !master) return;

    progSel.innerHTML = '<option value="0">Select program…</option>';
    Array.from(master.querySelectorAll('option[data-college]')).forEach(function(opt) {
        if (!cid || cid === '0' || opt.getAttribute('data-college') === cid)
            progSel.appendChild(opt.cloneNode(true));
    });
    if (progWrap) progWrap.style.display = '';
}

// ── SweetAlert confirmations ──────────────────────────────────
(function() {
    var modals = [
        { id: 'createModal', title: 'Create User?',       html: 'A welcome email with login credentials will be sent to the user.',  confirmText: 'Yes, Create User',    icon: 'question' },
        { id: 'editModal',   title: 'Save User Changes?', html: 'Save the changes to this user account?',                           confirmText: 'Yes, Save Changes',   icon: 'question' },
        { id: 'resetModal',  title: 'Reset Password?',    html: 'The user will receive an email with their new password.',           confirmText: 'Yes, Reset Password', icon: 'warning'  },
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
                    title: m.title, html: m.html, icon: m.icon,
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
</body>
</html>