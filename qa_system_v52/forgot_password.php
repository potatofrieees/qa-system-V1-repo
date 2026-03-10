<?php
session_start();
include 'database/db_connect.php';
require_once 'mail/emails.php';

// Already logged in
if (isset($_SESSION['user_id'])) {
    $dest = in_array($_SESSION['role'] ?? '', ['qa_director','qa_staff']) ? 'admin/dashboard.php' : 'user/dashboard.php';
    header('Location: ' . $dest); exit;
}

$step    = 'request';   // request | sent | reset | done
$error   = '';
$success = '';
$token   = trim($_GET['token'] ?? '');
$token_user = null;

// If token in URL, validate it first
if ($token) {
    $te  = $conn->real_escape_string($token);
    $token_user = $conn->query("SELECT id, name FROM users WHERE otp_code='$te' AND otp_expiry > NOW() AND deleted_at IS NULL LIMIT 1")->fetch_assoc();
    $step = $token_user ? 'reset' : 'invalid';
}

// POST: send reset email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_step'] ?? '') === 'request') {
    $email = trim($_POST['email'] ?? '');
    if (!$email) {
        $error = 'Please enter your email address.';
    } else {
        $em  = $conn->real_escape_string($email);
        $row = $conn->query("SELECT id, name, email, status FROM users WHERE email='$em' AND deleted_at IS NULL LIMIT 1")->fetch_assoc();
        if ($row && $row['status'] === 'active') {
            $tok = bin2hex(random_bytes(32));
            $exp = date('Y-m-d H:i:s', strtotime('+60 minutes'));
            $te2 = $conn->real_escape_string($tok);
            $conn->query("UPDATE users SET otp_code='$te2', otp_expiry='$exp' WHERE id={$row['id']}");
            mail_password_reset($row['email'], $row['name'], $tok);
        }
        // Always show sent page (anti-enumeration)
        $step = 'sent';
    }
}

