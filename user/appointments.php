<?php
session_start();
include '../database/db_connect.php';
$active_nav = 'appointments';
$me = (int)$_SESSION['user_id'];

// Ensure required columns exist (safe runtime migration)
@$conn->query("ALTER TABLE appointment_slots ADD COLUMN IF NOT EXISTS is_open_day TINYINT(1) NOT NULL DEFAULT 0");
@$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS chosen_start TIME NULL");
@$conn->query("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS chosen_end TIME NULL");

// Load user info for pre-filling forms
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
            // Validate chosen times for open-day slots
            if ($is_open && (!$chosen_start || !$chosen_end)) {
                header("Location: appointments.php?msg=".urlencode("Please choose your preferred time.")."&typ=e"); exit;
            }
            $booked  = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE slot_id=$sid AND status NOT IN ('cancelled')")->fetch_assoc()['c'];
            $already = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE slot_id=$sid AND booked_by=$me AND status NOT IN ('cancelled')")->fetch_assoc()['c'];
            if ($booked < $slot['capacity'] && !$already) {
                $cs = $chosen_start ? "'$chosen_start'" : 'NULL';
                $ce = $chosen_end   ? "'$chosen_end'"   : 'NULL';
                $conn->query("INSERT INTO appointments (slot_id,booked_by,purpose,notes,chosen_start,chosen_end) VALUES ($sid,$me,'$purpose','$notes',$cs,$ce)");
                $time_show = $chosen_start ? $chosen_start.' – '.$chosen_end : $slot['start_time'];
                $msg2 = $conn->real_escape_string("New appointment booked for {$slot['slot_date']} at $time_show");
                $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
                if ($admins) while ($adm = $admins->fetch_assoc())
                    $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$adm['id']},'system','New Appointment','$msg2')");
                header("Location: appointments.php?msg=".urlencode("Appointment booked successfully!")."&typ=s"); exit;
            } elseif ($already) {
                header("Location: appointments.php?msg=".urlencode("You already have a booking for this slot.")."&typ=e"); exit;
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
        $consent = isset($_POST['consent']) ? 1 : 0;
        if (!$consent) { header("Location: appointments.php?tab=reservations&msg=".urlencode("Consent is required.")."&typ=e"); exit; }
        $purpose_res  = $conn->real_escape_string(trim($_POST['purpose_reservation'] ?? ''));
        $room  = $conn->real_escape_string(trim($_POST['room_name'] ?? 'QA Office'));
        $date_of_use  = $conn->real_escape_string($_POST['date_of_use'] ?? '');
        $time_start   = $conn->real_escape_string($_POST['time_start'] ?? '');
        $time_end     = $conn->real_escape_string($_POST['time_end'] ?? '');
        $participants = $conn->real_escape_string(trim($_POST['num_participants'] ?? ''));
        $equipment    = $conn->real_escape_string(trim($_POST['equipment'] ?? ''));
        $add_notes    = $conn->real_escape_string(trim($_POST['additional_notes'] ?? ''));
        $position     = $conn->real_escape_string(trim($_POST['position'] ?? ''));
        $dept_off     = $conn->real_escape_string(trim($_POST['department_office'] ?? ''));
        if ($purpose_res && $date_of_use && $time_start && $time_end) {
            $start_dt = $date_of_use.' '.$time_start;
            $end_dt   = $date_of_use.' '.$time_end;
            $desc_str = trim("$position — $dept_off. Equipment: $equipment. Notes: $add_notes");
            $conn->query("INSERT INTO room_reservations (title,description,room_name,reserved_by,start_datetime,end_datetime,attendees) VALUES ('$purpose_res','$desc_str','$room',$me,'$start_dt','$end_dt','$participants')");
            $rid = $conn->insert_id;
            if ($rid) {
                $un=$conn->real_escape_string($_SESSION['name']??'');
                $admins = $conn->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.role_key IN ('qa_director','qa_staff') AND u.status='active'");
                if ($admins) while ($a=$admins->fetch_assoc())
                    $conn->query("INSERT INTO notifications (user_id,type,title,message) VALUES ({$a['id']},'system','Room Reservation Request','$un submitted a room reservation request for $date_of_use')");
            }
        }
        header("Location: appointments.php?tab=reservations&msg=".urlencode("Room reservation submitted for review!")."&typ=s"); exit;
    }
    header("Location: appointments.php"); exit;
}

$tab      = in_array($_GET['tab'] ?? 'book', ['book','my','reservations']) ? ($_GET['tab'] ?? 'book') : 'book';$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';
$today    = date('Y-m-d');

