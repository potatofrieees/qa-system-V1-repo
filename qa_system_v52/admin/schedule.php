<?php
session_start();
include '../database/db_connect.php';
$active_nav = 'schedule';
$me = (int)$_SESSION['user_id'];

// Ensure required columns exist (safe runtime check)
@$conn->query("ALTER TABLE appointment_slots ADD COLUMN IF NOT EXISTS is_open_day TINYINT(1) NOT NULL DEFAULT 0");
@$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS chosen_start TIME NULL");
@$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS chosen_end TIME NULL");
@$conn->query("CREATE TABLE IF NOT EXISTS schedule_blackouts (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, blackout_date DATE NOT NULL UNIQUE, reason VARCHAR(255), type ENUM('holiday','event','maintenance','other') DEFAULT 'other', created_by INT UNSIGNED NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");

/* ═══════ POST ACTIONS ═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    if ($act === 'create_slot') {
        $date=$conn->real_escape_string($_POST['slot_date']??'');
        $start=$conn->real_escape_string($_POST['start_time']??'');
        $end=$conn->real_escape_string($_POST['end_time']??'');
        $loc=$conn->real_escape_string(trim($_POST['location']??'QA Office'));
        $purp=$conn->real_escape_string(trim($_POST['purpose']??''));
        $cap=max(1,(int)($_POST['capacity']??1));
        if($date&&$start&&$end)
            $conn->query("INSERT INTO appointment_slots (slot_date,start_time,end_time,location,purpose,capacity,created_by) VALUES ('$date','$start','$end','$loc','$purp',$cap,$me)");
        header("Location: schedule.php?msg=".urlencode('Slot created.')."&typ=s"); exit;
    }

    if ($act === 'generate_slots') {
        $df=$_POST['date_from']??''; $dt=$_POST['date_to']??'';
        $dows=$_POST['days_of_week']??[];
        $slot_mode=$_POST['slot_mode']??'fixed';
        $loc=$conn->real_escape_string(trim($_POST['location']??'QA Office'));
        $purp=$conn->real_escape_string(trim($_POST['purpose']??''));
        $cap=max(1,(int)($_POST['capacity']??10));
        $open_start=$conn->real_escape_string($_POST['open_start']??'08:00');
        $open_end=$conn->real_escape_string($_POST['open_end']??'17:00');
        $created=0;
        if($df&&$dt&&$dows){
            $pairs=[];
            if($slot_mode==='fixed'){
                $times_raw=$_POST['slot_times']??'';
                foreach(explode("\n",trim($times_raw)) as $line){
                    $line=trim($line);
                    if(preg_match('/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/',$line,$m))
                        $pairs[]=[$m[1],$m[2]];
                }
            } else {
                $pairs=[[$open_start,$open_end]];
                $cap=max(1,(int)($_POST['capacity']??99));
            }
            $cur=strtotime($df); $end_ts=strtotime($dt);
            while($cur<=$end_ts){
                $dow=(int)date('w',$cur); $ds=date('Y-m-d',$cur);
                $blk=(int)$conn->query("SELECT COUNT(*) c FROM schedule_blackouts WHERE blackout_date='$ds'")->fetch_assoc()['c'];
                if(in_array((string)$dow,$dows)&&!$blk){
                    foreach($pairs as [$ts,$te]){
                        $ets=$conn->real_escape_string($ts); $ete=$conn->real_escape_string($te);
                        $ex=(int)$conn->query("SELECT COUNT(*) c FROM appointment_slots WHERE slot_date='$ds' AND start_time='$ets'")->fetch_assoc()['c'];
                        $is_open=($slot_mode==='open')?1:0;
                        if(!$ex){ $conn->query("INSERT INTO appointment_slots (slot_date,start_time,end_time,location,purpose,capacity,created_by,is_open_day) VALUES ('$ds','$ets','$ete','$loc','$purp',$cap,$me,$is_open)"); $created++; }
                    }
                }
                $cur=strtotime('+1 day',$cur);
            }
        }
        header("Location: schedule.php?msg=".urlencode("Generated $created slot(s) successfully.")."&typ=s"); exit;
    }

    if ($act === 'toggle_slot') {
        $sid=(int)$_POST['slot_id'];
        $conn->query("UPDATE appointment_slots SET is_active = NOT is_active WHERE id=$sid");
        header("Location: schedule.php?tab=slots&msg=".urlencode('Slot updated.')."&typ=s"); exit;
    }

    if ($act === 'delete_slot') {
        $sid=(int)$_POST['slot_id'];
        $conn->query("DELETE FROM appointment_slots WHERE id=$sid");
        header("Location: schedule.php?tab=slots&msg=".urlencode('Slot deleted.')."&typ=s"); exit;
    }

    if ($act === 'delete_day_slots') {
        $ds=$conn->real_escape_string($_POST['slot_date']??'');
        if($ds) $conn->query("DELETE FROM appointment_slots WHERE slot_date='$ds'");
        header("Location: schedule.php?tab=slots&msg=".urlencode('All slots for that date deleted.')."&typ=s"); exit;
    }

    if ($act === 'clear_all_appointments') {
        // Cancel all pending/confirmed appointments (does not delete records, just cancels)
        $conn->query("UPDATE appointments SET status='cancelled', cancelled_by=$me WHERE status IN ('pending','confirmed')");
        $cancelled = $conn->affected_rows;
        header("Location: schedule.php?tab=appointments&msg=".urlencode("Cleared $cancelled active appointment(s).")."&typ=s"); exit;
    }

    if ($act === 'add_blackout') {
        $bd=$conn->real_escape_string($_POST['blackout_date']??'');
        $reason=$conn->real_escape_string(trim($_POST['reason']??''));
        $type=in_array($_POST['type']??'',['holiday','event','maintenance','other'])?$_POST['type']:'other';
        if($bd){
            $conn->query("INSERT IGNORE INTO schedule_blackouts (blackout_date,reason,type,created_by) VALUES ('$bd','$reason','$type',$me)");
            $conn->query("UPDATE appointment_slots SET is_active=0 WHERE slot_date='$bd'");
        }
        header("Location: schedule.php?tab=blackouts&msg=".urlencode('Blackout date added.')."&typ=s"); exit;
    }

    if ($act === 'remove_blackout') {
        $bid=(int)$_POST['blackout_id'];
        $row=$conn->query("SELECT blackout_date FROM schedule_blackouts WHERE id=$bid")->fetch_assoc();
        $conn->query("DELETE FROM schedule_blackouts WHERE id=$bid");
        if($row) $conn->query("UPDATE appointment_slots SET is_active=1 WHERE slot_date='{$row['blackout_date']}'");
        header("Location: schedule.php?tab=blackouts&msg=".urlencode('Blackout removed.')."&typ=s"); exit;
    }

    if ($act === 'update_appt') {
        $aid=(int)$_POST['appt_id'];
        $status=in_array($_POST['status']??'',['confirmed','cancelled','completed','no_show'])?$_POST['status']:null;
        if($aid&&$status){
            $extra=$status==='confirmed'?",confirmed_by=$me,confirmed_at=NOW()":($status==='cancelled'?",cancelled_by=$me":'');
            $conn->query("UPDATE appointments SET status='$status'$extra WHERE id=$aid");
            $appt=$conn->query("SELECT a.*,s.slot_date,s.start_time FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id WHERE a.id=$aid")->fetch_assoc();
            if($appt){
                $lbl=ucfirst($status);
                $msg=$conn->real_escape_string("Your appointment on {$appt['slot_date']} at {$appt['start_time']} has been $lbl.");
                $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$appt['booked_by']},'system','Appointment $lbl','$msg')");
            }
        }
        header("Location: schedule.php?tab=appointments&msg=".urlencode('Updated.')."&typ=s"); exit;
    }

    if ($act === 'update_reservation') {
        $rid=(int)$_POST['res_id'];
        $status=in_array($_POST['status']??'',['approved','rejected'])?$_POST['status']:null;
        $reason=$conn->real_escape_string(trim($_POST['reason']??''));
        if($rid&&$status){
            $extra=$status==='approved'?",approved_by=$me,approved_at=NOW()":",reject_reason='$reason'";
            $conn->query("UPDATE room_reservations SET status='$status'$extra WHERE id=$rid");
            $res=$conn->query("SELECT reserved_by,title FROM room_reservations WHERE id=$rid")->fetch_assoc();
            if($res){
                $msg=$conn->real_escape_string("Your room reservation \"{$res['title']}\" has been $status.");
                $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$res['reserved_by']},'system','Reservation ".ucfirst($status)."','$msg')");
            }
        }
        header("Location: schedule.php?tab=reservations&msg=".urlencode('Reservation updated.')."&typ=s"); exit;
    }

    header("Location: schedule.php"); exit;

}
/* ═══════ DATA ═══════════════════════════════════════════════ */
$tab=in_array($_GET['tab']??'calendar',['calendar','slots','appointments','reservations','blackouts'])?$_GET['tab']:'calendar';
$message=htmlspecialchars(urldecode($_GET['msg']??''));
$msg_type=($_GET['typ']??'')==='e'?'error':'success';
$today=date('Y-m-d');

