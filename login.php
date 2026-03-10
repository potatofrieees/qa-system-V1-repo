<?php
session_start();
include 'database/db_connect.php';
require_once 'mail/emails.php';

/* ── Already logged in ──────────────────────────────────────── */
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $d_role=$_SESSION['role']??''; header('Location: '.($d_role==='qa_director'||$d_role==='qa_staff'?'admin':($d_role==='student'?'student':'user')).'/dashboard.php');
    exit;
}

$error = $success = '';
$step  = $_SESSION['otp_step'] ?? 'login'; // 'login' | 'otp'

// These track whether user hit an inactive/suspended wall — used to show contact link inline
$show_contact = false;
$contact_email  = '';
$contact_status = '';

/* ── POST: Step 1 — credentials ─────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='login') {
    $email = trim($_POST['email']??'');
    $pw    = $_POST['password']??'';

    if (!$email || !$pw) {
        $error = 'Email and password are required.';
    } else {
        $stmt = $conn->prepare("SELECT u.*, r.role_key, r.role_label FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=? AND u.deleted_at IS NULL LIMIT 1");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $error = 'Invalid email or password.';
        } elseif ($row['status'] === 'inactive') {
            $error = 'Your account has been deactivated.';
            $show_contact  = true;
            $contact_email  = $email;
            $contact_status = 'inactive';
        } elseif ($row['status'] === 'suspended') {
            $error = 'Your account has been suspended.';
            $show_contact  = true;
            $contact_email  = $email;
            $contact_status = 'suspended';
        } elseif ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $until = date('H:i', strtotime($row['locked_until']));
            $error = "Account temporarily locked due to too many failed attempts. Try again after $until.";
        } elseif (!password_verify($pw, $row['password'])) {
            $fails = (int)$row['failed_attempts'] + 1;
            $lock  = '';
            if ($fails >= 5) {
                $lock = ", locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE), failed_attempts=0";
                $error = 'Too many failed attempts. Account locked for 30 minutes.';
            } else {
                $rem   = 5 - $fails;
                $error = "Invalid email or password. ($rem attempt".($rem===1?'':'s')." remaining)";
            }
            $conn->query("UPDATE users SET failed_attempts=$fails $lock WHERE id={$row['id']}");
        } else {
            // Credentials OK — generate OTP
            $otp    = str_pad((string)random_int(0, 10**OTP_DIGITS - 1), OTP_DIGITS, '0', STR_PAD_LEFT);
            $expiry = date('Y-m-d H:i:s', strtotime('+'.OTP_EXPIRY_MINUTES.' minutes'));
            $oe     = $conn->real_escape_string($otp);
            $conn->query("UPDATE users SET otp_code='$oe', otp_expiry='$expiry' WHERE id={$row['id']}");

            $_SESSION['otp_step']    = 'otp';
            $_SESSION['otp_user_id'] = $row['id'];
            $_SESSION['otp_email']   = $row['email'];
            $_SESSION['otp_name']    = $row['name'];
            $_SESSION['otp_role']    = $row['role_key'];
            $_SESSION['otp_rl']      = $row['role_label'];
            $_SESSION['otp_ci']      = $row['college_id'];
            // department_id removed
            $_SESSION['otp_row']     = array_intersect_key($row, array_flip(['id','name','email','role_key','role_label','college_id']));

            mail_otp($row['email'], $row['name'], $otp);

            $step = 'otp';
            $success = "A 6-digit verification code has been sent to <strong>".htmlspecialchars($row['email'])."</strong>.";
        }
    }
}

/* ── POST: Step 2 — OTP verify ──────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='otp') {
    $entered = trim($_POST['otp']??'');
    $uid     = (int)($_SESSION['otp_user_id']??0);

    if (!$uid) { session_destroy(); header('Location: login.php'); exit; }

    $row = $conn->query("SELECT otp_code, otp_expiry, status FROM users WHERE id=$uid AND deleted_at IS NULL")->fetch_assoc();

    if (!$row || $row['status']!=='active') {
        $error = 'User not found or account deactivated.';
        $step  = 'otp';
    } elseif (!$row['otp_code'] || $row['otp_code'] !== $entered) {
        $error = 'Invalid verification code.';
        $step  = 'otp';
    } elseif (strtotime($row['otp_expiry']) < time()) {
        $error = 'Verification code expired. Please log in again.';
        session_destroy(); session_start(); $step = 'login';
    } else {
        $conn->query("UPDATE users SET otp_code=NULL, otp_expiry=NULL, failed_attempts=0, last_login_at=NOW(), last_login_ip='".$conn->real_escape_string($_SERVER['REMOTE_ADDR']??'')."' WHERE id=$uid");
        $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address) VALUES ($uid,'LOGIN','users',$uid,'User logged in (OTP verified)','".$conn->real_escape_string($_SERVER['REMOTE_ADDR']??'')."')");

        $data = $_SESSION['otp_row'];
        foreach(['otp_step','otp_user_id','otp_email','otp_name','otp_role','otp_rl','otp_ci','otp_di','otp_row'] as $k) unset($_SESSION[$k]);

        $_SESSION['user_id']    = $data['id'];
        $_SESSION['name']       = $data['name'];
        $_SESSION['email']      = $data['email'];
        $_SESSION['role']       = $data['role_key'];
        $_SESSION['role_label'] = $data['role_label'];
        $_SESSION['college_id'] = $data['college_id'];
        // department_id removed

        $dest = in_array($data['role_key'],['qa_director','qa_staff']) ? 'admin/dashboard.php' : ($data['role_key']==='student' ? 'student/dashboard.php' : 'user/dashboard.php');
        header("Location: $dest"); exit;
    }
}

/* ── POST: Resend OTP ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='resend') {
    $uid = (int)($_SESSION['otp_user_id']??0);
    if ($uid) {
        $otp    = str_pad((string)random_int(0, 10**OTP_DIGITS - 1), OTP_DIGITS, '0', STR_PAD_LEFT);
        $expiry = date('Y-m-d H:i:s', strtotime('+'.OTP_EXPIRY_MINUTES.' minutes'));
        $oe     = $conn->real_escape_string($otp);
        $conn->query("UPDATE users SET otp_code='$oe', otp_expiry='$expiry' WHERE id=$uid");
        mail_otp($_SESSION['otp_email']??'', $_SESSION['otp_name']??'', $otp);
        $step    = 'otp';
        $success = 'A new code has been sent to <strong>'.htmlspecialchars($_SESSION['otp_email']??'').'</strong>.';
    } else {
        session_destroy(); header('Location: login.php'); exit;
    }
}

/* ── POST: Back to login ────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='back') {
    session_destroy(); session_start(); $step = 'login';
}

$step = $_SESSION['otp_step'] ?? $step;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>QAIAO Portal — <?=$step==='otp'?'Verification':'Sign In'?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
    /* contact modal field */
    .modal-field-label{font-size:.72rem;font-weight:500;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:6px;}
    .modal-field-input{width:100%;padding:10px 14px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.15);border-radius:8px;font-family:'Outfit',sans-serif;font-size:.85rem;color:white;box-sizing:border-box;outline:none;margin-bottom:14px;}
    .modal-field-input:focus{border-color:#c9a84c;}
    .account-info-box{background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.2);border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.82rem;color:rgba(255,255,255,.7);}
    .account-info-box strong{color:#e8c96e;}
    </style>
</head>
<body>

<!-- Full-screen background -->
<div class="bg-scene">
    <img src="images/UEP_BG.jpg" alt="" class="bg-image" onerror="this.style.display='none'">
    <div class="bg-overlay"></div>
</div>

<!-- Left side brand panel (hidden on mobile) -->
<div class="side-brand">
    <p class="univ-eyebrow">University of Eastern Pangasinan</p>
    <h2 class="univ-name">Quality Assurance,<br>Internationalization<br>&amp; Accreditation Office</h2>
    <div class="brand-rule"></div>
    <p class="side-tagline">Streamlining accreditation workflows and document management for academic excellence and institutional compliance.</p>

    <!-- Core Values -->
    <div class="core-values">
        <div class="cv-label">Core Values</div>
        <div class="cv-item">
            <div class="cv-icon">⚖</div>
            <div class="cv-text">
                <span class="cv-name">Uprightness</span>
                <span class="cv-desc">Integrity in every action</span>
            </div>
        </div>
        <div class="cv-item">
            <div class="cv-icon">✦</div>
            <div class="cv-text">
                <span class="cv-name">Excellence</span>
                <span class="cv-desc">Pursuit of the highest standards</span>
            </div>
        </div>
        <div class="cv-item">
            <div class="cv-icon">♥</div>
            <div class="cv-text">
                <span class="cv-name">Passion</span>
                <span class="cv-desc">Dedication to growth and service</span>
            </div>
        </div>
    </div>
</div>

<div class="page-wrapper">
    <!-- Glass Login Card -->
    <div class="login-card">

        <!-- Card Header -->
        <div class="card-header">
            <img src="images/UEP_logo.png" alt="UEP Logo" class="school-logo" onerror="this.style.display='none'">
            <h1 class="card-title"><?=$step==='otp'?'Verify Identity':'Welcome Back'?></h1>
            <p class="card-sub"><?=$step==='otp'?'Enter the 6-digit code sent to your email':'Sign in to continue to the QAIAO Portal'?></p>
        </div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step-dot <?=$step==='login'?'active':''?>"></div>
            <div class="step-dot <?=$step==='otp'?'active':''?>"></div>
        </div>

        <?php if ($step === 'login'): ?>
        <!-- ── STEP 1: LOGIN ── -->

        <?php if($error): ?>
        <div class="alert-error">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span><?=$error?></span>
        </div>
        <?php if($show_contact): ?>
        <div class="contact-banner">
            <span>Need access restored?</span>
            <a href="#" onclick="openContactModal(event)">Contact Administrator →</a>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="step" value="login">
            <div class="field-group">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg>
                    <input type="email" id="email" name="email" placeholder="Enter your email address" required
                           value="<?=isset($_POST['email'])?htmlspecialchars($_POST['email']):''?>">
                </div>
            </div>
            <div class="field-group">
                <label for="password">Password
                    <a href="forgot_password.php" class="forgot-link">Forgot password?</a>
                </label>
                <div class="input-wrap">
                    <svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <button type="button" class="toggle-pw" onclick="togglePw()">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <span>Sign In</span>
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
            </button>
        </form>

        <?php else: // step === 'otp' ?>
        <!-- ── STEP 2: OTP ── -->

        <?php if($error):?>
        <div class="alert-error">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
            <span><?=$error?></span>
        </div>
        <?php endif;?>
        <?php if($success):?>
        <div class="alert-success">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <span><?=$success?></span>
        </div>
        <?php endif;?>

        <form method="POST" id="otpForm">
            <input type="hidden" name="step" value="otp">
            <div class="otp-inputs">
                <?php for($i=0;$i<6;$i++): ?>
                <input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]"
                       id="otp<?=$i?>" class="otp-digit" autocomplete="<?=$i===0?'one-time-code':'off'?>">
                <?php endfor; ?>
                <input type="hidden" name="otp" id="otpValue">
            </div>
            <button type="submit" class="btn-login" id="verifyBtn">
                <span>Verify &amp; Sign In</span>
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
            </button>
        </form>

        <div style="text-align:center;margin-top:16px;font-size:0.74rem;color:rgba(255,255,255,0.35);">
            Didn't receive a code?
            <form method="POST" style="display:inline;">
                <input type="hidden" name="step" value="resend">
                <button type="submit" class="resend-link">Resend code</button>
            </form>
            &nbsp;·&nbsp;
            <form method="POST" style="display:inline;">
                <input type="hidden" name="step" value="back">
                <button type="submit" class="resend-link">← Back</button>
            </form>
        </div>
        <?php endif; ?>

        <div style="text-align:center;margin-top:10px;font-size:.8rem;color:rgba(255,255,255,.4);">New student? <a href="register.php" style="color:#C9A84C;font-weight:600;text-decoration:none;">Create an account →</a></div>
        <p class="form-footer">Having trouble? <a href="#" onclick="openContactModal(event)">Contact system administrator</a></p>
    </div>