/* ── Load ALL slots for calendar (next 3 months) ──────────── */
$cal_end = date('Y-m-d', strtotime('+3 months'));
$all_slots_q = $conn->query("
    SELECT s.id, s.slot_date, s.start_time, s.end_time, s.location, s.purpose,
           s.capacity, s.is_active,
           (SELECT COUNT(*) FROM appointments a WHERE a.slot_id=s.id AND a.status NOT IN ('cancelled')) AS booked_count,
           (SELECT COUNT(*) FROM appointments a WHERE a.slot_id=s.id AND a.booked_by=$me AND a.status NOT IN ('cancelled')) AS my_booking
    FROM appointment_slots s
    WHERE s.slot_date >= '$today' AND s.slot_date <= '$cal_end' AND s.is_active=1
    ORDER BY s.slot_date, s.start_time
");

// Build slot data indexed by date
$slot_data   = []; // date => array of slot rows
$date_status = []; // date => 'available'|'full'|'mine'
if ($all_slots_q) {
    while ($sl = $all_slots_q->fetch_assoc()) {
        $d = $sl['slot_date'];
        if (!isset($slot_data[$d])) $slot_data[$d] = [];
        $slot_data[$d][] = $sl;
    }
}
foreach ($slot_data as $d => $slots) {
    $has_mine = false; $any_avail = false;
    foreach ($slots as $s) {
        if ($s['my_booking'] > 0) $has_mine = true;
        if (($s['capacity'] - $s['booked_count']) > 0) $any_avail = true;
    }
    if ($has_mine)      $date_status[$d] = 'mine';
    elseif ($any_avail) $date_status[$d] = 'available';
    else                $date_status[$d] = 'full';
}

/* ── My appointments ──────────────────────────────────────── */
$my_appts = $conn->query("SELECT a.*,s.slot_date,s.start_time,s.end_time,s.location,s.purpose AS slot_purpose
    FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id
    WHERE a.booked_by=$me ORDER BY s.slot_date DESC LIMIT 50");

/* ── My reservations ──────────────────────────────────────── */
$my_res = $conn->query("SELECT * FROM room_reservations WHERE reserved_by=$me ORDER BY start_datetime DESC LIMIT 50");

$notif_count  = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$me AND is_read=0")->fetch_assoc()['c'];
$upcoming_cnt = (int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id WHERE a.booked_by=$me AND a.status IN ('pending','confirmed') AND s.slot_date >= '$today'")->fetch_assoc()['c'];

// Stats for summary bar
$avail_days = count(array_filter($date_status, fn($s) => $s === 'available'));
$full_days  = count(array_filter($date_status, fn($s) => $s === 'full'));
$mine_days  = count(array_filter($date_status, fn($s) => $s === 'mine'));

$status_colors = [
    'pending'   => ['bg'=>'#fffbeb','text'=>'#92400e','border'=>'#fde68a'],
    'confirmed' => ['bg'=>'#ecfdf5','text'=>'#065f46','border'=>'#6ee7b7'],
    'cancelled' => ['bg'=>'#fef2f2','text'=>'#991b1b','border'=>'#fca5a5'],
    'completed' => ['bg'=>'#f0f9ff','text'=>'#0c4a6e','border'=>'#7dd3fc'],
    'no_show'   => ['bg'=>'#f9fafb','text'=>'#374151','border'=>'#d1d5db'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<title>Appointments — QA System</title>
<?php include 'head.php'; ?>
<style>
/* ================================================================
   CALENDAR STYLES
================================================================ */
.appt-page-grid {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 22px;
    align-items: start;
}
@media (max-width: 960px) {
    .appt-page-grid { grid-template-columns: 1fr; }
    .slot-sticky-panel { position: static !important; }
}

/* Calendar Card */
.cal-card {
    background: white;
    border-radius: 18px;
    border: 1.5px solid var(--border);
    box-shadow: 0 4px 24px rgba(30,58,138,.07);
    overflow: hidden;
}
.cal-top-bar {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%);
    padding: 22px 24px 18px;
    color: white;
}
.cal-top-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 14px;
}
.cal-month-name {
    font-family: 'Playfair Display', serif;
    font-size: 1.3rem;
    font-weight: 700;
    letter-spacing: .3px;
}
.cal-year-badge {
    font-size: .8rem;
    opacity: .65;
    font-weight: 500;
    margin-left: 8px;
}
.cal-nav-btn {
    width: 38px; height: 38px;
    border-radius: 50%;
    border: 2px solid rgba(255,255,255,.25);
    background: rgba(255,255,255,.1);
    color: white;
    cursor: pointer;
    font-size: 1.1rem;
    display: flex; align-items: center; justify-content: center;
    transition: all .18s;
    backdrop-filter: blur(4px);
}
.cal-nav-btn:hover:not(:disabled) {
    background: rgba(255,255,255,.25);
    border-color: rgba(255,255,255,.5);
}
.cal-nav-btn:disabled { opacity: .3; cursor: default; }

/* Mini stats in header */
.cal-header-stats {
    display: flex;
    gap: 10px;
}
.cal-stat-pill {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 600;
    backdrop-filter: blur(4px);
}
.cal-stat-pill.green  { background: rgba(34,197,94,.2);  border: 1px solid rgba(34,197,94,.4); }
.cal-stat-pill.red    { background: rgba(239,68,68,.2);   border: 1px solid rgba(239,68,68,.4); }
.cal-stat-pill.blue   { background: rgba(96,165,250,.2);  border: 1px solid rgba(96,165,250,.4); }
.cal-stat-dot { width: 8px; height: 8px; border-radius: 50%; }
.cal-stat-dot.green { background: #4ade80; }
.cal-stat-dot.red   { background: #f87171; }
.cal-stat-dot.blue  { background: #93c5fd; }

/* Day name row */
.cal-day-names {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background: #f1f5f9;
    border-bottom: 1.5px solid #e2e8f0;
}
.cal-day-name {
    text-align: center;
    padding: 10px 0;
    font-size: .68rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: #64748b;
}
.cal-day-name.wkend { color: #b91c1c; }

/* Cell grid */
.cal-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: 0;
}
.cal-cell {
    position: relative;
    min-height: 80px;
    padding: 8px 6px 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    background: white;
    cursor: default;
    transition: all .15s ease;
    overflow: hidden;
}
.cal-cell:hover.clickable {
    background: #f8faff;
    z-index: 1;
}
.cal-cell.other-month {
    background: #fafafa;
}
.cal-cell.other-month .cal-num { color: #d1d5db; }

/* Today */
.cal-cell.is-today { background: #fefce8; }

/* Status backgrounds */
.cal-cell.s-available {
    background: linear-gradient(160deg, #f0fdf4 60%, #dcfce7 100%);
    cursor: pointer;
    border-bottom-color: #bbf7d0;
    border-right-color: #bbf7d0;
}
.cal-cell.s-available:hover {
    background: linear-gradient(160deg, #dcfce7 0%, #bbf7d0 100%);
    transform: scale(1.01);
    box-shadow: 0 2px 12px rgba(22,163,74,.15);
    z-index: 2;
}
.cal-cell.s-full {
    background: linear-gradient(160deg, #fff5f5 60%, #fee2e2 100%);
    cursor: pointer;
    border-bottom-color: #fecaca;
    border-right-color: #fecaca;
}
.cal-cell.s-full:hover {
    background: linear-gradient(160deg, #fee2e2 0%, #fecaca 100%);
    z-index: 2;
}
.cal-cell.s-mine {
    background: linear-gradient(160deg, #eff6ff 60%, #dbeafe 100%);
    cursor: pointer;
    border-bottom-color: #bfdbfe;
    border-right-color: #bfdbfe;
}
.cal-cell.s-mine:hover {
    background: linear-gradient(160deg, #dbeafe 0%, #bfdbfe 100%);
    transform: scale(1.01);
    box-shadow: 0 2px 12px rgba(37,99,235,.15);
    z-index: 2;
}

/* Selected state */
.cal-cell.selected {
    box-shadow: inset 0 0 0 2.5px #2563eb;
    z-index: 3;
}
.cal-cell.s-available.selected { box-shadow: inset 0 0 0 2.5px #16a34a; }
.cal-cell.s-full.selected      { box-shadow: inset 0 0 0 2.5px #dc2626; }
.cal-cell.s-mine.selected      { box-shadow: inset 0 0 0 2.5px #2563eb; }

/* Date number */
.cal-num {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem;
    font-weight: 600;
    color: #374151;
    flex-shrink: 0;
    margin-bottom: 4px;
}
.cal-cell.is-today .cal-num {
    background: #1e3a8a;
    color: white;
    font-weight: 700;
}
.cal-cell.s-available .cal-num { color: #166534; font-weight: 700; }
.cal-cell.s-full      .cal-num { color: #991b1b; font-weight: 700; }
.cal-cell.s-mine      .cal-num { color: #1e40af; font-weight: 700; }

/* Status bar at bottom of cell */
.cal-cell-bar {
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    border-radius: 0;
}
.s-available .cal-cell-bar { background: #22c55e; }
.s-full      .cal-cell-bar { background: #ef4444; }
.s-mine      .cal-cell-bar { background: #3b82f6; }

/* Slot dots */
.cal-dots {
    display: flex;
    gap: 3px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 2px;
}
.cal-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    flex-shrink: 0;
}
.cal-dot.g { background: #22c55e; }
.cal-dot.r { background: #ef4444; }
.cal-dot.b { background: #3b82f6; }

/* Count label */
.cal-count-label {
    font-size: .6rem;
    font-weight: 700;
    margin-top: 3px;
    text-align: center;
    line-height: 1.2;
    letter-spacing: .2px;
}
.s-available .cal-count-label { color: #15803d; }
.s-full      .cal-count-label { color: #b91c1c; }
.s-mine      .cal-count-label { color: #1d4ed8; }

/* Legend row */
.cal-legend {
    display: flex;
    align-items: center;
    gap: 18px;
    flex-wrap: wrap;
    padding: 14px 20px;
    border-top: 1.5px solid #f1f5f9;
    background: #f8fafc;
}
.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .75rem;
    color: #64748b;
    font-weight: 500;
}
.legend-swatch {
    width: 14px; height: 14px;
    border-radius: 4px;
    border: 1.5px solid transparent;
}
.legend-swatch.avail { background: #dcfce7; border-color: #22c55e; }
.legend-swatch.full  { background: #fee2e2; border-color: #ef4444; }
.legend-swatch.mine  { background: #dbeafe; border-color: #3b82f6; }
.legend-swatch.today { background: #1e3a8a; border-radius: 50%; width: 14px; height: 14px; }
.legend-hint { margin-left: auto; font-size: .72rem; color: #94a3b8; font-style: italic; }

/* ================================================================
   SLOT PANEL (right side)
================================================================ */
.slot-sticky-panel {
    position: sticky;
    top: 80px;
}
.slot-panel-card {
    background: white;
    border-radius: 18px;
    border: 1.5px solid var(--border);
    box-shadow: 0 4px 24px rgba(30,58,138,.07);
    overflow: hidden;
}
.slot-panel-head {
    padding: 20px 20px 16px;
    border-bottom: 1.5px solid #f1f5f9;
    background: #f8fafc;
}
.slot-panel-date {
    font-family: 'Playfair Display', serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 3px;
}
.slot-panel-hint {
    font-size: .77rem;
    color: var(--muted);
}
.slot-panel-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-height: 180px;
    max-height: 460px;
    overflow-y: auto;
}
.slot-panel-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 20px;
    color: #94a3b8;
    text-align: center;
    min-height: 200px;
}
.slot-panel-empty svg { width: 48px; height: 48px; opacity: .18; margin-bottom: 12px; }

/* Individual slot card */
.slot-row {
    border-radius: 12px;
    padding: 13px 14px;
    border: 1.5px solid #e5e7eb;
    transition: all .15s;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
.slot-row.avail { border-color: #86efac; background: linear-gradient(135deg,#f0fdf4,#f7fffe); }
.slot-row.avail:hover { border-color: #22c55e; box-shadow: 0 3px 14px rgba(22,163,74,.13); }
.slot-row.full  { border-color: #fca5a5; background: #fff5f5; opacity: .8; }
.slot-row.mine  { border-color: #93c5fd; background: linear-gradient(135deg,#eff6ff,#f5f9ff); }
.slot-row.mine:hover { border-color: #3b82f6; box-shadow: 0 3px 14px rgba(37,99,235,.12); }

.slot-row-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
.slot-row.avail .slot-row-icon { background: #dcfce7; }
.slot-row.full  .slot-row-icon { background: #fee2e2; }
.slot-row.mine  .slot-row-icon { background: #dbeafe; }

.slot-row-info { flex: 1; min-width: 0; }
.slot-row-time {
    font-size: .9rem;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 3px;
}
.slot-row-meta { font-size: .75rem; color: var(--muted); margin-bottom: 6px; }
.slot-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: .7rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 20px;
    margin-bottom: 6px;
}
.slot-badge.green  { background: #dcfce7; color: #166534; }
.slot-badge.red    { background: #fee2e2; color: #991b1b; }
.slot-badge.blue   { background: #dbeafe; color: #1e40af; }
.slot-book-btn {
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    border: none;
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    color: white;
    font-size: .78rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .18s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
    margin-top: 4px;
    font-family: inherit;
}
.slot-book-btn:hover { background: linear-gradient(135deg,#1e40af,#3b82f6); box-shadow: 0 3px 12px rgba(37,99,235,.3); transform: translateY(-1px); }
.slot-mine-label {
    font-size: .75rem;
    color: #2563eb;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 4px;
    margin-top: 4px;
}

/* ================================================================
   SUMMARY CARDS
================================================================ */
.appt-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 22px;
}
.appt-stat-card {
    border-radius: 14px;
    padding: 16px 18px;
    border: 1.5px solid;
    display: flex;
    align-items: center;
    gap: 14px;
}
.appt-stat-card.green { background: #f0fdf4; border-color: #86efac; }
.appt-stat-card.red   { background: #fff5f5; border-color: #fca5a5; }
.appt-stat-card.blue  { background: #eff6ff; border-color: #93c5fd; }
.appt-stat-icon {
    width: 44px; height: 44px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem;
    flex-shrink: 0;
}
.appt-stat-card.green .appt-stat-icon { background: #dcfce7; }
.appt-stat-card.red   .appt-stat-icon { background: #fee2e2; }
.appt-stat-card.blue  .appt-stat-icon { background: #dbeafe; }
.appt-stat-num  { font-size: 1.7rem; font-weight: 800; line-height: 1; }
.appt-stat-lbl  { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: .7px; margin-top: 2px; font-weight: 600; }
.appt-stat-card.green .appt-stat-num { color: #16a34a; }
.appt-stat-card.red   .appt-stat-num { color: #dc2626; }
.appt-stat-card.blue  .appt-stat-num { color: #2563eb; }

/* Earliest-available banner */
.earliest-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(90deg, #ecfdf5, #f0fdf4);
    border: 1.5px solid #6ee7b7;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: .85rem;
    color: #065f46;
    font-weight: 600;
}
.earliest-banner svg { color: #10b981; flex-shrink: 0; }

/* My appointments table badges */
.status-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: .72rem;
    font-weight: 700;
    border: 1.5px solid;
}

@media (max-width: 640px) {
    .appt-stats-row { grid-template-columns: 1fr 1fr; }
    .cal-cell { min-height: 56px; padding: 5px 3px 4px; }
    .cal-num  { width: 24px; height: 24px; font-size: .72rem; }
    .cal-count-label { display: none; }
}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">

  <!-- TOPBAR -->
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
      <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
    </button>
    <div class="topbar-title">Appointments &amp; Scheduling</div>
    <div class="topbar-right">
      <button class="btn btn-outline btn-sm" onclick="openModal('bookRoomModal')">
        🏢 Reserve a Room
      </button>
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

    <div class="page-header" style="margin-bottom:20px;">
      <div>
        <h1 class="page-heading">Appointments &amp; Scheduling</h1>
        <p class="page-subheading">Book a consultation slot or reserve the QA office for meetings</p>
      </div>
    </div>

    <!-- TABS -->
    <div class="tabs-bar" style="margin-bottom:24px;">
      <a href="appointments.php?tab=book" class="tab-pill<?=$tab==='book'?' active':''?>">
        📅 Book Appointment
      </a>
      <a href="appointments.php?tab=my" class="tab-pill<?=$tab==='my'?' active':''?>">
        👤 My Appointments
        <?php if($upcoming_cnt>0):?>
        <span style="background:var(--primary);color:white;border-radius:20px;padding:1px 7px;font-size:.68rem;font-weight:700;margin-left:2px;"><?=$upcoming_cnt?></span>
        <?php endif;?>
      </a>
      <a href="appointments.php?tab=reservations" class="tab-pill<?=$tab==='reservations'?' active':''?>">
        🏢 Room Reservations
      </a>
    </div>

    <?php if($tab === 'book'): ?>
    <!-- ═══════════════════════════════════════════════════════
         BOOK TAB — Calendar + Slot Panel
    ═══════════════════════════════════════════════════════ -->

    <!-- Stats row -->
    <div class="appt-stats-row">
      <div class="appt-stat-card green">
        <div class="appt-stat-icon">📅</div>
        <div>
          <div class="appt-stat-num"><?=$avail_days?></div>
          <div class="appt-stat-lbl">Days Available</div>
        </div>
      </div>
      <div class="appt-stat-card red">
        <div class="appt-stat-icon">🔴</div>
        <div>
          <div class="appt-stat-num"><?=$full_days?></div>
          <div class="appt-stat-lbl">Fully Booked</div>
        </div>
      </div>
      <div class="appt-stat-card blue">
        <div class="appt-stat-icon">✅</div>
        <div>
          <div class="appt-stat-num"><?=$mine_days?></div>
          <div class="appt-stat-lbl">My Bookings</div>
        </div>
      </div>
    </div>

    <!-- Earliest available banner -->
    <?php
    $earliest = null;
    foreach ($date_status as $d => $st) {
        if ($st === 'available') { $earliest = $d; break; }
    }
    ?>
    <?php if($earliest):?>
    <div class="earliest-banner">
      <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
      Earliest available slot: <span style="color:#059669;"><?=date('l, F j, Y', strtotime($earliest))?></span>
      <button onclick="jumpToDate('<?=$earliest?>')" style="margin-left:auto;background:#10b981;color:white;border:none;border-radius:8px;padding:4px 12px;font-size:.75rem;font-weight:700;cursor:pointer;font-family:inherit;">View →</button>
    </div>
    <?php elseif(count($date_status) === 0):?>
    <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:12px;padding:14px 18px;font-size:.85rem;color:#92400e;font-weight:600;margin-bottom:20px;">
      ⚠️ No appointment slots are currently available. Please check back later.
    </div>
    <?php endif;?>

    <!-- Calendar + Panel layout -->
    <div class="appt-page-grid">

      <!-- ── CALENDAR ── -->
      <div class="cal-card">

        <!-- Calendar header -->
        <div class="cal-top-bar">
          <div class="cal-top-row">
            <button class="cal-nav-btn" id="cal-prev" onclick="calNav(-1)" title="Previous month">&#8249;</button>
            <div>
              <span class="cal-month-name" id="cal-month-name">—</span>
              <span class="cal-year-badge" id="cal-year-badge">—</span>
            </div>
            <button class="cal-nav-btn" id="cal-next" onclick="calNav(1)" title="Next month">&#8250;</button>
          </div>
          <div class="cal-header-stats">
            <div class="cal-stat-pill green">
              <div class="cal-stat-dot green"></div>
              <span id="hstat-avail">—</span> Available
            </div>
            <div class="cal-stat-pill red">
              <div class="cal-stat-dot red"></div>
              <span id="hstat-full">—</span> Full
            </div>
            <div class="cal-stat-pill blue">
              <div class="cal-stat-dot blue"></div>
              <span id="hstat-mine">—</span> Booked
            </div>
          </div>
        </div>

        <!-- Day name row -->
        <div class="cal-day-names">
          <div class="cal-day-name wkend">Sun</div>
          <div class="cal-day-name">Mon</div>
          <div class="cal-day-name">Tue</div>
          <div class="cal-day-name">Wed</div>
          <div class="cal-day-name">Thu</div>
          <div class="cal-day-name">Fri</div>
          <div class="cal-day-name wkend">Sat</div>
        </div>

        <!-- Calendar cells -->
        <div class="cal-grid" id="cal-grid"></div>

        <!-- Legend -->
        <div class="cal-legend">
          <div class="legend-item"><div class="legend-swatch today"></div> Today</div>
          <div class="legend-item"><div class="legend-swatch avail"></div> Available</div>
          <div class="legend-item"><div class="legend-swatch full"></div> Fully Booked</div>
          <div class="legend-item"><div class="legend-swatch mine"></div> Your Booking</div>
          <div class="legend-hint">← Click a date to see time slots</div>
        </div>

      </div><!-- /cal-card -->

      <!-- ── SLOT PANEL ── -->
      <div class="slot-sticky-panel">
        <div class="slot-panel-card">
          <div class="slot-panel-head">
            <div class="slot-panel-date" id="panel-date-label">No date selected</div>
            <div class="slot-panel-hint" id="panel-hint">Click any coloured date on the calendar</div>
          </div>
          <div class="slot-panel-body" id="slot-panel-body">
            <div class="slot-panel-empty">
              <svg viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/>
              </svg>
              <p style="font-size:.83rem;line-height:1.5;">Select a highlighted date on the calendar to view available time slots</p>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /appt-page-grid -->

    <?php elseif($tab === 'my'): ?>
    <!-- ═══════════════════════════════════════════════════════
         MY APPOINTMENTS TAB
    ═══════════════════════════════════════════════════════ -->
    <div class="card">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Time</th>
              <th>Location</th>
              <th>Purpose</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if(!$my_appts || $my_appts->num_rows === 0):?>
          <tr><td colspan="6">
            <div class="empty-state">
              <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
              <p>No appointments yet. Go to <strong>Book Appointment</strong> to get started.</p>
            </div>
          </td></tr>
          <?php else: while($ap = $my_appts->fetch_assoc()):
            $sc  = $status_colors[$ap['status']] ?? ['bg'=>'#f9fafb','text'=>'#374151','border'=>'#d1d5db'];
            $past = $ap['slot_date'] < $today;
          ?>
          <tr style="<?=$past?'opacity:.65':''?>">
            <td>
              <div style="font-weight:700;font-size:.88rem;"><?=date('D, M j', strtotime($ap['slot_date']))?></div>
              <div style="font-size:.72rem;color:var(--muted);"><?=date('Y', strtotime($ap['slot_date']))?></div>
            </td>
            <td>
              <div style="font-weight:600;font-size:.85rem;"><?=date('g:i A', strtotime($ap['start_time']))?></div>
              <div style="font-size:.72rem;color:var(--muted);">to <?=date('g:i A', strtotime($ap['end_time']))?></div>
            </td>
            <td class="text-sm"><?=htmlspecialchars($ap['location'])?></td>
            <td class="text-sm text-muted" style="max-width:160px;">
              <?=htmlspecialchars($ap['purpose'] ?? ($ap['slot_purpose'] ?? '—'))?>
            </td>
            <td>
              <span class="status-pill" style="background:<?=$sc['bg']?>;color:<?=$sc['text']?>;border-color:<?=$sc['border']?>;">
                <?=ucfirst($ap['status'])?>
              </span>
            </td>
            <td>
              <?php if(in_array($ap['status'],['pending','confirmed']) && !$past):?>
              <form method="POST" class="swal-confirm-form"
                data-title="Cancel Appointment?"
                data-text="This will free up the slot for others."
                data-icon="warning"
                data-confirm="Yes, Cancel"
                data-cls="qa-btn-red">
                <input type="hidden" name="form_action" value="cancel_appt">
                <input type="hidden" name="appt_id" value="<?=$ap['id']?>">
                <button type="submit" class="btn btn-danger btn-sm">Cancel</button>
              </form>
              <?php else:?>
              <span class="text-sm text-muted">—</span>
              <?php endif;?>
            </td>
          </tr>
          <?php endwhile; endif;?>
          </tbody>
        </table>
      </div>
    </div>

    <?php elseif($tab === 'reservations'): ?>
    <!-- ═══════════════════════════════════════════════════════
         ROOM RESERVATIONS TAB
    ═══════════════════════════════════════════════════════ -->
    <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
      <button class="btn btn-primary" onclick="openModal('bookRoomModal')">
        + New Room Reservation
      </button>
    </div>
    <div class="card">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr><th>Title</th><th>Room</th><th>Start</th><th>End</th><th>Status</th><th>Note</th></tr>
          </thead>
          <tbody>
          <?php if(!$my_res || $my_res->num_rows === 0):?>
          <tr><td colspan="6">
            <div class="empty-state">
              <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 012-2h8a2 2 0 012 2v12a1 1 0 110 2h-3a1 1 0 01-1-1v-2a1 1 0 00-1-1H9a1 1 0 00-1 1v2a1 1 0 01-1 1H4a1 1 0 110-2V4z" clip-rule="evenodd"/></svg>
              <p>No room reservations yet.</p>
            </div>
          </td></tr>
          <?php else: while($res = $my_res->fetch_assoc()):
            $rsc = ['pending'=>['#fffbeb','#92400e','#fde68a'],'approved'=>['#ecfdf5','#065f46','#6ee7b7'],'rejected'=>['#fef2f2','#991b1b','#fca5a5'],'cancelled'=>['#f9fafb','#374151','#d1d5db']][$res['status']] ?? ['#f9fafb','#374151','#d1d5db'];
          ?>
          <tr>
            <td style="max-width:180px;">
              <div style="font-weight:600;font-size:.88rem;"><?=htmlspecialchars($res['title'])?></div>
              <?php if($res['description']):?><div class="text-sm text-muted"><?=htmlspecialchars(mb_substr($res['description'],0,50))?></div><?php endif;?>
            </td>
            <td class="text-sm"><?=htmlspecialchars($res['room_name'])?></td>
            <td class="text-sm">
              <div style="font-weight:600;"><?=date('M j, Y', strtotime($res['start_datetime']))?></div>
              <div class="text-muted"><?=date('g:i A', strtotime($res['start_datetime']))?></div>
            </td>
            <td class="text-sm text-muted"><?=date('g:i A', strtotime($res['end_datetime']))?></td>
            <td>
              <span class="status-pill" style="background:<?=$rsc[0]?>;color:<?=$rsc[1]?>;border-color:<?=$rsc[2]?>;">
                <?=ucfirst($res['status'])?>
              </span>
            </td>
            <td class="text-sm text-muted" style="max-width:130px;">
              <?=htmlspecialchars($res['reject_reason'] ?? '—')?>
            </td>
          </tr>
          <?php endwhile; endif;?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif;?>

  </div><!-- /page-body -->
</div><!-- /main-content -->

<!-- ═══════════════════════════════════════════════════════════
     BOOK SLOT MODAL
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="bookSlotModal">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <span class="modal-title">Book Appointment</span>
      <button type="button" class="modal-close" onclick="closeModal('bookSlotModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="book_slot">
      <input type="hidden" name="slot_id" id="bsSlotId">
      <input type="hidden" name="is_open" id="bsIsOpen" value="0">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:14px;">
        <!-- Slot info box -->
        <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #bfdbfe;border-radius:12px;padding:13px 16px;">
          <div style="font-size:.68rem;font-weight:800;color:#3b82f6;text-transform:uppercase;letter-spacing:.8px;margin-bottom:5px;">Selected Date &amp; Slot</div>
          <div id="bsTimeLabel" style="font-size:.98rem;font-weight:700;color:#1e3a8a;"></div>
          <div id="bsLocLabel"  style="font-size:.78rem;color:#64748b;margin-top:2px;"></div>
        </div>

        <!-- Open-day: user picks own time -->
        <div id="bsTimePickRow" style="display:none;">
          <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:10px;padding:10px 13px;font-size:.8rem;color:#92400e;margin-bottom:10px;">
            📅 This is an open-availability day. Choose your preferred time within office hours.
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="field">
              <label>Preferred Start Time <span style="color:#dc2626;">*</span></label>
              <input type="time" name="chosen_start" id="bsChosenStart" class="form-input">
            </div>
            <div class="field">
              <label>Preferred End Time <span style="color:#dc2626;">*</span></label>
              <input type="time" name="chosen_end" id="bsChosenEnd" class="form-input">
            </div>
          </div>
          <div id="bsOpenWindow" style="font-size:.73rem;color:#64748b;margin-top:4px;"></div>
        </div>

        <div class="field">
          <label>Purpose of visit <span style="color:#dc2626;">*</span></label>
          <input type="text" name="purpose" required class="form-input"
            placeholder="e.g. Document consultation, inquiry, advice…">
        </div>
        <div class="field">
          <label>Additional notes <span style="color:var(--muted);font-weight:400;">(optional)</span></label>
          <textarea name="notes" rows="2" class="form-input"
            placeholder="Any details the QA office should know…" style="resize:vertical;"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('bookSlotModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">✓ Confirm Booking</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     BOOK ROOM MODAL — Full reservation form
═══════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="bookRoomModal">
  <div class="modal" style="max-width:580px;max-height:92vh;overflow-y:auto;">
    <div class="modal-header" style="position:sticky;top:0;z-index:2;background:white;">
      <span class="modal-title">🏢 Room / Office Reservation</span>
      <button type="button" class="modal-close" onclick="closeModal('bookRoomModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="form_action" value="book_room">
      <div class="modal-body" style="display:flex;flex-direction:column;gap:0;padding:0;">

        <!-- Consent -->
        <div style="background:#fff8f8;border-bottom:1.5px solid #fca5a5;padding:16px 20px;">
          <p style="font-size:.8rem;color:#374151;line-height:1.6;margin-bottom:12px;">
            By filling out this form, I voluntarily provide my personal information for the purpose of office reservation. I consent to the collection and use of my personal data in accordance with the <strong>Data Privacy Act of 2012</strong>. My information will not be shared with unauthorized third parties.
          </p>
          <label style="display:flex;align-items:center;gap:8px;font-size:.83rem;cursor:pointer;font-weight:600;color:#374151;">
            <input type="radio" name="consent" value="1" required style="accent-color:#8B0000;width:16px;height:16px;">
            Yes, I consent
          </label>
        </div>

        <!-- A. Requestor Info — pre-filled, read-only hints -->
        <div style="padding:16px 20px;border-bottom:1px solid #f1f5f9;">
          <div style="font-size:.72rem;font-weight:800;color:var(--primary-light);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">A. Requestor's Information</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="field" style="grid-column:1/-1;">
              <label>Full Name <span style="color:#dc2626;">*</span></label>
              <input type="text" name="full_name" class="form-input" required
                value="<?=htmlspecialchars($me_info['name']??'')?>"
                placeholder="Your full name">
            </div>
            <div class="field">
              <label>Position / Designation <span style="color:#dc2626;">*</span></label>
              <input type="text" name="position" class="form-input" required
                value="<?=htmlspecialchars($me_info['role_label']??'')?>"
                placeholder="e.g. Faculty, Program Head">
            </div>
            <div class="field">
              <label>Department / Office <span style="color:#dc2626;">*</span></label>
              <input type="text" name="department_office" class="form-input" required
                value="<?=htmlspecialchars($me_info['college_name']??($me_info['department_name']??'')?? '')?>"
                placeholder="e.g. College of Education">
            </div>
          </div>
        </div>

        <!-- B. Reservation Details -->
        <div style="padding:16px 20px;">
          <div style="font-size:.72rem;font-weight:800;color:var(--primary-light);text-transform:uppercase;letter-spacing:.8px;margin-bottom:12px;">B. Reservation Details</div>
          <div style="display:flex;flex-direction:column;gap:12px;">
            <div class="field">
              <label>Purpose of Reservation <span style="color:#dc2626;">*</span></label>
              <input type="text" name="purpose_reservation" class="form-input" required placeholder="Your answer">
            </div>
            <div class="field">
              <label>Room / Office</label>
              <select name="room_name" class="form-input">
                <option value="QA Office">QA Office</option>
                <option value="Conference Room A">Conference Room A</option>
                <option value="Conference Room B">Conference Room B</option>
                <option value="Other">Other</option>
              </select>
            </div>
            <div class="field">
              <label>Date of Use <span style="color:#dc2626;">*</span></label>
              <input type="date" name="date_of_use" class="form-input" required min="<?=date('Y-m-d')?>">
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div class="field">
                <label>Time to Start <span style="color:#dc2626;">*</span></label>
                <input type="time" name="time_start" class="form-input" required>
              </div>
              <div class="field">
                <label>Time to End <span style="color:#dc2626;">*</span></label>
                <input type="time" name="time_end" class="form-input" required>
              </div>
            </div>
            <div class="field">
              <label>Estimated No. of Participants <span style="color:#dc2626;">*</span></label>
              <input type="number" name="num_participants" class="form-input" required min="1" placeholder="Your answer">
            </div>
            <div class="field">
              <label>Equipment / Materials Needed</label>
              <input type="text" name="equipment" class="form-input" placeholder="e.g. Projector, whiteboard…">
            </div>
            <div class="field">
              <label>Additional Notes</label>
              <textarea name="additional_notes" rows="2" class="form-input" placeholder="Any other details…" style="resize:vertical;"></textarea>
            </div>
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 13px;font-size:.78rem;color:#92400e;">
              ⚠️ Reservations require approval from the QA Director. You will be notified once reviewed.
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="position:sticky;bottom:0;background:white;z-index:2;">
        <button type="button" class="btn btn-outline" onclick="closeModal('bookRoomModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Submit Reservation →</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     CALENDAR JAVASCRIPT
═══════════════════════════════════════════════════════════ -->
<script>
// Data injected from PHP
const SLOT_DATA   = <?= json_encode($slot_data,   JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const DATE_STATUS = <?= json_encode($date_status, JSON_HEX_TAG|JSON_HEX_APOS) ?>;
const TODAY_STR   = '<?= $today ?>';

// Calendar state
const todayD    = new Date(TODAY_STR + 'T00:00:00');
let   curYear   = todayD.getFullYear();
let   curMonth  = todayD.getMonth();
let   selDate   = null;

const MAX_MONTHS_AHEAD = 3;
const MONTH_NAMES = ['January','February','March','April','May','June',
                     'July','August','September','October','November','December'];
const SHORT_MONTHS= ['Jan','Feb','Mar','Apr','May','Jun',
                     'Jul','Aug','Sep','Oct','Nov','Dec'];
const DAY_NAMES   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

function pad(n){ return n < 10 ? '0'+n : ''+n; }
function toDateStr(y,m,d){ return y+'-'+pad(m+1)+'-'+pad(d); }

/* ── Build & render calendar grid ─────────────────────────── */
function renderCalendar() {
    const grid    = document.getElementById('cal-grid');
    const mLabel  = document.getElementById('cal-month-name');
    const yLabel  = document.getElementById('cal-year-badge');
    const prevBtn = document.getElementById('cal-prev');
    const nextBtn = document.getElementById('cal-next');

    mLabel.textContent = MONTH_NAMES[curMonth];
    yLabel.textContent = curYear;

    const isFirst = curYear === todayD.getFullYear() && curMonth === todayD.getMonth();
    const maxDate = new Date(todayD.getFullYear(), todayD.getMonth() + MAX_MONTHS_AHEAD, 1);
    const isLast  = curYear > maxDate.getFullYear() ||
                    (curYear === maxDate.getFullYear() && curMonth >= maxDate.getMonth());
    prevBtn.disabled = isFirst;
    nextBtn.disabled = isLast;

    // Update header stats for this month
    let mAvail=0, mFull=0, mMine=0;
    Object.keys(DATE_STATUS).forEach(d => {
        const [dy,dm] = d.split('-').map(Number);
        if (dy===curYear && dm===curMonth+1) {
            if (DATE_STATUS[d]==='available') mAvail++;
            else if (DATE_STATUS[d]==='full')  mFull++;
            else if (DATE_STATUS[d]==='mine')  mMine++;
        }
    });
    document.getElementById('hstat-avail').textContent = mAvail;
    document.getElementById('hstat-full').textContent  = mFull;
    document.getElementById('hstat-mine').textContent  = mMine;

    const firstDow  = new Date(curYear, curMonth, 1).getDay();
    const daysInMon = new Date(curYear, curMonth+1, 0).getDate();
    const daysInPrev= new Date(curYear, curMonth, 0).getDate();
    const totalCells= Math.ceil((firstDow + daysInMon) / 7) * 7;

    let html = '';
    for (let i = 0; i < totalCells; i++) {
        let day, mo, yr, other = false;
        if (i < firstDow) {
            day = daysInPrev - firstDow + i + 1;
            mo  = curMonth - 1; yr = curYear;
            if (mo < 0) { mo = 11; yr--; }
            other = true;
        } else if (i >= firstDow + daysInMon) {
            day = i - firstDow - daysInMon + 1;
            mo  = curMonth + 1; yr = curYear;
            if (mo > 11) { mo = 0; yr++; }
            other = true;
        } else {
            day = i - firstDow + 1;
            mo  = curMonth; yr = curYear;
        }

        const ds     = toDateStr(yr, mo, day);
        const status = (!other && ds >= TODAY_STR) ? (DATE_STATUS[ds] || null) : null;
        const isToday= ds === TODAY_STR;
        const isSel  = ds === selDate;
        const slots  = SLOT_DATA[ds] || [];

        let cls = 'cal-cell';
        if (other)   cls += ' other-month';
        if (isToday) cls += ' is-today';
        if (isSel)   cls += ' selected';
        if (status)  { cls += ' s-'+status; cls += ' clickable'; }

        // Build dots
        let dots = '';
        if (status && slots.length > 0) {
            const mySlots  = slots.filter(s => s.my_booking > 0).length;
            const avSlots  = slots.filter(s => (s.capacity - s.booked_count) > 0 && s.my_booking == 0).length;
            const fulSlots = slots.length - avSlots - mySlots;
            if (mySlots > 0)  dots += `<div class="cal-dot b"></div>`;
            if (avSlots > 0)  dots += `<div class="cal-dot g"></div>`;
            if (fulSlots > 0) dots += `<div class="cal-dot r"></div>`;
        }

        // Count label
        let countLabel = '';
        if (status === 'available') {
            const n = slots.filter(s=>(s.capacity-s.booked_count)>0).length;
            countLabel = n + ' slot'+(n!==1?'s':'')+' open';
        } else if (status === 'full') {
            countLabel = 'Fully booked';
        } else if (status === 'mine') {
            countLabel = '✓ Booked';
        }

        const onclick = status ? `onclick="selectDate('${ds}')"` : '';
        html += `
        <div class="${cls}" ${onclick}>
          <div class="cal-num">${day}</div>
          ${dots ? `<div class="cal-dots">${dots}</div>` : ''}
          ${countLabel ? `<div class="cal-count-label">${countLabel}</div>` : ''}
          ${status ? '<div class="cal-cell-bar"></div>' : ''}
        </div>`;
    }
    grid.innerHTML = html;
}

function calNav(dir) {
    curMonth += dir;
    if (curMonth > 11) { curMonth = 0; curYear++; }
    if (curMonth < 0)  { curMonth = 11; curYear--; }
    renderCalendar();
}

function selectDate(ds) {
    selDate = ds;
    renderCalendar();
    renderSlotPanel(ds);
}

function jumpToDate(ds) {
    const parts = ds.split('-');
    curYear  = parseInt(parts[0]);
    curMonth = parseInt(parts[1]) - 1;
    selDate  = ds;
    renderCalendar();
    renderSlotPanel(ds);
}

/* ── Slot panel ───────────────────────────────────────────── */
function renderSlotPanel(ds) {
    const panDate = document.getElementById('panel-date-label');
    const panHint = document.getElementById('panel-hint');
    const panBody = document.getElementById('slot-panel-body');

    const d      = new Date(ds + 'T00:00:00');
    const dLabel = DAY_NAMES[d.getDay()] + ', ' + SHORT_MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
    panDate.textContent = dLabel;

    const slots = SLOT_DATA[ds] || [];
    if (slots.length === 0) {
        panHint.textContent = 'No slots on this date';
        panBody.innerHTML = `
        <div class="slot-panel-empty">
          <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6 2a1 1 0 00-1 1v1H4a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V6a2 2 0 00-2-2h-1V3a1 1 0 10-2 0v1H7V3a1 1 0 00-1-1zm0 5a1 1 0 000 2h8a1 1 0 100-2H6z" clip-rule="evenodd"/></svg>
          <p style="font-size:.83rem;">No appointment slots available on this date.</p>
        </div>`;
        return;
    }

    const availCount = slots.filter(s => (s.capacity - s.booked_count) > 0).length;
    panHint.textContent = slots.length + ' slot' + (slots.length !== 1 ? 's' : '') +
        ' · ' + availCount + ' available';

    let html = '';
    slots.forEach(s => {
        const remain = s.capacity - s.booked_count;
        const isMine = s.my_booking > 0;
        const isFull = remain <= 0;
        const cls    = isMine ? 'mine' : (isFull ? 'full' : 'avail');
        const icon   = isMine ? '✅' : (isFull ? '🔴' : '📅');

        const t1 = fmtTime(s.start_time);
        const t2 = fmtTime(s.end_time);
        const isOpenDay = s.is_open_day == 1;

        let badge = '';
        if (isMine)      badge = `<div class="slot-badge blue">✓ Your booking</div>`;
        else if (isFull) badge = `<div class="slot-badge red">🔴 Session fully booked</div>`;
        else             badge = `<div class="slot-badge green">🟢 Session available · ${s.booked_count} booked so far</div>`;

        let action = '';
        if (isMine) {
            action = `<div class="slot-mine-label">✓ You are booked for this slot</div>`;
        } else if (isFull) {
            action = `<div style="font-size:.74rem;color:#dc2626;margin-top:4px;">No spots remaining — check other dates</div>`;
        } else {
            const esc = v => (v||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
            const isOpen = s.is_open_day == 1;
            const btnLabel = isOpen ? '📅 Choose My Time' : 'Book this slot';
            action = `<button class="slot-book-btn"
                onclick="openBookSlot(${s.id},'${esc(t1+' – '+t2)}','${esc(s.location)}','${esc(s.purpose||'')}',${isOpen?1:0},'${esc(s.start_time)}','${esc(s.end_time)}')">
                <svg viewBox="0 0 20 20" fill="currentColor" width="13" height="13"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                ${btnLabel}
            </button>`;
        }

        const loc     = esc2(s.location   || '');
        const purpose = esc2(s.purpose    || '');
        html += `
        <div class="slot-row ${cls}">
          <div class="slot-row-icon">${icon}</div>
          <div class="slot-row-info">
            <div class="slot-row-time">${isOpenDay ? '🗓 Open Day ' : ''}${t1} <span style="font-weight:400;color:var(--muted);font-size:.8rem;">→</span> ${t2}${isOpenDay ? ' <span style="background:#fef3c7;color:#92400e;font-size:.68rem;padding:1px 7px;border-radius:10px;font-weight:700;margin-left:5px;">Choose your time</span>' : ''}</div>
            <div class="slot-row-meta">📍 ${loc}${purpose?' · '+purpose:''}</div>
            ${badge}
            ${action}
          </div>
        </div>`;
    });
    panBody.innerHTML = html;
}

function fmtTime(ts) {
    if (!ts) return '';
    const [h, m] = ts.split(':');
    const hr = parseInt(h, 10);
    return (hr % 12 || 12) + ':' + m + ' ' + (hr >= 12 ? 'PM' : 'AM');
}
function esc2(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function openBookSlot(id, time, loc, purpose, isOpen, winStart, winEnd) {
    document.getElementById('bsSlotId').value  = id;
    document.getElementById('bsIsOpen').value  = isOpen ? '1' : '0';
    document.getElementById('bsTimeLabel').textContent = isOpen ? '🗓 ' + time.split(' – ')[0].replace(/:00/,'').trim() + ' (Open Day)' : '🕐 ' + time;
    document.getElementById('bsLocLabel').textContent  = '📍 ' + loc + (purpose ? '  ·  ' + purpose : '');
    const timeRow = document.getElementById('bsTimePickRow');
    const chosenStart = document.getElementById('bsChosenStart');
    const chosenEnd   = document.getElementById('bsChosenEnd');
    const openWin = document.getElementById('bsOpenWindow');
    if (isOpen) {
        timeRow.style.display = 'block';
        chosenStart.required = true;
        chosenEnd.required   = true;
        chosenStart.min = winStart ? winStart.slice(0,5) : '08:00';
        chosenStart.max = winEnd   ? winEnd.slice(0,5)   : '17:00';
        chosenEnd.min   = winStart ? winStart.slice(0,5) : '08:00';
        chosenEnd.max   = winEnd   ? winEnd.slice(0,5)   : '17:00';
        if (openWin) openWin.textContent = 'Office window: ' + fmtTime(winStart) + ' – ' + fmtTime(winEnd);
        // Set sensible defaults
        if (!chosenStart.value) chosenStart.value = winStart ? winStart.slice(0,5) : '09:00';
        if (!chosenEnd.value)   chosenEnd.value   = winEnd   ? winEnd.slice(0,5)   : '10:00';
    } else {
        timeRow.style.display = 'none';
        if (chosenStart) { chosenStart.required = false; chosenStart.value = ''; }
        if (chosenEnd)   { chosenEnd.required   = false; chosenEnd.value   = ''; }
    }
    openModal('bookSlotModal');
}

/* ── Auto-jump to month with first available slot ─────────── */
function jumpToFirstAvailable() {
    const dates = Object.keys(DATE_STATUS)
        .filter(d => DATE_STATUS[d] === 'available' && d >= TODAY_STR)
        .sort();
    if (dates.length > 0) {
        const p = dates[0].split('-');
        curYear  = parseInt(p[0]);
        curMonth = parseInt(p[1]) - 1;
    }
}

document.addEventListener('DOMContentLoaded', function () {
    jumpToFirstAvailable();
    renderCalendar();
});
</script>
</body>
</html>