// POST: set new password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_step'] ?? '') === 'reset') {
    $tok2 = trim($_POST['token'] ?? '');
    $pw1  = $_POST['password']   ?? '';
    $pw2  = $_POST['password2']  ?? '';
    $te3  = $conn->real_escape_string($tok2);
    $row2 = $conn->query("SELECT id, name FROM users WHERE otp_code='$te3' AND otp_expiry > NOW() AND deleted_at IS NULL LIMIT 1")->fetch_assoc();

    if (!$row2)        { $error = 'This reset link is invalid or has expired.'; $step = 'invalid'; }
    elseif (strlen($pw1) < 6) { $error = 'Password must be at least 6 characters.'; $step = 'reset'; $token_user = $row2; }
    elseif ($pw1 !== $pw2)    { $error = 'Passwords do not match.'; $step = 'reset'; $token_user = $row2; }
    else {
        $h = $conn->real_escape_string(password_hash($pw1, PASSWORD_BCRYPT));
        $conn->query("UPDATE users SET password='$h', otp_code=NULL, otp_expiry=NULL, failed_attempts=0, locked_until=NULL WHERE id={$row2['id']}");
        $conn->query("INSERT INTO audit_logs (user_id, action, description, ip_address) VALUES ({$row2['id']}, 'PASSWORD_RESET', 'Self-service password reset via email link', '".$conn->real_escape_string($_SERVER['REMOTE_ADDR']??'')."')");
        $step = 'done';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password — QAIAO System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/login.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
<div class="bg-grid"></div>
<div class="login-wrapper">

  <!-- Brand Panel -->
  <div class="brand-panel">
    <div class="brand-content">
      <div class="brand-icon">
        <svg viewBox="0 0 60 60" fill="none">
          <circle cx="30" cy="30" r="28" stroke="rgba(255,255,255,0.3)" stroke-width="2"/>
          <path d="M20 30L27 37L40 23" stroke="white" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </div>
      <h1>QAIAO</h1>
      <p class="brand-sub">QAIAO<br>Management System</p>
      <div class="brand-divider"></div>
      <p class="brand-desc">Your reset link will expire in 60 minutes for security.</p>
    </div>
  </div>

  <!-- Form Panel -->
  <div class="form-panel">
    <div class="form-container">

      <?php if ($step === 'request'): ?>
      <!-- STEP: Request -->
      <div class="form-header">
        <h2>Forgot password?</h2>
        <p>Enter your email and we'll send a reset link</p>
      </div>
      <?php if ($error): ?>
      <div class="alert-error">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
      <form method="POST" action="forgot_password.php">
        <input type="hidden" name="form_step" value="request">
        <div class="field-group">
          <label for="email">Email Address</label>
          <div class="input-wrap">
            <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
            <input type="email" id="email" name="email" placeholder="Enter your registered email" required autofocus>
          </div>
        </div>
        <button type="submit" class="btn-login">
          <span>Send Reset Link</span>
          <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        </button>
      </form>

      <?php elseif ($step === 'sent'): ?>
      <!-- STEP: Email Sent -->
      <div class="form-header">
        <h2>Check your email</h2>
        <p>A reset link has been sent if your email is registered</p>
      </div>
      <div class="alert-success">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        <div>
          <strong>Reset link sent!</strong><br>
          Check your inbox and spam folder. The link expires in 60 minutes.
        </div>
      </div>
      <p style="font-size:.85rem;color:#6b7a8d;text-align:center;margin-top:8px;">Didn't receive it? <a href="forgot_password.php" style="color:#2563a8;">Try again</a></p>

      <?php elseif ($step === 'reset'): ?>
      <!-- STEP: Set new password -->
      <div class="form-header">
        <h2>Set new password</h2>
        <p>Hi <?= htmlspecialchars($token_user['name'] ?? '') ?>, choose a strong password</p>
      </div>
      <?php if ($error): ?>
      <div class="alert-error">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        <?= htmlspecialchars($error) ?>
      </div>
      <?php endif; ?>
      <form method="POST" action="forgot_password.php?token=<?= urlencode($token) ?>">
        <input type="hidden" name="form_step" value="reset">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
        <div class="field-group">
          <label for="pw1">New Password <span style="color:#8a94a6;font-size:.75rem;">(min 6 characters)</span></label>
          <div class="input-wrap">
            <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <input type="password" id="pw1" name="password" required minlength="6" autofocus placeholder="Enter new password">
            <button type="button" class="toggle-pw" onclick="togglePw('pw1')">
              <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
            </button>
          </div>
        </div>
        <div class="field-group">
          <label for="pw2">Confirm New Password</label>
          <div class="input-wrap">
            <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <input type="password" id="pw2" name="password2" required minlength="6" placeholder="Confirm new password">
          </div>
        </div>
        <button type="submit" class="btn-login">
          <span>Set New Password</span>
          <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
        </button>
      </form>

      <?php elseif ($step === 'done'): ?>
      <!-- STEP: Done -->
      <div class="form-header">
        <h2>Password reset!</h2>
        <p>Your password has been updated successfully</p>
      </div>
      <div class="alert-success">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        <div>Your password has been changed. You can now log in with your new password.</div>
      </div>
      <a href="login.php" class="btn-login" style="text-decoration:none;margin-top:16px;">
        <span>Go to Login</span>
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
      </a>

      <?php else: // invalid token ?>
      <!-- STEP: Invalid/Expired -->
      <div class="form-header">
        <h2>Link expired</h2>
        <p>This reset link is invalid or has expired</p>
      </div>
      <div class="alert-error">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        Reset links expire after 60 minutes. Please request a new one.
      </div>
      <a href="forgot_password.php" class="btn-login" style="text-decoration:none;margin-top:8px;">
        <span>Request New Link</span>
      </a>
      <?php endif; ?>

      <p class="form-footer"><a href="login.php">← Back to Login</a></p>
    </div>
  </div>
</div>
<script>
function togglePw(id) {
  const el = document.getElementById(id);
  if (el) el.type = el.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>
