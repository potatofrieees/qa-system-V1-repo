<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
require_once '../mail/emails.php';
//require_login(['qa_director', 'qa_staff']);
$active_nav = 'profile';
$uid = (int)$_SESSION['user_id'];

/* ── POST → Redirect ─────────────────────────────────────────── */
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
            $conn->query("INSERT INTO audit_logs (user_id,action,description) VALUES ($uid,'PASSWORD_CHANGE','Admin changed own password')");
            $m = 'Password changed successfully.'; $t = 's';
        }

    } elseif ($act === 'update_profile') {
        $phone = $conn->real_escape_string(trim($_POST['phone'] ?? ''));
        $dname = $conn->real_escape_string(trim($_POST['display_name'] ?? ''));
        if ($dname) {
            $conn->query("UPDATE users SET phone='$phone', name='$dname' WHERE id=$uid");
            $_SESSION['name'] = trim($_POST['display_name']); // Store raw (not escaped) in session
            $m = 'Profile updated.'; $t = 's';
        } else {
            $m = 'Display name cannot be empty.'; $t = 'e';
        }
    } else {
        $m = 'Unknown action.'; $t = 'e';
    }

    header("Location: profile.php?msg=".urlencode($m)."&typ=$t"); exit;
}

/* ── Flash ───────────────────────────────────────────────────── */
$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

