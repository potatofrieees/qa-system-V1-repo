<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
require_once '../mail/emails.php';
//require_login();
$active_nav = 'profile';
$uid = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'change_password') {
        $old  = $_POST['old_password'] ?? '';
        $new1 = $_POST['new_password'] ?? '';
        $new2 = $_POST['new_password2'] ?? '';
        $row  = $conn->query("SELECT password, email, name FROM users WHERE id=$uid")->fetch_assoc();

        if (!password_verify($old, $row['password'])) {
            $m = 'Current password is incorrect.'; $t = 'e';
        } elseif (strlen($new1) < 6) {
            $m = 'New password must be at least 6 characters.'; $t = 'e';
        } elseif ($new1 !== $new2) {
            $m = 'New passwords do not match.'; $t = 'e';
        } else {
            $h = $conn->real_escape_string(password_hash($new1, PASSWORD_BCRYPT));
            $conn->query("UPDATE users SET password='$h' WHERE id=$uid");
            $conn->query("INSERT INTO audit_logs (user_id,action,description) VALUES ($uid,'PASSWORD_CHANGE','User changed own password')");
            $m = 'Password changed successfully.'; $t = 's';
        }
    } elseif ($act === 'update_profile') {
        $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $dname_raw = trim($_POST['display_name'] ?? '');
        if ($dname_raw) {
            $dname_e = $conn->real_escape_string($dname_raw);
            $conn->query("UPDATE users SET phone='$phone', name='$dname_e' WHERE id=$uid");
            $_SESSION['name'] = $dname_raw; // Store raw value in session (not SQL-escaped)
        } else {
            $conn->query("UPDATE users SET phone='$phone' WHERE id=$uid");
        }
        $m = 'Profile updated.'; $t = 's';
    } else { $m = 'Unknown action.'; $t = 'e'; }

    header("Location: profile.php?msg=".urlencode($m)."&typ=$t"); exit;
}

$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

$user = $conn->query("SELECT u.*, r.role_label, c.college_name
    FROM users u
    LEFT JOIN roles r ON r.id=u.role_id
    LEFT JOIN colleges c ON c.id=u.college_id
    WHERE u.id=$uid AND u.deleted_at IS NULL")->fetch_assoc();

if (!$user) { session_destroy(); header('Location: ../login.php'); exit; }

$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html><html lang="en"><head><title>My Profile — QA Portal</title><?php include 'head.php'; ?></head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">My Profile</div>
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
    <div class="page-header"></div>

    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;align-items:start;">
      <!-- Profile Info -->
      <div class="card">
        <div class="card-header"><span class="card-title">Account Details</span></div>
        <div class="card-body">
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:20px;border-bottom:1px solid var(--border);">
            <div style="width:64px;height:64px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:24px;font-weight:700;color:white;flex-shrink:0;">
              <?=strtoupper(substr($user['name'],0,1))?>
            </div>
            <div>
              <div style="font-weight:600;font-size:1.1rem;"><?=htmlspecialchars($user['name'])?></div>
              <div class="text-sm text-muted"><?=htmlspecialchars($user['email'])?></div>
              <span class="badge badge-submitted" style="margin-top:4px;"><?=htmlspecialchars($user['role_label']??'—')?></span>
            </div>
          </div>
          <table style="width:100%;font-size:.875rem;border-collapse:collapse;">
            <?php foreach([
              'Employee ID' => $user['employee_id']?:'—',
              'Program' => $user['program_name'] ?? ($user['college_name'] ?? '—'),
              'Phone'       => $user['phone']?:'—',
              'Last Login'  => $user['last_login_at']?date('M d, Y H:i',strtotime($user['last_login_at'])):'Never',
              'Member Since'=> date('F Y', strtotime($user['created_at'])),
            ] as $label => $val):?>
            <tr style="border-bottom:1px solid var(--border);">
              <td style="padding:10px 0;color:var(--muted);width:40%;"><?=$label?></td>
              <td style="padding:10px 0;font-weight:500;"><?=htmlspecialchars($val)?></td>
            </tr>
            <?php endforeach;?>
          </table>
        </div>
      </div>

      <!-- Edit Profile -->
      <div class="card">
        <div class="card-header"><span class="card-title">✏️ Edit Profile</span></div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="form_action" value="update_profile">
            <div class="field" style="margin-bottom:14px;">
              <label>Display Name *</label>
              <input type="text" name="display_name" required
                     value="<?=htmlspecialchars($user['name'])?>"
                     placeholder="Your full name">
            </div>
            <div class="field" style="margin-bottom:20px;">
              <label>Phone Number</label>
              <input type="text" name="phone"
                     value="<?=htmlspecialchars($user['phone']??'')?>"
                     placeholder="e.g. +63 912 345 6789">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Save Changes</button>
          </form>
        </div>
      </div>

      <!-- Change Password -->
      <div class="card">
        <div class="card-header"><span class="card-title">🔑 Change Password</span></div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="form_action" value="change_password">
            <div class="field" style="margin-bottom:14px;">
              <label>Current Password *</label>
              <input type="password" name="old_password" required placeholder="Enter your current password">
            </div>
            <div class="field" style="margin-bottom:14px;">
              <label>New Password * (min 6 chars)</label>
              <input type="password" name="new_password" required minlength="6" placeholder="Choose a strong password">
            </div>
            <div class="field" style="margin-bottom:20px;">
              <label>Confirm New Password *</label>
              <input type="password" name="new_password2" required minlength="6" placeholder="Repeat new password">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Update Password</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
</body></html>
