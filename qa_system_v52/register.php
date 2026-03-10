<?php
session_start();
include 'database/db_connect.php';

// Already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: '.($_SESSION['role']==='qa_director'||$_SESSION['role']==='qa_staff'?'admin':'user').'/dashboard.php');
    exit;
}

$error = $success = '';
$step = $_SESSION['reg_step'] ?? 'form'; // form | otp | done

/* ── STEP 1: Submit registration form ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='register') {
    $name     = trim($_POST['full_name'] ?? '');
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $phone    = trim($_POST['phone'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $dept_off = trim($_POST['department_office'] ?? '');
    $program_id = (int)($_POST['program_id'] ?? 0);
    $college_id = (int)($_POST['college_id'] ?? 0);
    $pw       = $_POST['password'] ?? '';
    $pw2      = $_POST['password2'] ?? '';
    $consent  = isset($_POST['consent']);

    if (!$consent) { $error = 'You must consent to data collection to register.'; }
    elseif (!$name || !$email || !$pw) { $error = 'Full name, email, and password are required.'; }
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $error = 'Please enter a valid email address.'; }
    elseif (strlen($pw) < 8) { $error = 'Password must be at least 8 characters.'; }
    elseif ($pw !== $pw2) { $error = 'Passwords do not match.'; }
    else {
        // Check if email already exists
        $chk = $conn->prepare("SELECT id FROM users WHERE email=? AND deleted_at IS NULL");
        $chk->bind_param('s', $email); $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = 'An account with this email already exists. <a href="login.php">Sign in instead.</a>';
        } else {
            // Get student role id
            $role_row = $conn->query("SELECT id FROM roles WHERE role_key='student' LIMIT 1")->fetch_assoc();
            if (!$role_row) { $error = 'Student registration is not currently available. Please contact the administrator.'; }
            else {
                $role_id = $role_row['id'];
                $pw_hash = password_hash($pw, PASSWORD_DEFAULT);
                $ne = $conn->real_escape_string($name);
                $ee = $conn->real_escape_string($email);
                $phe = $conn->real_escape_string($phone);
                $pid = $program_id > 0 ? $program_id : 'NULL';
                $cid = $college_id > 0 ? $college_id : 'NULL';
                $notes_e = $conn->real_escape_string($position . ($dept_off ? ' — ' . $dept_off : ''));

                $conn->query("INSERT INTO users (name,email,phone,password,role_id,program_id,college_id,status,employee_id)
                              VALUES ('$ne','$ee','$phe','$pw_hash',$role_id,$pid,$cid,'active',NULL)");
                $new_uid = $conn->insert_id;

                if ($new_uid) {
                    // Send OTP for email verification
                    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $exp = date('Y-m-d H:i:s', strtotime('+15 minutes'));
                    $oe  = $conn->real_escape_string($otp);
                    $conn->query("UPDATE users SET otp_code='$oe',otp_expiry='$exp' WHERE id=$new_uid");

                    // Notify QA staff about new registration
                    $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
                    if ($admins) while ($adm = $admins->fetch_assoc())
                        $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$adm['id']},'system','New Student Registration','A new student account was created: ".addslashes($name)." ($email)')");

                    // Try to send email if mail function available
                    if (function_exists('mail_otp')) {
                        try { mail_otp($email, $name, $otp); } catch(Exception $ex) {}
                    }

                    $_SESSION['reg_step']  = 'otp';
                    $_SESSION['reg_uid']   = $new_uid;
                    $_SESSION['reg_email'] = $email;
                    $_SESSION['reg_name']  = $name;
                    $step = 'otp';
                    $success = "Account created! A verification code was sent to <strong>$email</strong>.";
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        }
        $chk->close();
    }
}

/* ── STEP 2: OTP Verification ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='verify_otp') {
    $entered = trim($_POST['otp'] ?? '');
    $uid     = (int)($_SESSION['reg_uid'] ?? 0);
    if (!$uid) { session_destroy(); header('Location: register.php'); exit; }

    $row = $conn->query("SELECT otp_code,otp_expiry FROM users WHERE id=$uid")->fetch_assoc();
    if (!$row || $row['otp_code'] !== $entered) {
        $error = 'Invalid verification code.'; $step = 'otp';
    } elseif (strtotime($row['otp_expiry']) < time()) {
        $error = 'Code expired. Please re-register or request a new code.'; $step = 'otp';
    } else {
        $conn->query("UPDATE users SET email_verified=1,otp_code=NULL,otp_expiry=NULL WHERE id=$uid");
        $conn->query("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,description,ip_address) VALUES ($uid,'REGISTER','users',$uid,'Student self-registration','".$conn->real_escape_string($_SERVER['REMOTE_ADDR']??'')."')");

        // Log them in
        $data = $conn->query("SELECT u.*,r.role_key,r.role_label FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=$uid")->fetch_assoc();
        foreach(['reg_step','reg_uid','reg_email','reg_name'] as $k) unset($_SESSION[$k]);
        $_SESSION['user_id']    = $data['id'];
        $_SESSION['name']       = $data['name'];
        $_SESSION['email']      = $data['email'];
        $_SESSION['role']       = $data['role_key'];
        $_SESSION['role_label'] = $data['role_label'];
        $_SESSION['college_id'] = $data['college_id'];
        // department_id removed
        header('Location: student/dashboard.php'); exit;
    }
}

/* ── Resend OTP ── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['step']??'')==='resend_reg') {
    $uid = (int)($_SESSION['reg_uid'] ?? 0);
    if ($uid) {
        $otp = str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
        $exp = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        $oe  = $conn->real_escape_string($otp);
        $conn->query("UPDATE users SET otp_code='$oe',otp_expiry='$exp' WHERE id=$uid");
        if (function_exists('mail_otp')) { try { mail_otp($_SESSION['reg_email']??'', $_SESSION['reg_name']??'', $otp); } catch(Exception $ex){} }
        $step = 'otp'; $success = 'New code sent to <strong>'.htmlspecialchars($_SESSION['reg_email']??'').'</strong>.';
    }
}

$step = $_SESSION['reg_step'] ?? $step;

// Load programs and colleges for dropdown
$programs_q = $conn->query("SELECT p.id, p.program_name, c.college_name FROM programs p LEFT JOIN colleges c ON c.id=p.college_id WHERE p.status='active' ORDER BY c.college_name, p.program_name");
$colleges_q = $conn->query("SELECT id, college_name FROM colleges ORDER BY college_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Student Registration — QAIAO Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/login.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.reg-card{max-width:620px!important;}
.reg-section{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:18px 20px;margin-bottom:6px;}
.reg-section-title{font-size:.68rem;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:1px;margin-bottom:14px;}
.reg-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.consent-box{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:14px 16px;font-size:.82rem;color:var(--white-70);line-height:1.6;margin-bottom:12px;}
.consent-check{display:flex;align-items:flex-start;gap:10px;font-size:.82rem;color:var(--white-70);cursor:pointer;}
.consent-check input[type="radio"],.consent-check input[type="checkbox"]{width:18px;height:18px;margin-top:2px;flex-shrink:0;accent-color:var(--gold);}
.already-link{text-align:center;font-size:.8rem;color:var(--white-50);margin-top:14px;}
.already-link a{color:var(--gold);text-decoration:none;font-weight:600;}
.already-link a:hover{text-decoration:underline;}
@media(max-width:600px){.reg-grid-2{grid-template-columns:1fr;}}
</style>
</head>
<body>
<div class="bg-scene">
  <img src="images/UEP_BG.jpg" alt="" class="bg-image" onerror="this.style.display='none'">
  <div class="bg-overlay"></div>
</div>

<div class="side-brand">
  <p class="univ-eyebrow">University of Eastern Pangasinan</p>
  <h2 class="univ-name">Quality Assurance,<br>Internationalization<br>&amp; Accreditation Office</h2>
  <div class="brand-rule"></div>
  <p class="side-tagline">Create your student account to book appointments, submit proposals, and access QA services.</p>
  <div class="core-values">
    <div class="cv-label">Student Services</div>
    <div class="cv-item"><div class="cv-icon">📅</div><div class="cv-text"><span class="cv-name">Appointments</span><span class="cv-desc">Book consultations with QA office</span></div></div>
    <div class="cv-item"><div class="cv-icon">📋</div><div class="cv-text"><span class="cv-name">Proposals</span><span class="cv-desc">Submit research &amp; thesis proposals</span></div></div>
    <div class="cv-item"><div class="cv-icon">🏢</div><div class="cv-text"><span class="cv-name">Room Reservations</span><span class="cv-desc">Reserve office space for events</span></div></div>
  </div>
</div>

<div class="page-wrapper" style="align-items:flex-start;padding:40px 20px;overflow-y:auto;min-height:100vh;">
  <div class="login-card reg-card">
    <div class="card-header">
      <img src="images/UEP_logo.png" alt="UEP Logo" class="school-logo" onerror="this.style.display='none'">
      <h1 class="card-title"><?=$step==='otp'?'Verify Email':'Student Registration'?></h1>
      <p class="card-sub"><?=$step==='otp'?'Enter the 6-digit code sent to your email':'Create your QAIAO Portal student account'?></p>
    </div>

    <?php if($error):?>
    <div class="alert-error"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg><span><?=$error?></span></div>
    <?php endif;?>
    <?php if($success):?>
    <div class="alert-success"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><span><?=$success?></span></div>
    <?php endif;?>

    <?php if($step==='form'): ?>
    <!-- REGISTRATION FORM -->
    <form method="POST" id="regForm">
      <input type="hidden" name="step" value="register">

      <!-- Consent -->
      <div class="reg-section">
        <div class="consent-box">
          By filling out this form, I voluntarily provide my personal information for the purpose of office reservation and services. I consent to the collection and use of my personal data in accordance with the <strong>Data Privacy Act of 2012</strong>. My information will not be shared with unauthorized third parties.
        </div>
        <label class="consent-check">
          <input type="checkbox" name="consent" value="1" required>
          <span>Yes, I consent to the collection and use of my personal data as described above. *</span>
        </label>
      </div>

      <!-- A. Requestor's Information -->
      <div class="reg-section" style="margin-top:14px;">
        <div class="reg-section-title">A. Requestor's Information</div>
        <div class="reg-grid-2">
          <div class="field-group" style="grid-column:1/-1;">
            <label>Full Name *</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z" clip-rule="evenodd"/></svg><input type="text" name="full_name" placeholder="Your full name" required value="<?=htmlspecialchars($_POST['full_name']??'')?>"></div>
          </div>
          <div class="field-group">
            <label>Position / Designation *</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"/></svg><input type="text" name="position" placeholder="e.g. Student, Researcher" required value="<?=htmlspecialchars($_POST['position']??'')?>"></div>
          </div>
          <div class="field-group">
            <label>Department / Office *</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4zm3 1h2v2H7V5zm2 4H7v2h2V9zm2-4h2v2h-2V5zm2 4h-2v2h2V9z" clip-rule="evenodd"/></svg><input type="text" name="department_office" placeholder="e.g. College of Education" required value="<?=htmlspecialchars($_POST['department_office']??'')?>"></div>
          </div>
          <div class="field-group">
            <label>College</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3z"/></svg>
              <select name="college_id" style="padding-left:36px;width:100%;background:var(--input-bg);border:1px solid var(--input-border);border-radius:8px;color:white;font-family:'Outfit',sans-serif;font-size:.9rem;padding:10px 12px 10px 36px;outline:none;">
                <option value="">— Select College —</option>
                <?php if($colleges_q) while($cg=$colleges_q->fetch_assoc()):?><option value="<?=$cg['id']?>" <?=(isset($_POST['college_id'])&&$_POST['college_id']==$cg['id'])?'selected':''?>><?=htmlspecialchars($cg['college_name'])?></option><?php endwhile;?>
              </select>
            </div>
          </div>
          <div class="field-group">
            <label>Program</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12a1 1 0 11-2 0V4.804z"/></svg>
              <select name="program_id" style="padding-left:36px;width:100%;background:var(--input-bg);border:1px solid var(--input-border);border-radius:8px;color:white;font-family:'Outfit',sans-serif;font-size:.9rem;padding:10px 12px 10px 36px;outline:none;">
                <option value="">— Select Program —</option>
                <?php if($programs_q) while($pg=$programs_q->fetch_assoc()):?><option value="<?=$pg['id']?>" <?=(isset($_POST['program_id'])&&$_POST['program_id']==$pg['id'])?'selected':''?>><?=htmlspecialchars($pg['program_name'])?> <?=$pg['college_name']?'('.$pg['college_name'].')':''?></option><?php endwhile;?>
              </select>
            </div>
          </div>
          <div class="field-group">
            <label>Email Address *</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"/><path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"/></svg><input type="email" name="email" placeholder="your@email.com" required value="<?=htmlspecialchars($_POST['email']??'')?>"></div>
          </div>
          <div class="field-group">
            <label>Phone Number</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"/></svg><input type="tel" name="phone" placeholder="e.g. 09XX-XXX-XXXX" value="<?=htmlspecialchars($_POST['phone']??'')?>"></div>
          </div>
        </div>
      </div>

      <!-- B. Account Security -->
      <div class="reg-section" style="margin-top:14px;">
        <div class="reg-section-title">B. Account Security</div>
        <div class="reg-grid-2">
          <div class="field-group">
            <label>Password * <span style="font-size:.7rem;font-weight:400;opacity:.6;">(min. 8 characters)</span></label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg><input type="password" id="pw1" name="password" placeholder="Create a strong password" required minlength="8"><button type="button" class="toggle-pw" onclick="togglePw('pw1')"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg></button></div>
          </div>
          <div class="field-group">
            <label>Confirm Password *</label>
            <div class="input-wrap"><svg class="input-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg><input type="password" id="pw2" name="password2" placeholder="Repeat your password" required minlength="8"><button type="button" class="toggle-pw" onclick="togglePw('pw2')"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg></button></div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn-login" style="margin-top:20px;">
        <span>Create Account</span>
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
      </button>
    </form>
    <p class="already-link">Already have an account? <a href="login.php">Sign in here →</a></p>

    <?php else: // OTP step ?>
    <!-- OTP VERIFICATION -->
    <form method="POST" id="otpForm">
      <input type="hidden" name="step" value="verify_otp">
      <p style="font-size:.83rem;color:var(--white-70);text-align:center;margin-bottom:18px;">Enter the 6-digit code sent to <strong style="color:var(--gold);"><?=htmlspecialchars($_SESSION['reg_email']??'')?></strong></p>
      <div class="otp-inputs">
        <?php for($i=0;$i<6;$i++):?><input type="text" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp<?=$i?>" class="otp-digit" autocomplete="<?=$i===0?'one-time-code':'off'?>"><?php endfor;?>
        <input type="hidden" name="otp" id="otpValue">
      </div>
      <button type="submit" class="btn-login" id="verifyBtn"><span>Verify &amp; Continue</span><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></button>
    </form>
    <div style="text-align:center;margin-top:14px;font-size:.74rem;color:rgba(255,255,255,.35);">
      Didn't get a code? <form method="POST" style="display:inline;"><input type="hidden" name="step" value="resend_reg"><button type="submit" class="resend-link">Resend code</button></form>
    </div>
    <?php endif;?>

  </div>
</div>

<div class="bottom-bar">
  <span>© <?=date('Y')?> University of Eastern Pangasinan</span><span class="dot">•</span>
  <span>QAIAO Portal</span><span class="dot">•</span><span>Student Registration</span>
</div>

<script>
function togglePw(id){var p=document.getElementById(id);if(p)p.type=p.type==='password'?'text':'password';}
var digits=document.querySelectorAll('.otp-digit');
var otpVal=document.getElementById('otpValue');
if(digits.length){
  digits[0]&&digits[0].focus();
  digits.forEach(function(inp,i){
    inp.addEventListener('input',function(){inp.value=inp.value.replace(/\D/g,'').slice(-1);if(inp.value&&i<digits.length-1)digits[i+1].focus();collectOtp();});
    inp.addEventListener('keydown',function(e){if(e.key==='Backspace'&&!inp.value&&i>0){digits[i-1].focus();digits[i-1].value='';collectOtp();}});
    inp.addEventListener('paste',function(e){e.preventDefault();var p=(e.clipboardData||window.clipboardData).getData('text').replace(/\D/g,'');p.slice(0,6).split('').forEach(function(ch,j){if(digits[i+j])digits[i+j].value=ch;});collectOtp();});
  });
}
function collectOtp(){if(otpVal)otpVal.value=Array.from(digits).map(function(d){return d.value;}).join('');}
</script>
<style>.qa-login-swal-btn{background:#8B0000!important;color:white!important;border:none!important;border-radius:8px!important;padding:10px 28px!important;font-family:'Outfit',sans-serif!important;font-size:.9rem!important;font-weight:600!important;cursor:pointer!important;}</style>
</body>
</html>