/* ── Fetch user ──────────────────────────────────────────────── */
$user = $conn->query("
    SELECT u.*, r.role_label, c.college_name, p.program_name
    FROM users u
    LEFT JOIN roles r    ON r.id = u.role_id
    LEFT JOIN colleges c ON c.id = u.college_id
    LEFT JOIN programs p ON p.id = u.program_id
    WHERE u.id = $uid AND u.deleted_at IS NULL
")->fetch_assoc();

// Guard: if user not found (e.g. deleted account), redirect to logout
if (!$user) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

/* ── Activity summary ────────────────────────────────────────── */
$doc_counts = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(status='approved') AS approved,
        SUM(status='submitted' OR status='under_review') AS pending,
        SUM(status='revision_requested') AS revisions
    FROM documents
    WHERE uploaded_by=$uid AND deleted_at IS NULL
")->fetch_assoc();

$review_count = $conn->query("SELECT COUNT(*) c FROM document_reviews WHERE reviewer_id=$uid")->fetch_assoc()['c'];

/* ── Recent audit activity ───────────────────────────────────── */
$recent_activity = $conn->query("
    SELECT action, description, created_at
    FROM audit_logs
    WHERE user_id=$uid
    ORDER BY created_at DESC
    LIMIT 6
");

$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];

// Build initials
$name_parts = explode(' ', trim($user['name'] ?? 'U'));
$initials   = strtoupper(substr($name_parts[0],0,1) . (isset($name_parts[1]) ? substr($name_parts[1],0,1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>My Profile — QA System</title>
    <?php include 'head.php'; ?>
    <style>
    .profile-avatar{width:72px;height:72px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;color:white;flex-shrink:0;}
    .info-row{display:flex;padding:11px 0;border-bottom:1px solid var(--border);font-size:.88rem;}
    .info-row:last-child{border-bottom:none;}
    .info-label{color:var(--muted);width:42%;flex-shrink:0;}
    .info-val{font-weight:500;word-break:break-all;}
    .stat-mini{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px 18px;text-align:center;}
    .stat-mini .n{font-size:1.6rem;font-weight:700;}
    .stat-mini .l{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-top:2px;}
    .act-row{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--border);align-items:flex-start;}
    .act-row:last-child{border-bottom:none;}
    .act-dot{width:8px;height:8px;border-radius:50%;background:var(--primary-light);flex-shrink:0;margin-top:5px;}
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
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

        <div class="page-header">
            <h1 class="page-heading">My Profile</h1>
            <p class="page-subheading">Manage your account information and password</p>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

            <!-- ── LEFT COLUMN ── -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Profile Card -->
                <div class="card">
                    <div style="padding:20px;border-bottom:1px solid var(--border);display:flex;gap:16px;align-items:center;">
                        <div class="profile-avatar"><?=$initials?></div>
                        <div>
                            <div style="font-weight:700;font-size:1.15rem;"><?=htmlspecialchars($user['name'])?></div>
                            <div style="color:var(--muted);font-size:.88rem;margin-bottom:6px;"><?=htmlspecialchars($user['email'])?></div>
                            <span class="badge badge-submitted"><?=htmlspecialchars($user['role_label']??'—')?></span>
                            <?php if($user['status']==='active'):?>
                            <span class="badge badge-active" style="margin-left:4px;">Active</span>
                            <?php endif;?>
                        </div>
                    </div>
                    <div style="padding:16px 20px;">
                        <?php $info_rows = [
                            'Employee ID'  => !empty($user['employee_id']) ? $user['employee_id'] : '—',
                            'Program'      => $user['program_name'] ?? ($user['college_name'] ?? '—'),
                            'Phone'        => !empty($user['phone']) ? $user['phone'] : '—',
                            'Last Login'   => !empty($user['last_login_at']) ? date('M d, Y H:i', strtotime($user['last_login_at'])) : 'Never',
                            'Member Since' => !empty($user['created_at']) ? date('F Y', strtotime($user['created_at'])) : '—',
                        ];
                        foreach ($info_rows as $lbl => $val): ?>
                        <div class="info-row">
                            <div class="info-label"><?=$lbl?></div>
                            <div class="info-val"><?=htmlspecialchars($val)?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Activity Stats -->
                <div class="card" style="padding:20px;">
                    <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px;">My Activity</div>
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
                        <div class="stat-mini">
                            <div class="n" style="color:var(--primary);"><?=(int)$doc_counts['total']?></div>
                            <div class="l">Documents</div>
                        </div>
                        <div class="stat-mini">
                            <div class="n" style="color:var(--status-approved);"><?=(int)$doc_counts['approved']?></div>
                            <div class="l">Approved</div>
                        </div>
                        <div class="stat-mini">
                            <div class="n" style="color:#d97706;"><?=(int)$doc_counts['pending']?></div>
                            <div class="l">Pending</div>
                        </div>
                        <div class="stat-mini">
                            <div class="n" style="color:var(--primary-light);"><?=(int)$review_count?></div>
                            <div class="l">Reviews Done</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Activity -->
                <?php if ($recent_activity && $recent_activity->num_rows > 0): ?>
                <div class="card" style="padding:20px;">
                    <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px;">Recent Activity</div>
                    <?php while($act_row = $recent_activity->fetch_assoc()): ?>
                    <div class="act-row">
                        <div class="act-dot"></div>
                        <div style="flex:1;">
                            <div style="font-size:.83rem;font-weight:500;"><?=htmlspecialchars(mb_substr($act_row['description'] ?? $act_row['action'],0,70))?></div>
                            <div style="font-size:.72rem;color:var(--muted);margin-top:2px;"><?=date('M d, Y H:i',strtotime($act_row['created_at']))?></div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── RIGHT COLUMN ── -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Edit Profile -->
                <div class="card">
                    <div class="card-header"><span class="card-title">✏️ Edit Profile</span></div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="form_action" value="update_profile">
                            <div class="field" style="margin-bottom:14px;">
                                <label>Display Name</label>
                                <input type="text" name="display_name" value="<?=htmlspecialchars($user['name'])?>" required>
                            </div>
                            <div class="field" style="margin-bottom:14px;">
                                <label>Email Address <span style="font-size:.75rem;color:var(--muted);">(read only)</span></label>
                                <input type="email" value="<?=htmlspecialchars($user['email'])?>" disabled style="opacity:.6;cursor:not-allowed;">
                            </div>
                            <div class="field" style="margin-bottom:20px;">
                                <label>Phone / Mobile</label>
                                <input type="text" name="phone" value="<?=htmlspecialchars($user['phone']??'')?>" placeholder="e.g. +63 912 345 6789">
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
                                <input type="password" name="old_password" required placeholder="Your current password">
                            </div>
                            <div class="field" style="margin-bottom:14px;">
                                <label>New Password * <span style="font-size:.75rem;color:var(--muted);">(min 6 chars)</span></label>
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

                <!-- Security Info -->
                <div class="card" style="padding:20px;">
                    <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:14px;">🔒 Security Info</div>
                    <div class="info-row">
                        <div class="info-label">Account Status</div>
                        <div class="info-val">
                            <span class="badge badge-<?=$user['status']==='active'?'active':'rejected'?>"><?=ucfirst($user['status']??'—')?></span>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Two-Factor Auth</div>
                        <div class="info-val">
                            <?php if(!empty($user['otp_secret'])): ?>
                            <span class="badge badge-approved">Enabled</span>
                            <?php else: ?>
                            <span style="color:var(--muted);font-size:.85rem;">Not configured</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Last Login</div>
                        <div class="info-val" style="font-size:.85rem;"><?=$user['last_login_at']?date('M d, Y H:i',strtotime($user['last_login_at'])):'Never'?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Account Since</div>
                        <div class="info-val" style="font-size:.85rem;"><?=date('F j, Y',strtotime($user['created_at']))?></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>