</div>

<!-- Bottom bar -->
<div class="bottom-bar">
    <span>© <?=date('Y')?> University of Eastern Pangasinan</span>
    <span class="dot">•</span>
    <span>QAIAO Portal</span>
    <span class="dot">•</span>
    <span>All rights reserved</span>
</div>

<!-- Contact Admin Modal -->
<div class="contact-modal-bg" id="contactModalBg">
    <div class="contact-modal">
        <button class="contact-modal-close" onclick="closeContactModal()" title="Close">✕</button>

        <div id="contactFormState">
            <h3>Contact Administrator</h3>
            <p>Send a request to restore or review your account access.</p>

            <?php if($show_contact): ?>
            <div class="account-info-box">
                <strong>Account:</strong> <?=htmlspecialchars($contact_email)?><br>
                <strong>Status:</strong> <?=ucfirst($contact_status)?>
            </div>
            <?php else: ?>
            <label class="modal-field-label" for="contactEmailInput">Your Email Address</label>
            <input type="email" id="contactEmailInput" class="modal-field-input" placeholder="your@email.com">
            <?php endif; ?>

            <textarea id="contactNote" placeholder="Optional: briefly describe your situation…" rows="3"></textarea>
            <button class="send-btn" id="contactSendBtn" onclick="sendContactRequest()">Send Request</button>
        </div>

        <div id="contactSuccessState" class="success-state" style="display:none;">
            <div class="check">✅</div>
            <p style="color:#4ade80;font-weight:700;font-size:1rem;">Request Sent!</p>
            <p style="color:rgba(255,255,255,.5);">An administrator will review your account shortly.</p>
            <button onclick="closeContactModal()" style="margin-top:16px;padding:10px 24px;background:#8B0000;color:white;border:none;border-radius:8px;font-family:'Outfit',sans-serif;font-size:.9rem;cursor:pointer;">Close</button>
        </div>
    </div>
