<?php
session_start();
include '../database/db_connect.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$active_nav = 'appointments';
$me = (int)$_SESSION['user_id'];

// Load user info for pre-fill
$me_info = $conn->query("SELECT u.*,r.role_label,p.program_name,c.college_name FROM users u LEFT JOIN roles r ON r.id=u.role_id LEFT JOIN programs p ON p.id=u.program_id LEFT JOIN colleges c ON c.id=u.college_id WHERE u.id=$me")->fetch_assoc();

/* ── POST Actions ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'book_slot') {
        $sid     = (int)$_POST['slot_id'];
        $purpose = $conn->real_escape_string(trim($_POST['purpose'] ?? ''));
        $notes   = $conn->real_escape_string(trim($_POST['notes'] ?? ''));
        $chosen_start = $conn->real_escape_string(trim($_POST['chosen_start'] ?? ''));
        $chosen_end   = $conn->real_escape_string(trim($_POST['chosen_end'] ?? ''));
        $slot    = $conn->query("SELECT * FROM appointment_slots WHERE id=$sid AND is_active=1")->fetch_assoc();
        if ($slot) {
            $is_open = !empty($slot['is_open_day']);
            if ($is_open && (!$chosen_start || !$chosen_end)) {
                header("Location: appointments.php?msg=".urlencode("Please choose your preferred time.")."&typ=e"); exit;
            }
            $booked  = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE slot_id=$sid AND status NOT IN ('cancelled')")->fetch_assoc()['c'];
            $already = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE slot_id=$sid AND booked_by=$me AND status NOT IN ('cancelled')")->fetch_assoc()['c'];
            if ($booked < $slot['capacity'] && !$already) {
                $cs = $chosen_start ? "'$chosen_start'" : 'NULL';
                $ce = $chosen_end   ? "'$chosen_end'"   : 'NULL';
                $conn->query("INSERT INTO appointments (slot_id,booked_by,purpose,notes,chosen_start,chosen_end) VALUES ($sid,$me,'$purpose','$notes',$cs,$ce)");
                $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
                $bn = $conn->real_escape_string($_SESSION['name']??'');
                $msg = $conn->real_escape_string("New appointment by $bn for {$slot['slot_date']} at {$slot['start_time']}");
                if ($admins) while ($adm = $admins->fetch_assoc())
                    $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$adm['id']},'system','New Appointment','$msg')");
                header("Location: appointments.php?msg=".urlencode("Appointment booked successfully!")."&typ=s"); exit;
            } elseif ($already) {
                header("Location: appointments.php?msg=".urlencode("You already booked this slot.")."&typ=e"); exit;
            } else {
                header("Location: appointments.php?msg=".urlencode("This slot is fully booked.")."&typ=e"); exit;
            }
        }
    }

    if ($act === 'cancel_appt') {
        $aid = (int)$_POST['appt_id'];
        $conn->query("UPDATE appointments SET status='cancelled',cancelled_by=$me WHERE id=$aid AND booked_by=$me");
        header("Location: appointments.php?tab=my&msg=".urlencode("Appointment cancelled.")."&typ=s"); exit;
    }

    if ($act === 'book_room') {
        // Full form fields from the reservation form
        $consent      = isset($_POST['consent']) ? 1 : 0;
        if (!$consent) { header("Location: appointments.php?tab=reservations&msg=".urlencode("You must provide consent to submit.")."&typ=e"); exit; }

        $full_name    = $conn->real_escape_string(trim($_POST['full_name'] ?? $me_info['name'] ?? ''));
        $position     = $conn->real_escape_string(trim($_POST['position'] ?? ''));
        $dept_off     = $conn->real_escape_string(trim($_POST['department_office'] ?? ''));
        $purpose_res  = $conn->real_escape_string(trim($_POST['purpose_reservation'] ?? ''));
        $date_of_use  = $conn->real_escape_string($_POST['date_of_use'] ?? '');
        $time_start   = $conn->real_escape_string($_POST['time_start'] ?? '');
        $time_end     = $conn->real_escape_string($_POST['time_end'] ?? '');
        $participants = $conn->real_escape_string(trim($_POST['num_participants'] ?? ''));
        $room_name    = $conn->real_escape_string(trim($_POST['room_name'] ?? 'QA Office'));
        $equipment    = $conn->real_escape_string(trim($_POST['equipment'] ?? ''));
        $add_notes    = $conn->real_escape_string(trim($_POST['additional_notes'] ?? ''));

        if (!$date_of_use || !$time_start || !$time_end || !$purpose_res) {
            header("Location: appointments.php?tab=reservations&msg=".urlencode("Please fill in all required fields.")."&typ=e"); exit;
        }

        $start_dt = $date_of_use . ' ' . $time_start;
        $end_dt   = $date_of_use . ' ' . $time_end;
        $desc = "$position — $dept_off. Equipment: $equipment. Notes: $add_notes";

        $conn->query("INSERT INTO room_reservations (title,description,room_name,reserved_by,start_datetime,end_datetime,attendees) VALUES ('$purpose_res','$desc','$room_name',$me,'$start_dt','$end_dt','$participants')");
        $rid = $conn->insert_id;

        if ($rid) {
            $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
            if ($admins) while ($adm = $admins->fetch_assoc())
                $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$adm['id']},'system','Room Reservation Request','New room reservation from $full_name for $date_of_use')");
        }
        header("Location: appointments.php?tab=reservations&msg=".urlencode("Room reservation submitted for review!")."&typ=s"); exit;
    }

    header("Location: appointments.php"); exit;
}

$tab      = in_array($_GET['tab'] ?? 'book', ['book','my','reservations']) ? $_GET['tab'] : 'book';
$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';
$today    = date('Y-m-d');

/* ── Calendar data ────────────────────────────────────────── */
$cal_end = date('Y-m-d', strtotime('+3 months'));
$all_slots_q = $conn->query("
    SELECT s.id,s.slot_date,s.start_time,s.end_time,s.location,s.purpose,s.capacity,
           (SELECT COUNT(*) FROM appointments a WHERE a.slot_id=s.id AND a.status NOT IN ('cancelled')) booked_count,
           (SELECT COUNT(*) FROM appointments a WHERE a.slot_id=s.id AND a.booked_by=$me AND a.status NOT IN ('cancelled')) my_booking
    FROM appointment_slots s
    LEFT JOIN schedule_blackouts sb ON sb.blackout_date=s.slot_date
    WHERE s.slot_date>='$today' AND s.slot_date<='$cal_end' AND s.is_active=1 AND sb.id IS NULL
    ORDER BY s.slot_date,s.start_time");

$slot_data=[]; $date_status=[];
if($all_slots_q) while($sl=$all_slots_q->fetch_assoc()){ $d=$sl['slot_date']; $slot_data[$d][]=$sl; }
foreach($slot_data as $d=>$slots){
    $mine=false; $avail=false;
    foreach($slots as $s){ if($s['my_booking']>0)$mine=true; if(($s['capacity']-$s['booked_count'])>0)$avail=true; }
    $date_status[$d]=$mine?'mine':($avail?'available':'full');
}

// Blackout dates for user to see
$bk_dates_q = $conn->query("SELECT blackout_date,reason,type FROM schedule_blackouts WHERE blackout_date>='$today' AND blackout_date<='$cal_end'");
$bk_dates=[];
if($bk_dates_q) while($b=$bk_dates_q->fetch_assoc()) $bk_dates[$b['blackout_date']]=$b;

/* ── My appointments ──────────────────────────────────────── */
$my_appts = $conn->query("SELECT a.*,s.slot_date,s.start_time,s.end_time,s.location FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id WHERE a.booked_by=$me ORDER BY s.slot_date DESC LIMIT 50");

/* ── My reservations ──────────────────────────────────────── */
$my_res = $conn->query("SELECT * FROM room_reservations WHERE reserved_by=$me ORDER BY start_datetime DESC LIMIT 50");

$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];
$upcoming_cnt= (int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id WHERE a.booked_by=$me AND a.status IN ('pending','confirmed') AND s.slot_date>='$today'")->fetch_assoc()['c'];
$avail_days = count(array_filter($date_status,fn($s)=>$s==='available'));
$full_days  = count(array_filter($date_status,fn($s)=>$s==='full'));
$mine_days  = count(array_filter($date_status,fn($s)=>$s==='mine'));

// Earliest available
$earliest = null;
foreach($date_status as $d=>$st){ if($st==='available'){ $earliest=$d; break; } }
$status_colors=['pending'=>['#fff7ed','#92400e','#fde68a'],'confirmed'=>['#ecfdf5','#065f46','#6ee7b7'],'cancelled'=>['#fef2f2','#991b1b','#fca5a5'],'completed'=>['#eff6ff','#1e40af','#93c5fd'],'no_show'=>['#f9fafb','#374151','#d1d5db'],'approved'=>['#ecfdf5','#065f46','#6ee7b7'],'rejected'=>['#fef2f2','#991b1b','#fca5a5']];
?>
<!DOCTYPE html><html lang="en"><head><title>Appointments — Student Portal</title><?php include 'head.php';?>
<style>
.s-cal{background:white;border-radius:16px;border:1.5px solid var(--border);overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.06);}
.s-cal-hd{background:linear-gradient(135deg,var(--primary),#a00000);padding:18px 22px 14px;color:white;}
.s-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.s-nbtn{width:34px;height:34px;border-radius:50%;border:1.5px solid rgba(255,255,255,.3);background:rgba(255,255,255,.12);color:white;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:.15s;font-family:inherit;}
.s-nbtn:hover:not(:disabled){background:rgba(255,255,255,.25)}.s-nbtn:disabled{opacity:.3;cursor:default}
.s-cal-month{font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:700;}
.s-hd-stats{display:flex;gap:7px;flex-wrap:wrap;}
.s-stat{display:flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;}
.s-stat.g{background:rgba(34,197,94,.2);border:1px solid rgba(34,197,94,.4);}
.s-stat.r{background:rgba(239,68,68,.2); border:1px solid rgba(239,68,68,.4);}
.s-stat.b{background:rgba(96,165,250,.2);border:1px solid rgba(96,165,250,.4);}
.s-stat.gray{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);}
.s-dn{display:grid;grid-template-columns:repeat(7,1fr);background:#f8f9fc;border-bottom:1.5px solid #e5e7eb;}
.s-dn div{text-align:center;padding:8px 0;font-size:.67rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#64748b;}
.s-dn div.we{color:#b91c1c;}
.s-grid{display:grid;grid-template-columns:repeat(7,1fr);}
.s-cell{min-height:72px;padding:7px 5px 5px;display:flex;flex-direction:column;align-items:center;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;background:white;transition:.14s;position:relative;overflow:hidden;}
.s-cell.other{background:#fafafa;}.s-cell.other .s-num{color:#d1d5db;}
.s-cell.s-today{background:#fefce8;}
.s-cell.s-sel{box-shadow:inset 0 0 0 2.5px var(--primary-light)!important;z-index:2;}
.s-cell.c-avail{background:linear-gradient(160deg,#f0fdf4,#dcfce7);border-bottom-color:#bbf7d0;border-right-color:#bbf7d0;cursor:pointer;}
.s-cell.c-avail:hover{background:linear-gradient(160deg,#dcfce7,#bbf7d0);transform:scale(1.02);z-index:1;}
.s-cell.c-full{background:linear-gradient(160deg,#fff5f5,#fee2e2);border-bottom-color:#fecaca;border-right-color:#fecaca;cursor:pointer;}
.s-cell.c-mine{background:linear-gradient(160deg,#eff6ff,#dbeafe);border-bottom-color:#bfdbfe;border-right-color:#bfdbfe;cursor:pointer;}
.s-cell.c-mine:hover{background:linear-gradient(160deg,#dbeafe,#bfdbfe);transform:scale(1.02);}
.s-cell.c-bk{background:repeating-linear-gradient(45deg,#f9fafb,#f9fafb 4px,#f1f5f9 4px,#f1f5f9 8px);}
.s-num{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:600;color:#374151;flex-shrink:0;margin-bottom:3px;}
.s-today .s-num{background:var(--primary);color:white;font-weight:700;}
.c-avail .s-num{color:#166534;font-weight:700;}.c-full .s-num{color:#991b1b;font-weight:700;}.c-mine .s-num{color:#1e40af;font-weight:700;}
.s-bar{position:absolute;bottom:0;left:0;right:0;height:3px;}
.c-avail .s-bar{background:#22c55e;}.c-full .s-bar{background:#ef4444;}.c-mine .s-bar{background:#3b82f6;}
.s-lbl{font-size:.58rem;font-weight:700;text-align:center;line-height:1.2;margin-top:2px;}
.c-avail .s-lbl{color:#15803d;}.c-full .s-lbl{color:#b91c1c;}.c-mine .s-lbl{color:#1d4ed8;}.c-bk .s-lbl{color:#9ca3af;font-size:.55rem;}
.s-legend{display:flex;align-items:center;gap:14px;flex-wrap:wrap;padding:12px 18px;border-top:1.5px solid #f1f5f9;background:#f8fafc;}
.s-leg{display:flex;align-items:center;gap:5px;font-size:.73rem;color:#64748b;}
.s-sw{width:13px;height:13px;border-radius:3px;border:1.5px solid transparent;}
.s-sw.avail{background:#dcfce7;border-color:#22c55e;}.s-sw.full{background:#fee2e2;border-color:#ef4444;}.s-sw.mine{background:#dbeafe;border-color:#3b82f6;}.s-sw.bk{background:repeating-linear-gradient(45deg,#f9fafb,#f9fafb 3px,#e5e7eb 3px,#e5e7eb 6px);border-color:#9ca3af;}.s-sw.td{background:var(--primary);border-radius:50%;}
/* Slot panel */
.slot-panel{background:white;border-radius:16px;border:1.5px solid var(--border);overflow:hidden;position:sticky;top:80px;}
.slot-panel-hd{padding:16px 18px;border-bottom:1.5px solid #f1f5f9;background:#f8fafc;}
.slot-panel-body{padding:14px;display:flex;flex-direction:column;gap:9px;max-height:440px;overflow-y:auto;}
.slot-item{border:1.5px solid #e5e7eb;border-radius:11px;padding:12px 13px;display:flex;gap:11px;align-items:flex-start;transition:.14s;}
.slot-item.avail{border-color:#86efac;background:#f0fdf4;}.slot-item.avail:hover{border-color:#22c55e;box-shadow:0 2px 12px rgba(22,163,74,.12);}
.slot-item.full{border-color:#fca5a5;background:#fff5f5;opacity:.8;}
.slot-item.mine{border-color:#93c5fd;background:#eff6ff;}
/* Reservation form */
.res-section{border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px;}
.res-section-hd{background:var(--primary);color:white;padding:12px 16px;font-weight:700;font-size:.85rem;letter-spacing:.3px;}
.res-section-body{padding:16px;display:flex;flex-direction:column;gap:13px;}
.res-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.consent-area{background:#fff8f8;border:1.5px solid #fca5a5;border-radius:10px;padding:14px 16px;font-size:.82rem;color:#374151;line-height:1.6;margin-bottom:14px;}
@media(max-width:900px){.appt-layout{grid-template-columns:1fr!important;}.slot-panel{position:static;}}
@media(max-width:600px){.s-cell{min-height:50px;padding:4px 2px;}.s-num{width:22px;height:22px;font-size:.7rem;}.s-lbl{display:none;}.res-grid-2{grid-template-columns:1fr;}}
</style>
</head>
<body><?php include 'sidebar.php';?>
<div class="main-content">
<div class="topbar">
  <button class="sidebar-toggle" id="sidebar-toggle"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg></button>
  <div class="topbar-title">Appointments &amp; Scheduling</div>
  <div class="topbar-right">
    <a href="notifications.php" class="notif-btn"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg><?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?></a>
  </div>
</div>
<div class="page-body">
  <?php if($message):?><div class="alert alert-<?=$msg_type?>"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg><?=$message?></div><?php endif;?>
  <div class="page-header" style="margin-bottom:18px;"><div><h1 class="page-heading">Appointments &amp; Room Reservations</h1><p class="page-subheading">Book a consultation with the QA office or reserve a room for your event</p></div></div>

  <!-- Tabs -->
  <div class="tabs-bar" style="margin-bottom:22px;">
    <a href="appointments.php?tab=book" class="tab-pill<?=$tab==='book'?' active':''?>">📅 Book Appointment</a>
    <a href="appointments.php?tab=my" class="tab-pill<?=$tab==='my'?' active':''?>">👤 My Appointments<?php if($upcoming_cnt>0):?> <span style="background:var(--primary);color:white;border-radius:12px;padding:0 7px;font-size:.68rem;"><?=$upcoming_cnt?></span><?php endif;?></a>
    <a href="appointments.php?tab=reservations" class="tab-pill<?=$tab==='reservations'?' active':''?>">🏢 Reserve a Room</a>
  </div>

  <?php if($tab==='book'): ?>
  <!-- ═══ BOOK TAB ════════════════════════════════════ -->
  <!-- Stats row -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:18px;">
    <div class="card" style="padding:14px;display:flex;align-items:center;gap:10px;"><div style="width:38px;height:38px;border-radius:10px;background:#f0fdf4;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">📅</div><div><div style="font-size:1.4rem;font-weight:800;color:#16a34a;"><?=$avail_days?></div><div style="font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:600;">Available Days</div></div></div>
    <div class="card" style="padding:14px;display:flex;align-items:center;gap:10px;"><div style="width:38px;height:38px;border-radius:10px;background:#fff5f5;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">🔴</div><div><div style="font-size:1.4rem;font-weight:800;color:#dc2626;"><?=$full_days?></div><div style="font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:600;">Fully Booked</div></div></div>
    <div class="card" style="padding:14px;display:flex;align-items:center;gap:10px;"><div style="width:38px;height:38px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">✅</div><div><div style="font-size:1.4rem;font-weight:800;color:#2563eb;"><?=$mine_days?></div><div style="font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;font-weight:600;">Your Bookings</div></div></div>
  </div>

  <?php if($earliest):?>
  <div style="display:flex;align-items:center;gap:10px;background:linear-gradient(90deg,#ecfdf5,#f0fdf4);border:1.5px solid #6ee7b7;border-radius:12px;padding:12px 16px;margin-bottom:18px;font-size:.85rem;color:#065f46;font-weight:600;">
    <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
    Earliest available: <strong style="color:#059669;"><?=date('l, F j, Y',strtotime($earliest))?></strong>
    <button onclick="jumpToDate('<?=$earliest?>')" style="margin-left:auto;background:#10b981;color:white;border:none;border-radius:8px;padding:4px 12px;font-size:.75rem;font-weight:700;cursor:pointer;font-family:inherit;">View →</button>
  </div>
  <?php endif;?>

  <div class="appt-layout" style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">
    <!-- Calendar -->
    <div class="s-cal">
      <div class="s-cal-hd">
        <div class="s-cal-nav"><button class="s-nbtn" id="s-prev" onclick="sNav(-1)">&#8249;</button><div class="s-cal-month" id="s-mlabel">—</div><button class="s-nbtn" id="s-next" onclick="sNav(1)">&#8250;</button></div>
        <div class="s-hd-stats">
          <div class="s-stat g">🟢 <span id="sn-avail">—</span> Available</div>
          <div class="s-stat r">🔴 <span id="sn-full">—</span> Full</div>
          <div class="s-stat b">✅ <span id="sn-mine">—</span> Yours</div>
          <div class="s-stat gray">🚫 <span id="sn-bk">—</span> Closed</div>
        </div>
      </div>
      <div class="s-dn"><?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i=>$d):?><div class="<?=$i===0||$i===6?'we':''?>"><?=$d?></div><?php endforeach;?></div>
      <div class="s-grid" id="s-grid"></div>
      <div class="s-legend">
        <div class="s-leg"><div class="s-sw td"></div> Today</div>
        <div class="s-leg"><div class="s-sw avail"></div> Available</div>
        <div class="s-leg"><div class="s-sw full"></div> Fully Booked</div>
        <div class="s-leg"><div class="s-sw mine"></div> Your Booking</div>
        <div class="s-leg"><div class="s-sw bk"></div> Office Closed</div>
      </div>
    </div>
    <!-- Slot panel -->
    <div class="slot-panel">
      <div class="slot-panel-hd"><div style="font-family:'Playfair Display',serif;font-size:.95rem;font-weight:700;" id="sp-title">Select a Date</div><div style="font-size:.75rem;color:var(--muted);" id="sp-hint">Click any coloured date on the calendar</div></div>
      <div class="slot-panel-body" id="sp-body"><div style="padding:40px 16px;text-align:center;color:var(--muted);"><div style="font-size:2rem;margin-bottom:8px;">📅</div><p style="font-size:.82rem;">Click a date on the calendar to view slots</p></div></div>
    </div>
  </div>

  <?php elseif($tab==='my'): ?>
  <!-- ═══ MY APPOINTMENTS ═══════════════════════════ -->
  <div class="card"><div class="table-wrapper"><table>
  <thead><tr><th>Date</th><th>Time</th><th>Location</th><th>Purpose</th><th>Status</th><th>Action</th></tr></thead>
  <tbody>
  <?php if(!$my_appts||$my_appts->num_rows===0):?><tr><td colspan="6"><div class="empty-state"><p>No appointments yet. <a href="appointments.php" style="color:var(--primary-light);">Book one now →</a></p></div></td></tr>
  <?php else: while($ap=$my_appts->fetch_assoc()): $c=$status_colors[$ap['status']]??['#f9fafb','#374151','#d1d5db'];$past=$ap['slot_date']<$today;?>
  <tr style="<?=$past?'opacity:.65':''?>">
    <td><div style="font-weight:700;font-size:.88rem;"><?=date('M j, Y',strtotime($ap['slot_date']))?></div><div style="font-size:.72rem;color:var(--muted);"><?=date('D',strtotime($ap['slot_date']))?></div></td>
    <td><div style="font-weight:600;font-size:.85rem;"><?=date('g:i A',strtotime($ap['start_time']))?></div><div style="font-size:.72rem;color:var(--muted);">to <?=date('g:i A',strtotime($ap['end_time']))?></div></td>
    <td class="text-sm"><?=htmlspecialchars($ap['location'])?></td>
    <td class="text-sm text-muted" style="max-width:140px;"><?=htmlspecialchars($ap['purpose']??'—')?></td>
    <td><span style="background:<?=$c[0]?>;color:<?=$c[1]?>;border:1.5px solid <?=$c[2]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?=ucfirst($ap['status'])?></span></td>
    <td><?php if(in_array($ap['status'],['pending','confirmed'])&&!$past):?><form method="POST" class="swal-confirm-form" data-title="Cancel Appointment?" data-icon="warning" data-confirm="Yes, Cancel" data-cls="qa-btn-red"><input type="hidden" name="form_action" value="cancel_appt"><input type="hidden" name="appt_id" value="<?=$ap['id']?>"><button type="submit" class="btn btn-danger btn-sm">Cancel</button></form><?php else:?><span style="font-size:.75rem;color:var(--muted);">—</span><?php endif;?></td>
  </tr>
  <?php endwhile; endif;?>
  </tbody></table></div></div>

  <?php elseif($tab==='reservations'): ?>
  <!-- ═══ ROOM RESERVATION FORM + HISTORY ═════════ -->

  <!-- My past reservations -->
  <?php if($my_res && $my_res->num_rows > 0): ?>
  <div class="card" style="margin-bottom:20px;">
    <div style="padding:14px 18px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
      <h3 style="font-size:.88rem;font-weight:700;">📋 My Reservation History</h3>
    </div>
    <div class="table-wrapper"><table>
    <thead><tr><th>Purpose</th><th>Room</th><th>Date</th><th>Time</th><th>Status</th><th>Remarks</th></tr></thead>
    <tbody>
    <?php while($res=$my_res->fetch_assoc()): $c=$status_colors[$res['status']]??['#f9fafb','#374151','#d1d5db'];?>
    <tr>
      <td style="max-width:160px;"><div style="font-weight:600;font-size:.85rem;"><?=htmlspecialchars($res['title'])?></div></td>
      <td class="text-sm"><?=htmlspecialchars($res['room_name'])?></td>
      <td class="text-sm"><?=date('M j, Y',strtotime($res['start_datetime']))?></td>
      <td class="text-sm"><?=date('g:i A',strtotime($res['start_datetime']))?> – <?=date('g:i A',strtotime($res['end_datetime']))?></td>
      <td><span style="background:<?=$c[0]?>;color:<?=$c[1]?>;border:1.5px solid <?=$c[2]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?=ucfirst($res['status'])?></span></td>
      <td style="font-size:.78rem;color:var(--muted);max-width:130px;"><?=htmlspecialchars($res['reject_reason']??'—')?></td>
    </tr>
    <?php endwhile;?>
    </tbody></table></div>
  </div>
  <?php endif;?>

  <!-- Reservation Form -->
  <form method="POST" id="resForm">
    <input type="hidden" name="form_action" value="book_room">
    <h2 style="font-size:1rem;font-weight:700;margin-bottom:14px;">📋 New Room Reservation Request</h2>

    <!-- Consent -->
    <div class="consent-area">
      <p style="margin-bottom:12px;">By filling out this nomination form, I voluntarily provide my personal information for the purpose of office reservation. I consent to the collection and use of my personal data in accordance with the <strong>Data Privacy Act of 2012</strong>. My information will not be shared with unauthorized third parties.</p>
      <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
        <input type="radio" name="consent" value="1" required style="margin-top:3px;accent-color:var(--primary);width:16px;height:16px;flex-shrink:0;">
        <span style="font-weight:600;">Yes</span>
      </label>
    </div>

    <!-- A. Requestor's Information -->
    <div class="res-section">
      <div class="res-section-hd">A. REQUESTOR'S INFORMATION</div>
      <div class="res-section-body">
        <div class="field"><label>Full Name <span style="color:#dc2626;">*</span></label><input type="text" name="full_name" class="form-input" required value="<?=htmlspecialchars($me_info['name']??'')?>" placeholder="Your answer"></div>
        <div class="res-grid-2">
          <div class="field"><label>Position / Designation <span style="color:#dc2626;">*</span></label><input type="text" name="position" class="form-input" required placeholder="e.g. Student, Researcher" value="<?=htmlspecialchars($me_info['role_label']??'')?>"></div>
          <div class="field"><label>Department / Office <span style="color:#dc2626;">*</span></label><input type="text" name="department_office" class="form-input" required placeholder="e.g. College of Education" value="<?=htmlspecialchars($me_info['college_name']??'')?>"></div>
        </div>
        <div class="res-grid-2">
          <div class="field"><label>Contact Number</label><input type="tel" name="contact" class="form-input" placeholder="Your phone number" value="<?=htmlspecialchars($me_info['phone']??'')?>"></div>
          <div class="field"><label>Email Address</label><input type="email" name="email_res" class="form-input" placeholder="Your email" value="<?=htmlspecialchars($me_info['email']??'')?>"></div>
        </div>
      </div>
    </div>

    <!-- B. Reservation Details -->
    <div class="res-section">
      <div class="res-section-hd">B. RESERVATION DETAILS</div>
      <div class="res-section-body">
        <div class="field"><label>Purpose of Reservation <span style="color:#dc2626;">*</span></label><input type="text" name="purpose_reservation" class="form-input" required placeholder="Your answer"></div>
        <div class="field"><label>Room / Office to Reserve</label>
          <select name="room_name" class="form-input">
            <option value="QA Office">QA Office</option>
            <option value="Conference Room A">Conference Room A</option>
            <option value="Conference Room B">Conference Room B</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="field"><label>Date of Use <span style="color:#dc2626;">*</span></label><input type="date" name="date_of_use" class="form-input" required min="<?=$today?>"></div>
        <div class="res-grid-2">
          <div class="field"><label>Time to Start <span style="color:#dc2626;">*</span></label><input type="time" name="time_start" class="form-input" required placeholder="Your answer"></div>
          <div class="field"><label>Time to End <span style="color:#dc2626;">*</span></label><input type="time" name="time_end" class="form-input" required placeholder="Your answer"></div>
        </div>
        <div class="field"><label>Estimated Number of Participants <span style="color:#dc2626;">*</span></label><input type="number" name="num_participants" class="form-input" required min="1" placeholder="Your answer"></div>
        <div class="field"><label>Equipment / Materials Needed</label><input type="text" name="equipment" class="form-input" placeholder="e.g. Projector, whiteboard, chairs…"></div>
        <div class="field"><label>Additional Notes</label><textarea name="additional_notes" class="form-input" rows="3" placeholder="Any other information you'd like the QA office to know…" style="resize:vertical;"></textarea></div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:.8rem;color:#92400e;">⚠️ Room reservations require approval from the QA Director. You will be notified by email once reviewed.</div>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:6px;">
      <a href="appointments.php?tab=reservations" class="btn btn-outline">Cancel</a>
      <button type="submit" class="btn btn-primary">Submit Reservation Request →</button>
    </div>
  </form>
  <?php endif;?>

</div></div>

<!-- Book Slot Modal -->
<div class="modal-overlay" id="bookSlotModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><span class="modal-title">Book Appointment</span><button type="button" class="modal-close" onclick="closeModal('bookSlotModal')">✕</button></div>
    <form method="POST"><input type="hidden" name="form_action" value="book_slot"><input type="hidden" name="slot_id" id="bsSlotId"><input type="hidden" name="is_open" id="bsIsOpen" value="0">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:13px;">
        <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #bfdbfe;border-radius:12px;padding:13px 15px;">
          <div style="font-size:.68rem;font-weight:800;color:#3b82f6;text-transform:uppercase;letter-spacing:.7px;margin-bottom:5px;">Selected Slot</div>
          <div id="bsTime" style="font-size:.98rem;font-weight:700;color:#1e3a8a;"></div>
          <div id="bsLoc" style="font-size:.78rem;color:#64748b;margin-top:2px;"></div>
        </div>
        <div id="bsTimePickRow" style="display:none;">
          <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:9px;padding:9px 13px;font-size:.78rem;color:#92400e;margin-bottom:8px;">📅 Open-availability day — choose your preferred time.</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="field"><label>Start Time <span style="color:#dc2626;">*</span></label><input type="time" name="chosen_start" id="bsChosenStart" class="form-input"></div>
            <div class="field"><label>End Time <span style="color:#dc2626;">*</span></label><input type="time" name="chosen_end" id="bsChosenEnd" class="form-input"></div>
          </div>
          <div id="bsOpenWindow" style="font-size:.72rem;color:#64748b;margin-top:3px;"></div>
        </div>
        <div class="field"><label>Purpose of visit <span style="color:#dc2626;">*</span></label><input type="text" name="purpose" required class="form-input" placeholder="e.g. Document consultation, inquiry…"></div>
        <div class="field"><label>Notes <span style="font-weight:400;color:var(--muted);">(optional)</span></label><textarea name="notes" rows="2" class="form-input" placeholder="Any details for the QA office…" style="resize:vertical;"></textarea></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('bookSlotModal')">Cancel</button><button type="submit" class="btn btn-primary">✓ Confirm Booking</button></div>
    </form>
  </div>
</div>

<script>
const S_DATA=<?=json_encode($slot_data,JSON_HEX_TAG)?>;
const S_STATUS=<?=json_encode($date_status,JSON_HEX_TAG)?>;
const S_BK=<?=json_encode($bk_dates,JSON_HEX_TAG)?>;
const S_TODAY='<?=$today?>';
const MN=['January','February','March','April','May','June','July','August','September','October','November','December'];
const SMN=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
const DN=['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
function pad(n){return n<10?'0'+n:''+n;}
function ds(y,m,d){return y+'-'+pad(m+1)+'-'+pad(d);}
const tod=new Date(S_TODAY+'T00:00:00');
let sY=tod.getFullYear(),sM=tod.getMonth(),sSel=null;

function renderCal(){
  const grid=document.getElementById('s-grid');
  const ml=document.getElementById('s-mlabel');
  if(!grid)return;
  ml.textContent=MN[sM]+' '+sY;
  document.getElementById('s-prev').disabled=(sY===tod.getFullYear()&&sM===tod.getMonth());
  document.getElementById('s-next').disabled=(sY>tod.getFullYear()+1||(sY===tod.getFullYear()&&sM>=tod.getMonth()+3));
  let na=0,nf=0,nm=0,nb=0;
  Object.keys(S_STATUS).forEach(d=>{const[dy,dm]=[parseInt(d.slice(0,4)),parseInt(d.slice(5,7))-1];if(dy===sY&&dm===sM){if(S_STATUS[d]==='available')na++;else if(S_STATUS[d]==='full')nf++;else if(S_STATUS[d]==='mine')nm++;}});
  Object.keys(S_BK).forEach(d=>{const[dy,dm]=[parseInt(d.slice(0,4)),parseInt(d.slice(5,7))-1];if(dy===sY&&dm===sM)nb++;});
  document.getElementById('sn-avail').textContent=na;
  document.getElementById('sn-full').textContent=nf;
  document.getElementById('sn-mine').textContent=nm;
  document.getElementById('sn-bk').textContent=nb;
  const fd=new Date(sY,sM,1).getDay(),dim=new Date(sY,sM+1,0).getDate(),dip=new Date(sY,sM,0).getDate();
  const total=Math.ceil((fd+dim)/7)*7;
  let html='';
  for(let i=0;i<total;i++){
    let day,mo,yr,other=false;
    if(i<fd){day=dip-fd+i+1;mo=sM-1;yr=sY;if(mo<0){mo=11;yr--;}other=true;}
    else if(i>=fd+dim){day=i-fd-dim+1;mo=sM+1;yr=sY;if(mo>11){mo=0;yr++;}other=true;}
    else{day=i-fd+1;mo=sM;yr=sY;}
    const d2=ds(yr,mo,day),isT=d2===S_TODAY,isSel=d2===sSel,isPast=d2<S_TODAY;
    const st=(!other&&!isPast)?S_STATUS[d2]||null:null;
    const bk=(!other&&!isPast)?S_BK[d2]||null:null;
    let cls='s-cell';
    if(other)cls+=' other';if(isT)cls+=' s-today';if(isSel)cls+=' s-sel';
    let lbl='',bar='';
    if(!other&&!isPast){
      if(bk){cls+=' c-bk';lbl='🚫 Closed';}
      else if(st){if(st==='available'){cls+=' c-avail';lbl=(S_DATA[d2]||[]).filter(s=>(s.capacity-s.booked_count)>0).length+' open';}else if(st==='full'){cls+=' c-full';lbl='Full';}else{cls+=' c-mine';lbl='✓ Booked';}bar='<div class="s-bar"></div>';}
      if(!bk)cls+=' has-slots';
    }
    const oc=(!other&&!isPast&&(st||bk))?`onclick="sSel('${d2}')"` :'';
    html+=`<div class="${cls}" ${oc}><div class="s-num">${day}</div>${lbl?`<div class="s-lbl">${lbl}</div>`:''}${bar}</div>`;
  }
  grid.innerHTML=html;
}
function sNav(d){sM+=d;if(sM>11){sM=0;sY++;}if(sM<0){sM=11;sY--;}renderCal();}
function sSel(d){sSel=d;renderCal();renderSlotPanel(d);}
function jumpToDate(d){const p=d.split('-');sY=parseInt(p[0]);sM=parseInt(p[1])-1;sSel=d;renderCal();renderSlotPanel(d);}
function renderSlotPanel(d){
  const t=document.getElementById('sp-title'),h=document.getElementById('sp-hint'),b=document.getElementById('sp-body');
  const dt=new Date(d+'T00:00:00');
  t.textContent=DN[dt.getDay()]+', '+SMN[dt.getMonth()]+' '+dt.getDate()+', '+dt.getFullYear();
  const bk=S_BK[d]||null;
  if(bk){h.textContent='Office closed: '+bk.reason;b.innerHTML=`<div style="padding:28px;text-align:center;"><div style="font-size:2rem;margin-bottom:8px;">🚫</div><div style="font-weight:700;color:#92400e;">${(bk.type||'').toUpperCase()}</div><div style="font-size:.8rem;color:#6b7280;margin-top:5px;">${bk.reason||'Office unavailable'}</div></div>`;return;}
  const slots=S_DATA[d]||[];
  if(!slots.length){h.textContent='No slots available';b.innerHTML=`<div style="padding:30px;text-align:center;color:#94a3b8;"><div style="font-size:1.8rem;margin-bottom:8px;">📭</div><p style="font-size:.82rem;">No appointment slots on this date.</p></div>`;return;}
  const avail=slots.filter(s=>(s.capacity-s.booked_count)>0).length;
  h.textContent=slots.length+' slot(s) · '+avail+' available';
  function fmtT(ts){if(!ts)return'';const[h2,m]=ts.split(':');const hr=parseInt(h2,10);return(hr%12||12)+':'+m+(hr>=12?' PM':' AM');}
  function esc(s){const d2=document.createElement('div');d2.textContent=s||'';return d2.innerHTML;}
  let html='';
  slots.forEach(s=>{
    const rem=s.capacity-s.booked_count,isMine=s.my_booking>0,isFull=rem<=0;
    const cls=isMine?'mine':(isFull?'full':'avail');
    const icon=isMine?'✅':(isFull?'🔴':'📅');
    let badge=isMine?`<span style="background:#dbeafe;color:#1e40af;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;">✓ Your booking</span>`:isFull?`<span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;">Full</span>`:`<span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;">${rem}/${s.capacity} open</span>`;
    let btn=isMine?`<div style="font-size:.75rem;color:#2563eb;font-weight:600;margin-top:5px;">✓ Booked for this slot</div>`:isFull?`<div style="font-size:.73rem;color:#dc2626;margin-top:5px;">No spots available</div>`:(s.is_open_day==1?`<button class="btn btn-primary btn-sm" style="width:100%;margin-top:6px;" onclick="openBook(${s.id},'${fmtT(s.start_time)} → ${fmtT(s.end_time)}','${esc(s.location)}','${esc(s.purpose||'')}',1,'${s.start_time}','${s.end_time}')">📅 Choose My Time</button>`:`<button class="btn btn-primary btn-sm" style="width:100%;margin-top:6px;" onclick="openBook(${s.id},'${fmtT(s.start_time)} → ${fmtT(s.end_time)}','${esc(s.location)}','${esc(s.purpose||'')}',0,'','')">Book this slot</button>`);
    html+=`<div class="slot-item ${cls}"><div style="width:38px;height:38px;border-radius:9px;background:${isMine?'#dbeafe':isFull?'#fee2e2':'#dcfce7'};display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;">${icon}</div><div style="flex:1;"><div style="font-weight:700;font-size:.88rem;">${fmtT(s.start_time)} <span style="font-weight:400;font-size:.78rem;color:#94a3b8;">to</span> ${fmtT(s.end_time)}</div><div style="font-size:.73rem;color:#64748b;margin:2px 0;">📍 ${esc(s.location)}${s.purpose?' · '+esc(s.purpose):''}</div>${badge}${btn}</div></div>`;
  });
  b.innerHTML=html;
}
function openBook(id,time,loc,purp,isOpen,winStart,winEnd){
  document.getElementById('bsSlotId').value=id;
  document.getElementById('bsIsOpen').value=isOpen?'1':'0';
  document.getElementById('bsTime').textContent=isOpen?'🗓 Open Day · '+time:'🕐 '+time;
  document.getElementById('bsLoc').textContent='📍 '+loc+(purp?' · '+purp:'');
  const row=document.getElementById('bsTimePickRow');
  const cs=document.getElementById('bsChosenStart');
  const ce=document.getElementById('bsChosenEnd');
  const ow=document.getElementById('bsOpenWindow');
  if(isOpen){
    row.style.display='block';cs.required=true;ce.required=true;
    if(winStart){cs.min=winStart.slice(0,5);ce.min=winStart.slice(0,5);}
    if(winEnd){cs.max=winEnd.slice(0,5);ce.max=winEnd.slice(0,5);}
    if(ow)ow.textContent='Office hours: '+fmtT(winStart)+' – '+fmtT(winEnd);
    if(!cs.value)cs.value=winStart?winStart.slice(0,5):'09:00';
    if(!ce.value)ce.value=winEnd?winEnd.slice(0,5):'10:00';
  } else {
    row.style.display='none';if(cs){cs.required=false;cs.value='';}if(ce){ce.required=false;ce.value='';}
  }
  openModal('bookSlotModal');
}
function jumpToFirstAvail(){const dates=Object.keys(S_STATUS).filter(d=>S_STATUS[d]==='available'&&d>=S_TODAY).sort();if(dates.length){const p=dates[0].split('-');sY=parseInt(p[0]);sM=parseInt(p[1])-1;}}
document.addEventListener('DOMContentLoaded',function(){jumpToFirstAvail();renderCal();});
</script>
</body></html>