// Calendar data
$cal_start=date('Y-m-d',strtotime('-1 month'));
$cal_end=date('Y-m-d',strtotime('+3 months'));
$cal_q=$conn->query("
    SELECT s.slot_date,
           COUNT(*) total_slots,
           SUM(s.capacity) total_cap,
           SUM(s.is_active) active_slots,
           SUM((SELECT COUNT(*) FROM appointments a WHERE a.slot_id=s.id AND a.status NOT IN ('cancelled'))) booked
    FROM appointment_slots s
    WHERE s.slot_date>='$cal_start' AND s.slot_date<='$cal_end'
    GROUP BY s.slot_date");
$cal_data=[];
if($cal_q) while($r=$cal_q->fetch_assoc()) $cal_data[$r['slot_date']]=$r;

// Blackouts
$bk_q=$conn->query("SELECT * FROM schedule_blackouts ORDER BY blackout_date DESC");
$bk_set=[]; $bk_all=[];
if($bk_q){ while($b=$bk_q->fetch_assoc()){$bk_set[$b['blackout_date']]=$b; $bk_all[]=$b;} }

// Slots list grouped by date
$sf=$conn->real_escape_string($_GET['date']??'');
$sw=$sf?"WHERE s.slot_date='$sf'":" WHERE s.slot_date>='$today'";
$slots_q=$conn->query("SELECT s.*,(SELECT COUNT(*) FROM appointments a WHERE a.slot_id=s.id AND a.status NOT IN ('cancelled')) booked_count FROM appointment_slots s $sw ORDER BY s.slot_date,s.start_time LIMIT 500");
$slots_by_date=[];
if($slots_q) while($sl=$slots_q->fetch_assoc()) $slots_by_date[$sl['slot_date']][]=$sl;

// Appointments
$af=in_array($_GET['astatus']??'',['pending','confirmed','cancelled','completed','no_show'])?$_GET['astatus']:'';
$aw=$af?"AND a.status='$af'":'';
$appts_q=$conn->query("SELECT a.*,s.slot_date,s.start_time,s.end_time,s.location,u.name booker_name,u.email booker_email,r.role_label booker_role FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id LEFT JOIN users u ON u.id=a.booked_by LEFT JOIN roles r ON r.id=u.role_id WHERE 1=1 $aw ORDER BY s.slot_date DESC,s.start_time DESC LIMIT 300");

// Reservations
$rf=in_array($_GET['rstatus']??'',['pending','approved','rejected','cancelled'])?$_GET['rstatus']:'';
$rw=$rf?"AND rr.status='$rf'":'';
$res_q=$conn->query("SELECT rr.*,u.name booker_name,u.email booker_email,r.role_label booker_role FROM room_reservations rr LEFT JOIN users u ON u.id=rr.reserved_by LEFT JOIN roles r ON r.id=u.role_id WHERE 1=1 $rw ORDER BY rr.start_datetime DESC LIMIT 300");

$notif_count=(int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];
$pending_appts=(int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE status='pending'")->fetch_assoc()['c'];
$pending_res=(int)$conn->query("SELECT COUNT(*) c FROM room_reservations WHERE status='pending'")->fetch_assoc()['c'];
$total_slots_up=(int)$conn->query("SELECT COUNT(*) c FROM appointment_slots WHERE slot_date>='$today' AND is_active=1")->fetch_assoc()['c'];

$sc_colors=['pending'=>['#fff7ed','#9a3412','#fdba74'],'confirmed'=>['#eff6ff','#1e40af','#93c5fd'],'cancelled'=>['#fef2f2','#991b1b','#fca5a5'],'completed'=>['#f0fdf4','#166534','#86efac'],'no_show'=>['#f9fafb','#374151','#d1d5db'],'approved'=>['#f0fdf4','#166534','#86efac'],'rejected'=>['#fef2f2','#991b1b','#fca5a5']];
$bk_icons=['holiday'=>'&#127958;','event'=>'&#128203;','maintenance'=>'&#128295;','other'=>'&#128204;'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Scheduling — QA System</title>
<?php include 'head.php'; ?>
<style>
/* ── Calendar layout ──────────────────────────────────────── */
.sc-wrap{display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;}
@media(max-width:960px){.sc-wrap{grid-template-columns:1fr;}.sc-dp{position:static!important;}}

.sc-cal{background:white;border-radius:16px;border:1.5px solid var(--border);overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.07);}
.sc-cal-hd{background:linear-gradient(135deg,var(--primary) 0%,#a00000 100%);padding:16px 20px 14px;color:white;}
.sc-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;}
.sc-nbtn{width:32px;height:32px;border-radius:50%;border:1.5px solid rgba(255,255,255,.35);background:rgba(255,255,255,.12);color:white;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;transition:.15s;font-family:inherit;line-height:1;}
.sc-nbtn:hover:not(:disabled){background:rgba(255,255,255,.28);}
.sc-nbtn:disabled{opacity:.3;cursor:default;}
.sc-month-lbl{font-size:1.1rem;font-weight:700;letter-spacing:.3px;}
.sc-stats-row{display:flex;gap:6px;flex-wrap:wrap;}
.sc-stat{display:flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:.68rem;font-weight:700;}
.sc-stat.g{background:rgba(34,197,94,.22);border:1px solid rgba(34,197,94,.45);}
.sc-stat.a{background:rgba(251,191,36,.22);border:1px solid rgba(251,191,36,.45);}
.sc-stat.r{background:rgba(239,68,68,.22);border:1px solid rgba(239,68,68,.45);}
.sc-stat.gr{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25);}
.sdot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.sdot.g{background:#4ade80;}.sdot.a{background:#fbbf24;}.sdot.r{background:#f87171;}

.sc-dow-row{display:grid;grid-template-columns:repeat(7,1fr);background:#f8f9fc;border-bottom:1.5px solid #e9ecef;}
.sc-dow-row div{text-align:center;padding:7px 0;font-size:.64rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#64748b;}
.sc-dow-row div.we{color:#b91c1c;}

.sc-grid{display:grid;grid-template-columns:repeat(7,1fr);}
.sc-cell{min-height:72px;padding:6px 4px 4px;display:flex;flex-direction:column;align-items:center;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;background:white;cursor:default;transition:transform .1s,box-shadow .1s;position:relative;overflow:hidden;}
.sc-cell.clickable{cursor:pointer;}
.sc-cell.clickable:hover{transform:scale(1.04);z-index:2;box-shadow:0 3px 14px rgba(0,0,0,.13);}
.sc-cell.other{background:#fafafa;}.sc-cell.other .sc-num{color:#d1d5db;}
.sc-cell.sc-today .sc-num{background:var(--primary);color:white;font-weight:700;}
.sc-cell.sc-sel{box-shadow:inset 0 0 0 2.5px var(--primary-light)!important;z-index:3;}
.sc-cell.c-open{background:linear-gradient(155deg,#f0fdf4,#dcfce7);}
.sc-cell.c-open.clickable:hover{background:linear-gradient(155deg,#dcfce7,#bbf7d0);}
.sc-cell.c-partial{background:linear-gradient(155deg,#fffbeb,#fef3c7);}
.sc-cell.c-partial.clickable:hover{background:linear-gradient(155deg,#fef3c7,#fde68a);}
.sc-cell.c-full{background:linear-gradient(155deg,#fff5f5,#fee2e2);}
.sc-cell.c-bk{background:repeating-linear-gradient(45deg,#f9fafb,#f9fafb 4px,#f1f5f9 4px,#f1f5f9 8px);}
.sc-num{width:25px;height:25px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:600;color:#374151;flex-shrink:0;margin-bottom:2px;transition:.15s;}
.c-open .sc-num{color:#166534;font-weight:700;}.c-partial .sc-num{color:#92400e;font-weight:700;}.c-full .sc-num{color:#991b1b;font-weight:700;}
.sc-bar{position:absolute;bottom:0;left:0;right:0;height:3px;}
.c-open .sc-bar{background:#22c55e;}.c-partial .sc-bar{background:#f59e0b;}.c-full .sc-bar{background:#ef4444;}
.sc-lbl{font-size:.57rem;font-weight:700;text-align:center;line-height:1.2;margin-top:1px;}
.c-open .sc-lbl{color:#15803d;}.c-partial .sc-lbl{color:#92400e;}.c-full .sc-lbl{color:#b91c1c;}.c-bk .sc-lbl{color:#9ca3af;font-size:.54rem;}
.sc-legend{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 16px;border-top:1.5px solid #f1f5f9;background:#f8fafc;}
.sc-leg{display:flex;align-items:center;gap:4px;font-size:.71rem;color:#64748b;}
.sc-sw{width:12px;height:12px;border-radius:3px;border:1.5px solid transparent;flex-shrink:0;}
.sc-sw.open{background:#dcfce7;border-color:#22c55e;}.sc-sw.partial{background:#fef3c7;border-color:#f59e0b;}.sc-sw.full{background:#fee2e2;border-color:#ef4444;}
.sc-sw.bk{background:repeating-linear-gradient(45deg,#f9fafb,#f9fafb 3px,#e5e7eb 3px,#e5e7eb 6px);border-color:#9ca3af;}
.sc-sw.td{background:var(--primary);border-radius:50%;border-color:var(--primary);}

/* ── Day Panel ────────────────────────────────────────────── */
.sc-dp{background:white;border-radius:16px;border:1.5px solid var(--border);overflow:hidden;position:sticky;top:76px;}
.sc-dp-hd{background:#f8fafc;border-bottom:1.5px solid #f1f5f9;padding:14px 16px;}
.sc-dp-body{padding:12px;display:flex;flex-direction:column;gap:8px;max-height:380px;overflow-y:auto;}
.sc-dp-acts{display:flex;gap:6px;padding:10px 12px;border-top:1px solid #f1f5f9;background:#fafbfc;flex-wrap:wrap;}

/* ── Slots card grid ──────────────────────────────────────── */
.slot-date-section{margin-bottom:24px;}
.slot-date-hdr{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:linear-gradient(90deg,#f8fafc,white);border-radius:10px 10px 0 0;border:1.5px solid var(--border);border-bottom:none;}
.slot-date-title{font-size:.8rem;font-weight:800;color:#374151;text-transform:uppercase;letter-spacing:.7px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.slot-cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(210px,1fr));border:1.5px solid var(--border);border-radius:0 0 10px 10px;overflow:hidden;}
.slot-card{position:relative;padding:14px;background:white;border-right:1px solid #f1f5f9;border-bottom:1px solid #f1f5f9;transition:background .15s;overflow:hidden;}
.slot-card:hover{background:#f8fbff;}
.slot-card.inactive{background:#fafafa;opacity:.7;}
.slot-card.full-card{background:#fff5f5;}
.slot-card-time{font-size:1rem;font-weight:800;color:var(--primary-light);line-height:1;}
.slot-card-end{font-size:.72rem;color:var(--muted);margin-top:1px;}
.slot-card-loc{font-size:.78rem;font-weight:600;color:#374151;margin-top:6px;}
.slot-card-purp{font-size:.7rem;color:var(--muted);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.slot-cap-bar{margin-top:8px;height:4px;border-radius:4px;background:#e5e7eb;overflow:hidden;}
.slot-cap-fill{height:100%;border-radius:4px;transition:width .3s;}
.slot-cap-label{font-size:.68rem;font-weight:700;margin-top:4px;}
/* Hover overlay */
.slot-card-overlay{position:absolute;inset:0;background:rgba(15,23,42,.76);display:flex;align-items:center;justify-content:center;gap:6px;opacity:0;transition:opacity .18s;backdrop-filter:blur(2px);flex-wrap:wrap;padding:8px;}
.slot-card:hover .slot-card-overlay{opacity:1;}
.slot-ovr-btn{display:flex;flex-direction:column;align-items:center;gap:3px;padding:8px 10px;border-radius:8px;border:none;cursor:pointer;font-size:.61rem;font-weight:700;transition:transform .12s,background .12s;color:white;font-family:inherit;}
.slot-ovr-btn:hover{transform:scale(1.09);}
.slot-ovr-btn .ico{font-size:1.1rem;line-height:1;}
.slot-ovr-btn.del{background:rgba(220,38,38,.88);}.slot-ovr-btn.del:hover{background:#dc2626;}
.slot-ovr-btn.tog{background:rgba(100,116,139,.78);}.slot-ovr-btn.tog:hover{background:#475569;}
.slot-ovr-btn.bk{background:rgba(234,88,12,.82);}.slot-ovr-btn.bk:hover{background:#ea580c;}
.slot-ovr-btn.view{background:rgba(37,99,168,.82);}.slot-ovr-btn.view:hover{background:#2563a8;}
.slot-inactive-badge{position:absolute;top:7px;right:8px;font-size:.6rem;background:#f1f5f9;color:#64748b;border-radius:20px;padding:1px 6px;font-weight:700;border:1px solid #e2e8f0;}

/* ── Misc ─────────────────────────────────────────────────── */
.bk-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-size:.7rem;font-weight:700;}
.bk-badge.holiday{background:#fef3c7;color:#92400e;}.bk-badge.event{background:#ede9fe;color:#5b21b6;}
.bk-badge.maintenance{background:#e0f2fe;color:#0369a1;}.bk-badge.other{background:#f3f4f6;color:#374151;}
@media(max-width:600px){.sc-cell{min-height:50px;padding:3px 2px;}.sc-lbl{display:none;}.slot-cards-grid{grid-template-columns:1fr 1fr;}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">Scheduling &amp; Appointments
      <?php if($pending_appts+$pending_res>0):?>
      <span style="margin-left:8px;background:#dc2626;color:white;border-radius:20px;padding:1px 9px;font-size:.72rem;font-weight:700;"><?=$pending_appts+$pending_res?> pending</span>
      <?php endif;?>
    </div>
    <div class="topbar-right">
      <button class="btn btn-primary btn-sm" onclick="openModal('addSlotModal')">+ Add Slot</button>
      <button class="btn btn-outline btn-sm" onclick="openModal('genSlotsModal')">&#9889; Auto-Generate</button>
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

    <div class="page-header" style="margin-bottom:18px;">
      <div>
        <h1 class="page-heading">Scheduling &amp; Appointments</h1>
        <p class="page-subheading">Manage appointment slots, bookings, room reservations, and blackout dates</p>
      </div>
    </div>

    <!-- Summary cards -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;">
      <?php foreach([
        ['&#128197;',$total_slots_up,'Upcoming Slots','#f0fdf4','#16a34a'],
        ['&#9203;',$pending_appts,'Pending Appts','#fff7ed','#d97706'],
        ['&#127970;',$pending_res,'Pending Reservations','#fef2f2','#dc2626'],
        ['&#128683;',count($bk_all),'Blackout Dates','#fef3c7','#92400e']
      ] as [$icon,$n,$lbl,$bg,$col]):?>
      <div class="card" style="padding:16px;display:flex;align-items:center;gap:12px;">
        <div style="width:40px;height:40px;border-radius:10px;background:<?=$bg?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;"><?=$icon?></div>
        <div>
          <div style="font-size:1.5rem;font-weight:800;color:<?=$col?>;line-height:1;"><?=$n?></div>
          <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:600;"><?=$lbl?></div>
        </div>
      </div>
      <?php endforeach;?>
    </div>

    <!-- Tabs -->
    <div class="tabs-bar" style="margin-bottom:22px;">
      <a href="schedule.php?tab=calendar" class="tab-pill<?=$tab==='calendar'?' active':''?>">&#128197; Calendar</a>
      <a href="schedule.php?tab=slots"    class="tab-pill<?=$tab==='slots'?' active':''?>">&#9201; Slots</a>
      <a href="schedule.php?tab=appointments" class="tab-pill<?=$tab==='appointments'?' active':''?>">&#128100; Appointments<?php if($pending_appts):?> <span style="background:#d97706;color:white;border-radius:12px;padding:0 7px;font-size:.68rem;"><?=$pending_appts?></span><?php endif;?></a>
      <a href="schedule.php?tab=reservations" class="tab-pill<?=$tab==='reservations'?' active':''?>">&#127970; Reservations<?php if($pending_res):?> <span style="background:#dc2626;color:white;border-radius:12px;padding:0 7px;font-size:.68rem;"><?=$pending_res?></span><?php endif;?></a>
      <a href="schedule.php?tab=blackouts"    class="tab-pill<?=$tab==='blackouts'?' active':''?>">&#128683; Blackouts</a>
    </div>

    <?php /* ══════════ CALENDAR ══════════ */ if($tab==='calendar'): ?>
    <div class="sc-wrap">
      <div class="sc-cal">
        <div class="sc-cal-hd">
          <div class="sc-cal-nav">
            <button class="sc-nbtn" id="sc-prev" onclick="scNav(-1)">&#8249;</button>
            <div class="sc-month-lbl" id="sc-mlabel">Loading…</div>
            <button class="sc-nbtn" id="sc-next" onclick="scNav(1)">&#8250;</button>
          </div>
          <div class="sc-stats-row">
            <div class="sc-stat g"><div class="sdot g"></div><span id="sc-n-open">0</span> Open</div>
            <div class="sc-stat a"><div class="sdot a"></div><span id="sc-n-partial">0</span> Partial</div>
            <div class="sc-stat r"><div class="sdot r"></div><span id="sc-n-full">0</span> Full</div>
            <div class="sc-stat gr">&#128683; <span id="sc-n-bk">0</span> Blocked</div>
          </div>
        </div>
        <div class="sc-dow-row">
          <?php foreach(['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $i=>$d):?>
          <div class="<?=$i===0||$i===6?'we':''?>"><?=$d?></div>
          <?php endforeach;?>
        </div>
        <div class="sc-grid" id="sc-grid"></div>
        <div class="sc-legend">
          <div class="sc-leg"><div class="sc-sw td"></div> Today</div>
          <div class="sc-leg"><div class="sc-sw open"></div> Available</div>
          <div class="sc-leg"><div class="sc-sw partial"></div> Partially booked</div>
          <div class="sc-leg"><div class="sc-sw full"></div> Fully booked</div>
          <div class="sc-leg"><div class="sc-sw bk"></div> Blocked</div>
        </div>
      </div>
      <!-- Day Panel -->
      <div class="sc-dp">
        <div class="sc-dp-hd">
          <div style="font-weight:700;font-size:.93rem;" id="dp-title">Select a Date</div>
          <div style="font-size:.74rem;color:var(--muted);margin-top:2px;" id="dp-hint">Click any date on the calendar</div>
        </div>
        <div class="sc-dp-body" id="dp-body">
          <div style="padding:36px 16px;text-align:center;color:var(--muted);">
            <div style="font-size:2rem;margin-bottom:8px;">&#128197;</div>
            <p style="font-size:.82rem;">Click a date to see and manage slots</p>
          </div>
        </div>
        <div class="sc-dp-acts" id="dp-actions" style="display:none;">
          <button class="btn btn-primary btn-sm" onclick="addSlotForDate()">+ Add Slot</button>
          <button class="btn btn-ghost btn-sm" onclick="viewDateSlots()">&#128203; View All</button>
          <button class="btn btn-outline btn-sm" style="color:#dc2626;border-color:#fca5a5;" onclick="markBlackout()">&#128683; Block Date</button>
        </div>
      </div>
    </div>

    <?php /* ══════════ SLOTS ══════════ */ elseif($tab==='slots'): ?>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
      <input type="date" value="<?=htmlspecialchars($sf)?>" min="<?=$today?>" onchange="location='schedule.php?tab=slots&date='+this.value" class="filter-select" style="padding:8px 12px;">
      <?php if($sf):?>
        <a href="schedule.php?tab=slots" class="btn btn-ghost btn-sm">&#10005; Clear</a>
        <form method="POST" onsubmit="return confirm('Delete ALL slots for <?=htmlspecialchars($sf)?>?')">
          <input type="hidden" name="form_action" value="delete_day_slots">
          <input type="hidden" name="slot_date" value="<?=htmlspecialchars($sf)?>">
          <button type="submit" class="btn btn-danger btn-sm">&#128465; Clear This Date</button>
        </form>
      <?php endif;?>
      <div style="margin-left:auto;font-size:.78rem;color:var(--muted);">Hover a slot card for quick actions</div>
    </div>

    <?php if(empty($slots_by_date)):?>
      <div class="card"><div class="empty-state"><p>No slots found.</p></div></div>
    <?php else: foreach($slots_by_date as $date=>$slots):
      $total_cap_d  = array_sum(array_column($slots,'capacity'));
      $total_bkd_d  = array_sum(array_column($slots,'booked_count'));
      $is_bk        = isset($bk_set[$date]);
    ?>
    <div class="slot-date-section">
      <div class="slot-date-hdr">
        <div class="slot-date-title">
          &#128198; <?=date('l, F j, Y',strtotime($date))?>
          <?php if($is_bk):?><span class="bk-badge <?=$bk_set[$date]['type']?>"><?=$bk_icons[$bk_set[$date]['type']]?> Blocked</span><?php endif;?>
          <span style="font-size:.72rem;background:#f0f4f9;color:var(--muted);padding:2px 9px;border-radius:12px;font-weight:600;"><?=count($slots)?> slots &middot; <?=$total_bkd_d?>/<?=$total_cap_d?> booked</span>
        </div>
        <div style="display:flex;gap:6px;align-items:center;">
          <a href="schedule.php?tab=slots&date=<?=$date?>" class="btn btn-ghost btn-sm" style="font-size:.72rem;">Filter</a>
          <form method="POST" onsubmit="return confirm('Delete all slots for <?=$date?>?')">
            <input type="hidden" name="form_action" value="delete_day_slots">
            <input type="hidden" name="slot_date" value="<?=$date?>">
            <button type="submit" class="btn btn-danger btn-sm" style="font-size:.72rem;">&#128465; Clear All</button>
          </form>
        </div>
      </div>
      <div class="slot-cards-grid">
        <?php foreach($slots as $sl):
          $av      = $sl['capacity'] - $sl['booked_count'];
          $pct     = $sl['capacity']>0 ? round(($sl['booked_count']/$sl['capacity'])*100) : 0;
          $bar_col = $pct>=100?'#ef4444':($pct>50?'#f59e0b':'#22c55e');
          $inactive= !$sl['is_active'];
        ?>
        <div class="slot-card<?=$inactive?' inactive':($av<=0?' full-card':'')?>">
          <?php if($inactive):?><span class="slot-inactive-badge">Inactive</span><?php endif;?>
          <div class="slot-card-time"><?=date('g:i A',strtotime($sl['start_time']))?></div>
          <div class="slot-card-end">until <?=date('g:i A',strtotime($sl['end_time']))?></div>
          <div class="slot-card-loc">&#128205; <?=htmlspecialchars($sl['location'])?></div>
          <?php if($sl['purpose']):?><div class="slot-card-purp">&#127991; <?=htmlspecialchars($sl['purpose'])?></div><?php endif;?>
          <div class="slot-cap-bar"><div class="slot-cap-fill" style="width:<?=$pct?>%;background:<?=$bar_col?>;"></div></div>
          <div class="slot-cap-label" style="color:<?=$bar_col?>;"><?=$sl['booked_count']?> booking<?=$sl['booked_count']!=1?'s':''?> &middot; <?=$av>0?$av.' slot'.($av!=1?'s':'').' left':'Session full'?></div>
          <!-- Hover overlay -->
          <div class="slot-card-overlay">
            <form method="POST" onsubmit="return confirm('Delete this slot?')">
              <input type="hidden" name="form_action" value="delete_slot">
              <input type="hidden" name="slot_id" value="<?=$sl['id']?>">
              <button type="submit" class="slot-ovr-btn del"><span class="ico">&#128465;</span>Delete</button>
            </form>
            <form method="POST">
              <input type="hidden" name="form_action" value="toggle_slot">
              <input type="hidden" name="slot_id" value="<?=$sl['id']?>">
              <button type="submit" class="slot-ovr-btn tog"><span class="ico"><?=$inactive?'&#9989;':'&#9208;'?></span><?=$inactive?'Enable':'Disable'?></button>
            </form>
            <button type="button" class="slot-ovr-btn bk" onclick="quickBlackout('<?=$date?>')">
              <span class="ico">&#128683;</span>Block Day
            </button>
            <a href="schedule.php?tab=appointments" class="slot-ovr-btn view" style="text-decoration:none;">
              <span class="ico">&#128065;</span>Bookings
            </a>
          </div>
        </div>
        <?php endforeach;?>
      </div>
    </div>
    <?php endforeach; endif;?>

    <?php /* ══════════ APPOINTMENTS ══════════ */ elseif($tab==='appointments'): ?>
    <div style="display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
      <?php foreach([''=>'All','pending'=>'Pending','confirmed'=>'Confirmed','completed'=>'Completed','cancelled'=>'Cancelled','no_show'=>'No Show'] as $sv=>$sl):?>
      <a href="schedule.php?tab=appointments&astatus=<?=$sv?>" class="tab-pill<?=$af===$sv?' active':''?>" style="font-size:.78rem;"><?=$sl?></a>
      <?php endforeach;?>
      <form method="POST" style="margin-left:auto;" onsubmit="return confirm('Cancel ALL pending and confirmed appointments? This cannot be undone.')">
        <input type="hidden" name="form_action" value="clear_all_appointments">
        <button type="submit" class="btn btn-danger btn-sm" style="font-size:.78rem;">&#128465; Clear All Active</button>
      </form>
    </div>
    <div class="card"><div class="table-wrapper"><table>
    <thead><tr><th>Date &amp; Time</th><th>Booked By</th><th>Role</th><th>Purpose</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$appts_q||$appts_q->num_rows===0):?>
    <tr><td colspan="6"><div class="empty-state"><p>No appointments found.</p></div></td></tr>
    <?php else: while($ap=$appts_q->fetch_assoc()): $c=$sc_colors[$ap['status']]??['#f9fafb','#374151','#d1d5db'];?>
    <tr>
      <td>
        <div style="font-weight:700;font-size:.85rem;"><?=date('M j, Y',strtotime($ap['slot_date']))?></div>
        <?php if(!empty($ap['chosen_start'])):?>
          <div style="font-size:.72rem;font-weight:600;color:#0369a1;"><?=date('g:i A',strtotime($ap['chosen_start']))?> &ndash; <?=date('g:i A',strtotime($ap['chosen_end']))?> <span style="background:#e0f2fe;color:#0369a1;font-size:.65rem;padding:1px 5px;border-radius:8px;">Custom</span></div>
        <?php else:?>
          <div style="font-size:.72rem;color:var(--muted);"><?=date('g:i A',strtotime($ap['start_time']))?> &ndash; <?=date('g:i A',strtotime($ap['end_time']))?></div>
        <?php endif;?>
        <div style="font-size:.7rem;color:var(--muted);"><?=htmlspecialchars($ap['location'])?></div>
      </td>
      <td>
        <div style="font-weight:600;font-size:.85rem;"><?=htmlspecialchars($ap['booker_name']??'—')?></div>
        <div style="font-size:.72rem;color:var(--muted);"><?=htmlspecialchars($ap['booker_email']??'')?></div>
      </td>
      <td><span style="font-size:.72rem;background:#f0f4f9;color:var(--muted);padding:2px 8px;border-radius:12px;"><?=htmlspecialchars($ap['booker_role']??'—')?></span></td>
      <td style="font-size:.8rem;color:var(--muted);max-width:150px;"><?=htmlspecialchars($ap['purpose']??'—')?></td>
      <td><span style="background:<?=$c[0]?>;color:<?=$c[1]?>;border:1.5px solid <?=$c[2]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?=ucfirst($ap['status'])?></span></td>
      <td>
        <?php if($ap['status']==='pending'):?>
          <div style="display:flex;gap:4px;">
            <form method="POST"><input type="hidden" name="form_action" value="update_appt"><input type="hidden" name="appt_id" value="<?=$ap['id']?>"><input type="hidden" name="status" value="confirmed"><button type="submit" class="btn btn-primary btn-sm">Confirm</button></form>
            <form method="POST" onsubmit="return confirm('Cancel this appointment?')"><input type="hidden" name="form_action" value="update_appt"><input type="hidden" name="appt_id" value="<?=$ap['id']?>"><input type="hidden" name="status" value="cancelled"><button type="submit" class="btn btn-danger btn-sm">Cancel</button></form>
          </div>
        <?php elseif($ap['status']==='confirmed'):?>
          <div style="display:flex;gap:4px;">
            <form method="POST"><input type="hidden" name="form_action" value="update_appt"><input type="hidden" name="appt_id" value="<?=$ap['id']?>"><input type="hidden" name="status" value="completed"><button type="submit" class="btn btn-outline btn-sm">Mark Done</button></form>
            <form method="POST" onsubmit="return confirm('Mark as no-show?')"><input type="hidden" name="form_action" value="update_appt"><input type="hidden" name="appt_id" value="<?=$ap['id']?>"><input type="hidden" name="status" value="no_show"><button type="submit" class="btn btn-ghost btn-sm">No Show</button></form>
          </div>
        <?php else:?><span style="font-size:.75rem;color:var(--muted);">—</span><?php endif;?>
      </td>
    </tr>
    <?php endwhile; endif;?>
    </tbody></table></div></div>

    <?php /* ══════════ RESERVATIONS ══════════ */ elseif($tab==='reservations'): ?>
    <div style="display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;">
      <?php foreach([''=>'All','pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','cancelled'=>'Cancelled'] as $sv=>$sl):?>
      <a href="schedule.php?tab=reservations&rstatus=<?=$sv?>" class="tab-pill<?=$rf===$sv?' active':''?>" style="font-size:.78rem;"><?=$sl?></a>
      <?php endforeach;?>
    </div>
    <div class="card"><div class="table-wrapper"><table>
    <thead><tr><th>Title &amp; Room</th><th>Requested By</th><th>Date &amp; Time</th><th>Participants</th><th>Status</th><th>Actions</th></tr></thead>
    <tbody>
    <?php if(!$res_q||$res_q->num_rows===0):?>
    <tr><td colspan="6"><div class="empty-state"><p>No reservations found.</p></div></td></tr>
    <?php else: while($res=$res_q->fetch_assoc()): $c=$sc_colors[$res['status']]??['#f9fafb','#374151','#d1d5db'];?>
    <tr>
      <td style="max-width:175px;">
        <div style="font-weight:700;font-size:.85rem;"><?=htmlspecialchars($res['title'])?></div>
        <div style="font-size:.73rem;color:var(--muted);">&#128205; <?=htmlspecialchars($res['room_name'])?></div>
        <?php if($res['description']):?><div style="font-size:.7rem;color:var(--muted);margin-top:2px;"><?=htmlspecialchars(mb_substr($res['description'],0,55))?></div><?php endif;?>
      </td>
      <td>
        <div style="font-weight:600;font-size:.85rem;"><?=htmlspecialchars($res['booker_name']??'—')?></div>
        <div style="font-size:.72rem;color:var(--muted);"><?=htmlspecialchars($res['booker_email']??'')?></div>
        <span style="font-size:.7rem;background:#f0f4f9;color:var(--muted);padding:1px 7px;border-radius:12px;"><?=htmlspecialchars($res['booker_role']??'—')?></span>
      </td>
      <td>
        <div style="font-weight:600;font-size:.85rem;"><?=date('M j, Y',strtotime($res['start_datetime']))?></div>
        <div style="font-size:.73rem;color:var(--muted);"><?=date('g:i A',strtotime($res['start_datetime']))?> &ndash; <?=date('g:i A',strtotime($res['end_datetime']))?></div>
      </td>
      <td style="font-size:.8rem;color:var(--muted);"><?=htmlspecialchars($res['attendees']??'—')?></td>
      <td><span style="background:<?=$c[0]?>;color:<?=$c[1]?>;border:1.5px solid <?=$c[2]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?=ucfirst($res['status'])?></span></td>
      <td>
        <?php if($res['status']==='pending'):?>
          <div style="display:flex;gap:4px;flex-wrap:wrap;">
            <form method="POST"><input type="hidden" name="form_action" value="update_reservation"><input type="hidden" name="res_id" value="<?=$res['id']?>"><input type="hidden" name="status" value="approved"><button type="submit" class="btn btn-primary btn-sm">Approve</button></form>
            <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?=$res['id']?>)">Reject</button>
          </div>
        <?php else:?><span style="font-size:.75rem;color:var(--muted);">—</span><?php endif;?>
      </td>
    </tr>
    <?php endwhile; endif;?>
    </tbody></table></div></div>

    <?php /* ══════════ BLACKOUTS ══════════ */ elseif($tab==='blackouts'): ?>
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
      <button class="btn btn-danger" onclick="openModal('addBlackoutModal')">&#128683; Add Blackout Date</button>
    </div>
    <?php if(!$bk_all):?>
      <div class="card"><div class="empty-state"><p>No blackout dates set.</p></div></div>
    <?php else:?>
    <div class="card"><div class="table-wrapper"><table>
    <thead><tr><th>Date</th><th>Day</th><th>Type</th><th>Reason</th><th>Slots Affected</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach($bk_all as $bk): $past=$bk['blackout_date']<$today;?>
    <tr style="<?=$past?'opacity:.6':''?>">
      <td style="font-weight:700;"><?=date('M j, Y',strtotime($bk['blackout_date']))?></td>
      <td style="font-size:.82rem;color:var(--muted);"><?=date('l',strtotime($bk['blackout_date']))?></td>
      <td><span class="bk-badge <?=$bk['type']?>"><?=$bk_icons[$bk['type']]?> <?=ucfirst($bk['type'])?></span></td>
      <td style="font-size:.82rem;max-width:200px;"><?=htmlspecialchars($bk['reason']??'—')?></td>
      <td style="font-size:.82rem;color:var(--muted);"><?=(int)$conn->query("SELECT COUNT(*) c FROM appointment_slots WHERE slot_date='{$bk['blackout_date']}'")->fetch_assoc()['c']?> slot(s)</td>
      <td>
        <?php if(!$past):?>
          <form method="POST" onsubmit="return confirm('Remove this blackout? Slots will be re-activated.')">
            <input type="hidden" name="form_action" value="remove_blackout">
            <input type="hidden" name="blackout_id" value="<?=$bk['id']?>">
            <button type="submit" class="btn btn-ghost btn-sm">Remove</button>
          </form>
        <?php else:?><span style="font-size:.75rem;color:var(--muted);">Past</span><?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody></table></div></div>
    <?php endif;?>
    <?php endif;?>

  </div><!-- /page-body -->
</div><!-- /main-content -->

<!-- ══ MODALS ════════════════════════════════════════════════ -->

<!-- Add Single Slot -->
<div class="modal-overlay" id="addSlotModal">
  <div class="modal" style="max-width:460px;">
    <div class="modal-header"><span class="modal-title">Add Appointment Slot</span><button type="button" class="modal-close" onclick="closeModal('addSlotModal')">&#10005;</button></div>
    <form method="POST"><input type="hidden" name="form_action" value="create_slot">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:13px;">
        <div class="field"><label>Date *</label><input type="date" name="slot_date" id="addSlotDate" required min="<?=$today?>" class="form-input"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Start Time *</label><input type="time" name="start_time" required class="form-input"></div>
          <div class="field"><label>End Time *</label><input type="time" name="end_time" required class="form-input"></div>
        </div>
        <div class="field"><label>Location</label><input type="text" name="location" value="QA Office" class="form-input"></div>
        <div class="field"><label>Purpose</label><input type="text" name="purpose" placeholder="e.g. Document Consultation" class="form-input"></div>
        <div class="field"><label>Capacity</label><input type="number" name="capacity" value="1" min="1" max="99" class="form-input"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('addSlotModal')">Cancel</button><button type="submit" class="btn btn-primary">Create Slot</button></div>
    </form>
  </div>
</div>

<!-- Auto-Generate Slots -->
<div class="modal-overlay" id="genSlotsModal">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header"><span class="modal-title">&#9889; Auto-Generate Appointment Slots</span><button type="button" class="modal-close" onclick="closeModal('genSlotsModal')">&#10005;</button></div>
    <form method="POST"><input type="hidden" name="form_action" value="generate_slots">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <label id="mode-fixed-lbl" style="cursor:pointer;border:2px solid var(--primary-light);background:var(--primary-xlight);border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;transition:.14s;">
            <input type="radio" name="slot_mode" value="fixed" checked onchange="setSlotMode('fixed')" style="margin-top:3px;accent-color:var(--primary-light);">
            <div><div style="font-weight:700;font-size:.85rem;color:var(--primary-light);">&#9201; Fixed Time Blocks</div><div style="font-size:.73rem;color:var(--muted);margin-top:3px;">Define exact slots (e.g. 9:00–10:00).</div></div>
          </label>
          <label id="mode-open-lbl" style="cursor:pointer;border:2px solid var(--border);background:white;border-radius:12px;padding:12px 14px;display:flex;gap:10px;align-items:flex-start;transition:.14s;">
            <input type="radio" name="slot_mode" value="open" onchange="setSlotMode('open')" style="margin-top:3px;accent-color:var(--primary-light);">
            <div><div style="font-weight:700;font-size:.85rem;">&#128197; Open Day</div><div style="font-size:.73rem;color:var(--muted);margin-top:3px;">Users choose their own time within office hours.</div></div>
          </label>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>From *</label><input type="date" name="date_from" required min="<?=$today?>" class="form-input"></div>
          <div class="field"><label>To *</label><input type="date" name="date_to" required min="<?=$today?>" class="form-input"></div>
        </div>
        <div class="field"><label>Days of week *</label>
          <div style="display:flex;flex-wrap:wrap;gap:7px;margin-top:5px;">
            <?php foreach(['0'=>'Sun','1'=>'Mon','2'=>'Tue','3'=>'Wed','4'=>'Thu','5'=>'Fri','6'=>'Sat'] as $v=>$l):?>
            <label id="dow<?=$v?>" style="display:flex;align-items:center;gap:4px;font-size:.8rem;cursor:pointer;padding:5px 11px;border:1.5px solid var(--border);border-radius:20px;background:<?=in_array($v,['1','2','3','4','5'])?'var(--primary-xlight)':'white'?>;border-color:<?=in_array($v,['1','2','3','4','5'])?'var(--primary-light)':'var(--border)'?>;color:<?=in_array($v,['1','2','3','4','5'])?'var(--primary-light)':'inherit'?>;transition:.14s;">
              <input type="checkbox" name="days_of_week[]" value="<?=$v?>" <?=in_array($v,['1','2','3','4','5'])?'checked':''?> onchange="toggleDow(this,'dow<?=$v?>')" style="margin:0;"><?=$l?>
            </label>
            <?php endforeach;?>
          </div>
        </div>
        <div id="fixed-opts">
          <div class="field"><label>Time Slots <span style="font-weight:400;color:var(--muted);">(one per line — HH:MM-HH:MM)</span></label>
            <textarea name="slot_times" rows="5" class="form-input" style="font-family:monospace;font-size:.84rem;resize:vertical;">08:00-09:00
09:00-10:00
10:00-11:00
13:00-14:00
14:00-15:00</textarea>
          </div>
        </div>
        <div id="open-opts" style="display:none;">
          <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:11px 14px;font-size:.8rem;color:#92400e;margin-bottom:10px;">&#128197; Users choose their own time within the office hours below.</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="field"><label>Office Opens At</label><input type="time" name="open_start" value="08:00" class="form-input"></div>
            <div class="field"><label>Office Closes At</label><input type="time" name="open_end" value="17:00" class="form-input"></div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="field"><label>Location</label><input type="text" name="location" value="QA Office" class="form-input"></div>
          <div class="field"><label id="cap-label">Capacity per slot</label><input type="number" name="capacity" id="genCapInput" value="1" min="1" max="999" class="form-input"></div>
        </div>
        <div class="field"><label>Purpose / Label</label><input type="text" name="purpose" placeholder="e.g. Walk-in Consultation" class="form-input"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('genSlotsModal')">Cancel</button><button type="submit" class="btn btn-primary">&#9889; Generate Slots</button></div>
    </form>
  </div>
</div>

<!-- Add Blackout -->
<div class="modal-overlay" id="addBlackoutModal">
  <div class="modal" style="max-width:420px;">
    <div class="modal-header"><span class="modal-title">&#128683; Block Date / Mark Unavailable</span><button type="button" class="modal-close" onclick="closeModal('addBlackoutModal')">&#10005;</button></div>
    <form method="POST"><input type="hidden" name="form_action" value="add_blackout">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:13px;">
        <div class="field"><label>Date *</label><input type="date" name="blackout_date" id="bkDateInput" required class="form-input" min="<?=$today?>"></div>
        <div class="field"><label>Type</label>
          <select name="type" class="form-input">
            <option value="holiday">&#127958; Holiday</option>
            <option value="event">&#128203; Office Event</option>
            <option value="maintenance">&#128295; Maintenance</option>
            <option value="other">&#128204; Other</option>
          </select>
        </div>
        <div class="field"><label>Reason</label><input type="text" name="reason" class="form-input" placeholder="e.g. National holiday, Office seminar…"></div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 13px;font-size:.78rem;color:#92400e;">&#9888;&#65039; Any slots on this date will be automatically deactivated.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('addBlackoutModal')">Cancel</button><button type="submit" class="btn btn-danger">Block This Date</button></div>
    </form>
  </div>
</div>

<!-- Reject Reservation -->
<div class="modal-overlay" id="rejectResModal">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header"><span class="modal-title">Reject Reservation</span><button type="button" class="modal-close" onclick="closeModal('rejectResModal')">&#10005;</button></div>
    <form method="POST"><input type="hidden" name="form_action" value="update_reservation"><input type="hidden" name="status" value="rejected"><input type="hidden" name="res_id" id="rejectResId">
      <div class="modal-body"><div class="field"><label>Reason *</label><textarea name="reason" rows="3" class="form-input" placeholder="Explain why the reservation is rejected…" style="resize:vertical;" required></textarea></div></div>
      <div class="modal-footer"><button type="button" class="btn btn-outline" onclick="closeModal('rejectResModal')">Cancel</button><button type="submit" class="btn btn-danger">Reject</button></div>
    </form>
  </div>
</div>

<script>
/* ─── Calendar data injected from PHP ─── */
var SC_CAL = <?=json_encode($cal_data, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP)?>;
var SC_BK  = <?=json_encode($bk_set,  JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP)?>;
var SC_TODAY = '<?=$today?>';

var MN  = ['January','February','March','April','May','June','July','August','September','October','November','December'];
var SMN = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
var DN  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

function pad(n){ return n < 10 ? '0'+n : ''+n; }
function dstr(y,m,d){ return y+'-'+pad(m+1)+'-'+pad(d); }

var todDate = new Date(SC_TODAY+'T00:00:00');
var scY = todDate.getFullYear(), scM = todDate.getMonth(), scSelDate = null;

function renderCal(){
  var grid = document.getElementById('sc-grid');
  var ml   = document.getElementById('sc-mlabel');
  if(!grid) return;

  ml.textContent = MN[scM]+' '+scY;
  document.getElementById('sc-prev').disabled = (scY===todDate.getFullYear() && scM===todDate.getMonth());
  document.getElementById('sc-next').disabled = (scY > todDate.getFullYear()+1 || (scY===todDate.getFullYear() && scM >= todDate.getMonth()+3));

  var no=0, np=0, nf=0, nb=0;
  var calKeys = Object.keys(SC_CAL);
  for(var i=0;i<calKeys.length;i++){
    var d=calKeys[i];
    var dy=parseInt(d.slice(0,4)), dm=parseInt(d.slice(5,7))-1;
    if(dy===scY && dm===scM){
      var r=SC_CAL[d], av=parseInt(r.total_cap||0)-parseInt(r.booked||0);
      if(av<=0) nf++; else if(parseInt(r.booked||0)>0) np++; else no++;
    }
  }
  var bkKeys = Object.keys(SC_BK);
  for(var i=0;i<bkKeys.length;i++){
    var d=bkKeys[i];
    var dy=parseInt(d.slice(0,4)), dm=parseInt(d.slice(5,7))-1;
    if(dy===scY && dm===scM) nb++;
  }
  document.getElementById('sc-n-open').textContent    = no;
  document.getElementById('sc-n-partial').textContent = np;
  document.getElementById('sc-n-full').textContent    = nf;
  document.getElementById('sc-n-bk').textContent      = nb;

  var fd    = new Date(scY,scM,1).getDay();
  var dim   = new Date(scY,scM+1,0).getDate();
  var dip   = new Date(scY,scM,0).getDate();
  var total = Math.ceil((fd+dim)/7)*7;
  var html  = '';

  for(var i=0;i<total;i++){
    var day,mo,yr,other=false;
    if(i<fd){
      day=dip-fd+i+1; mo=scM-1; yr=scY;
      if(mo<0){mo=11;yr--;} other=true;
    } else if(i>=fd+dim){
      day=i-fd-dim+1; mo=scM+1; yr=scY;
      if(mo>11){mo=0;yr++;} other=true;
    } else {
      day=i-fd+1; mo=scM; yr=scY;
    }
    var ds    = dstr(yr,mo,day);
    var isT   = (ds===SC_TODAY);
    var isSel = (ds===scSelDate);
    var isPast= (ds<SC_TODAY);
    var row   = (!other) ? (SC_CAL[ds]||null) : null;
    var bk    = (!other) ? (SC_BK[ds] ||null) : null;

    var cls = 'sc-cell';
    if(other)  cls += ' other';
    if(isT)    cls += ' sc-today';
    if(isSel)  cls += ' sc-sel';

    var lbl='', bar='';
    if(!other && !isPast){
      cls += ' clickable';
      if(bk){
        cls += ' c-bk';
        lbl = (bk.type||'blocked');
      } else if(row){
        var av = parseInt(row.total_cap||0) - parseInt(row.booked||0);
        if(av<=0)                 { cls+=' c-full';    lbl='Full'; }
        else if(parseInt(row.booked||0)>0){ cls+=' c-partial'; lbl=av+' open'; }
        else                      { cls+=' c-open';    lbl=row.total_slots+' slot'+(parseInt(row.total_slots)>1?'s':''); }
        bar='<div class="sc-bar"></div>';
      }
    }

    var oc = (!other && !isPast) ? 'onclick="selectCalDate(\''+ds+'\')"' : '';
    html += '<div class="'+cls+'" '+oc+'>'
          + '<div class="sc-num">'+day+'</div>'
          + (lbl ? '<div class="sc-lbl">'+lbl+'</div>' : '')
          + bar
          + '</div>';
  }
  grid.innerHTML = html;
}

function scNav(d){
  scM+=d;
  if(scM>11){scM=0;scY++;}
  if(scM<0) {scM=11;scY--;}
  renderCal();
}

function selectCalDate(d){
  scSelDate=d;
  renderCal();
  renderDayPanel(d);
}

function renderDayPanel(d){
  var t=document.getElementById('dp-title');
  var h=document.getElementById('dp-hint');
  var b=document.getElementById('dp-body');
  var a=document.getElementById('dp-actions');
  if(!t) return;

  var addDate=document.getElementById('addSlotDate');
  var bkDate =document.getElementById('bkDateInput');
  if(addDate) addDate.value=d;
  if(bkDate)  bkDate.value=d;

  var dt=new Date(d+'T00:00:00');
  t.textContent = DN[dt.getDay()]+', '+SMN[dt.getMonth()]+' '+dt.getDate()+', '+dt.getFullYear();
  a.style.display='flex';

  var bk  = SC_BK[d]  || null;
  var row = SC_CAL[d] || null;

  if(bk){
    h.textContent='Blocked: '+(bk.reason||bk.type);
    b.innerHTML='<div style="padding:22px;text-align:center;">'
      +'<div style="font-size:2rem;margin-bottom:8px;">&#128683;</div>'
      +'<div style="font-weight:700;color:#92400e;text-transform:uppercase;">'+(bk.type||'').toUpperCase()+' &mdash; UNAVAILABLE</div>'
      +'<div style="font-size:.8rem;color:var(--muted);margin-top:5px;">'+(bk.reason||'')+'</div>'
      +'</div>';
    return;
  }

  if(!row){
    h.textContent='No slots scheduled yet';
    b.innerHTML='<div style="padding:28px 16px;text-align:center;color:var(--muted);">'
      +'<div style="font-size:1.7rem;margin-bottom:8px;">&#128219;</div>'
      +'<p style="font-size:.82rem;">No slots on this date.</p>'
      +'<button class="btn btn-primary btn-sm" style="margin-top:10px;" onclick="addSlotForDate()">+ Add Slot</button>'
      +'</div>';
    return;
  }

  var av  = parseInt(row.total_cap||0) - parseInt(row.booked||0);
  var pct = row.total_cap>0 ? Math.round((row.booked/row.total_cap)*100) : 0;
  var barCol = pct>=100?'#ef4444':(pct>50?'#f59e0b':'#22c55e');
  h.textContent=row.total_slots+' slot(s) \u00b7 '+row.booked+' booked \u00b7 '+av+' available';

  b.innerHTML=''
    +'<a href="schedule.php?tab=slots&date='+d+'" class="btn btn-outline btn-sm" style="width:100%;justify-content:center;display:flex;">&#128203; Manage Slots for This Date</a>'
    +'<div style="background:#f8fafc;border:1.5px solid #e2e8f0;border-radius:10px;padding:12px 14px;">'
    +'<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;text-align:center;margin-bottom:10px;">'
    +'<div><div style="font-size:1.25rem;font-weight:800;color:#16a34a;">'+row.total_slots+'</div><div style="font-size:.63rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Slots</div></div>'
    +'<div><div style="font-size:1.25rem;font-weight:800;color:#d97706;">'+row.booked+'</div><div style="font-size:.63rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Booked</div></div>'
    +'<div><div style="font-size:1.25rem;font-weight:800;color:'+(av>0?'#16a34a':'#dc2626')+';">'+av+'</div><div style="font-size:.63rem;color:var(--muted);text-transform:uppercase;font-weight:700;">Open</div></div>'
    +'</div>'
    +'<div style="height:6px;border-radius:6px;background:#e5e7eb;overflow:hidden;"><div style="height:100%;width:'+pct+'%;background:'+barCol+';border-radius:6px;"></div></div>'
    +'<div style="text-align:right;font-size:.68rem;font-weight:700;color:'+barCol+';margin-top:3px;">'+pct+'% booked</div>'
    +'</div>';
}

function addSlotForDate() { openModal('addSlotModal'); }
function viewDateSlots()  { if(scSelDate) window.location.href='schedule.php?tab=slots&date='+scSelDate; }
function markBlackout()   { openModal('addBlackoutModal'); }
function quickBlackout(d) { var el=document.getElementById('bkDateInput'); if(el) el.value=d; openModal('addBlackoutModal'); }
function openRejectModal(id){ document.getElementById('rejectResId').value=id; openModal('rejectResModal'); }

function setSlotMode(mode){
  var fixedOpts = document.getElementById('fixed-opts');
  var openOpts  = document.getElementById('open-opts');
  var fixedLbl  = document.getElementById('mode-fixed-lbl');
  var openLbl   = document.getElementById('mode-open-lbl');
  var capLbl    = document.getElementById('cap-label');
  var capInp    = document.getElementById('genCapInput');
  if(!fixedOpts||!openOpts) return;
  if(mode==='fixed'){
    fixedOpts.style.display='';
    openOpts.style.display='none';
    fixedLbl.style.border='2px solid var(--primary-light)';
    fixedLbl.style.background='var(--primary-xlight)';
    openLbl.style.border='2px solid var(--border)';
    openLbl.style.background='white';
    if(capLbl) capLbl.textContent='Capacity per slot';
    if(capInp) capInp.value='1';
  } else {
    fixedOpts.style.display='none';
    openOpts.style.display='';
    openLbl.style.border='2px solid var(--primary-light)';
    openLbl.style.background='var(--primary-xlight)';
    fixedLbl.style.border='2px solid var(--border)';
    fixedLbl.style.background='white';
    if(capLbl) capLbl.textContent='Max concurrent bookings';
    if(capInp) capInp.value='99';
  }
}

function toggleDow(cb,id){
  var el=document.getElementById(id);
  if(!el) return;
  el.style.background  = cb.checked ? 'var(--primary-xlight)' : 'white';
  el.style.borderColor = cb.checked ? 'var(--primary-light)'  : 'var(--border)';
  el.style.color       = cb.checked ? 'var(--primary-light)'  : 'inherit';
}

document.addEventListener('DOMContentLoaded', function(){ renderCal(); });
</script>
</body>
</html>