</div>

<?php
$js_contact_email  = json_encode($contact_email);
$js_contact_status = json_encode($contact_status ?: 'inactive');
?>
<script>
var _contactEmail  = <?= $js_contact_email ?>;
var _contactStatus = <?= $js_contact_status ?>;

function openContactModal(e) {
    if (e && e.preventDefault) e.preventDefault();
    document.getElementById('contactModalBg').classList.add('open');
    document.getElementById('contactFormState').style.display = 'block';
    document.getElementById('contactSuccessState').style.display = 'none';
    var btn = document.getElementById('contactSendBtn');
    if (btn) { btn.disabled = false; btn.textContent = 'Send Request'; }
    var note = document.getElementById('contactNote');
    if (note) note.value = '';
}

function closeContactModal() {
    document.getElementById('contactModalBg').classList.remove('open');
}

document.getElementById('contactModalBg').addEventListener('click', function(e) {
    if (e.target === this) closeContactModal();
});

function sendContactRequest() {
    var btn  = document.getElementById('contactSendBtn');
    var note = document.getElementById('contactNote').value.trim();
    var email = _contactEmail;
    if (!email) {
        var inp = document.getElementById('contactEmailInput');
        email = inp ? inp.value.trim() : '';
    }
    if (!email) {
        var loginEmail = document.getElementById('email');
        email = loginEmail ? loginEmail.value.trim() : '';
    }
    if (!email) {
        alert('Please enter your email address.');
        var inp2 = document.getElementById('contactEmailInput');
        if (inp2) inp2.focus();
        return;
    }
    btn.disabled = true;
    btn.textContent = 'Sending…';
    var fd = new FormData();
    fd.append('email', email);
    fd.append('reason', _contactStatus);
    fd.append('note', note);
    fetch('contact_admin.php', { method: 'POST', body: fd })
        .then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
        .then(function() {
            Swal.fire({
                icon: 'success',
                title: 'Request Sent!',
                text: 'An administrator will review your account shortly.',
                confirmButtonText: 'Close',
                customClass: { confirmButton: 'qa-login-swal-btn' },
                buttonsStyling: false,
            }).then(() => closeContactModal());
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Send Request';
            alert('Could not send request. Please try again or contact your administrator directly.');
        });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeContactModal();
});

function togglePw() {
    var p = document.getElementById('password');
    if (p) p.type = p.type === 'password' ? 'text' : 'password';
}

// OTP input auto-advance
var digits = document.querySelectorAll('.otp-digit');
var otpVal = document.getElementById('otpValue');
if (digits.length) {
    digits[0] && digits[0].focus();
    digits.forEach(function(inp, i) {
        inp.addEventListener('input', function() {
            inp.value = inp.value.replace(/\D/g, '').slice(-1);
            if (inp.value && i < digits.length - 1) digits[i+1].focus();
            collectOtp();
        });
        inp.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !inp.value && i > 0) { digits[i-1].focus(); digits[i-1].value = ''; collectOtp(); }
            if (e.key === 'ArrowLeft' && i > 0) digits[i-1].focus();
            if (e.key === 'ArrowRight' && i < digits.length-1) digits[i+1].focus();
        });
        inp.addEventListener('paste', function(e) {
            e.preventDefault();
            var paste = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
            paste.slice(0,6).split('').forEach(function(ch, j) { if (digits[i+j]) digits[i+j].value = ch; });
            var next = Math.min(i + paste.length, 5);
            if (digits[next]) digits[next].focus();
            collectOtp();
        });
    });
    var otpForm = document.getElementById('otpForm');
    if (otpForm) {
        otpForm.addEventListener('submit', function(e) {
            collectOtp();
            if ((otpVal ? otpVal.value : '').length < 6) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Incomplete Code',
                    text: 'Please enter the complete 6-digit verification code.',
                    confirmButtonText: 'OK',
                    customClass: { confirmButton: 'qa-login-swal-btn' },
                    buttonsStyling: false,
                });
            }
        });
    }
}
function collectOtp() { if (otpVal) otpVal.value = Array.from(digits).map(function(d){return d.value;}).join(''); }
</script>
<style>
.qa-login-swal-btn {
    background: #8B0000 !important;
    color: white !important;
    border: none !important;
    border-radius: 8px !important;
    padding: 10px 28px !important;
    font-family: 'Outfit', sans-serif !important;
    font-size: .9rem !important;
    font-weight: 600 !important;
    cursor: pointer !important;
}
.qa-login-swal-btn:hover { background: #6b0000 !important; }
</style>
</body>
</html>
