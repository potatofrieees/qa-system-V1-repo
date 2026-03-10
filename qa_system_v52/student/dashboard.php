<?php
session_start();
include '../database/db_connect.php';
if (!isset($_SESSION['user_id'])) { header('Location: ../login.php'); exit; }
$active_nav = 'dashboard';
$uid = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$name = htmlspecialchars(explode(' ', $_SESSION['name'] ?? 'Student')[0]);

// Stats
$my_appts    = (int)$conn->query("SELECT COUNT(*) c FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id WHERE a.booked_by=$uid AND a.status NOT IN ('cancelled') AND s.slot_date>='$today'")->fetch_assoc()['c'];
$my_proposals= (int)$conn->query("SELECT COUNT(*) c FROM proposals WHERE submitted_by=$uid AND deleted_at IS NULL")->fetch_assoc()['c'];
$my_reserv   = (int)$conn->query("SELECT COUNT(*) c FROM room_reservations WHERE reserved_by=$uid AND status NOT IN ('cancelled')")->fetch_assoc()['c'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];

// Upcoming appointments
$upcoming_q = $conn->query("SELECT a.*,s.slot_date,s.start_time,s.end_time,s.location FROM appointments a JOIN appointment_slots s ON s.id=a.slot_id WHERE a.booked_by=$uid AND a.status NOT IN ('cancelled','completed') AND s.slot_date>='$today' ORDER BY s.slot_date,s.start_time LIMIT 3");

// Recent proposals
$props_q = $conn->query("SELECT id,proposal_code,title,status,updated_at FROM proposals WHERE submitted_by=$uid AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 4");

// Announcements targeting all or student
$anns_q = $conn->query("SELECT title,body,type,created_at FROM announcements WHERE is_active=1 AND (target='all' OR target='student') AND (expires_at IS NULL OR expires_at>NOW()) ORDER BY pinned DESC,created_at DESC LIMIT 3");

$status_colors=['pending'=>['#fff7ed','#9a3412'],'confirmed'=>['#eff6ff','#1e40af'],'cancelled'=>['#fef2f2','#991b1b'],'completed'=>['#f0fdf4','#166534'],'draft'=>['#f9fafb','#374151'],'submitted'=>['#eff6ff','#1e40af'],'under_review'=>['#fefce8','#92400e'],'revision_requested'=>['#fff7ed','#9a3412'],'approved'=>['#f0fdf4','#166534'],'rejected'=>['#fef2f2','#991b1b']];
$ann_colors=['general'=>['#eff6ff','#1e40af','📢'],'urgent'=>['#fef2f2','#991b1b','🚨'],'deadline'=>['#fff7ed','#9a3412','⏰'],'event'=>['#f0fdf4','#166534','📅']];
?>
<!DOCTYPE html><html lang="en"><head><title>Student Dashboard — QAIAO Portal</title><?php include 'head.php';?></head>
<body><?php include 'sidebar.php';?>
<div class="main-content">
<div class="topbar">
  <button class="sidebar-toggle" id="sidebar-toggle"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg></button>
  <div class="topbar-title">Student Dashboard</div>
  <div class="topbar-right">
    <a href="appointments.php" class="btn btn-primary btn-sm">📅 Book Appointment</a>
    <a href="notifications.php" class="notif-btn"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg><?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?></a>
  </div>
</div>
<div class="page-body">
  <div class="page-header" style="margin-bottom:20px;">
    <div>
      <h1 class="page-heading">Welcome back, <?=$name?>! 👋</h1>
      <p class="page-subheading">Here's your QAIAO student portal overview for <?=date('l, F j, Y')?></p>
    </div>
  </div>

  <!-- Stats -->
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px;">
    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;border-left:4px solid #2563a8;">
      <div style="width:44px;height:44px;border-radius:12px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">📅</div>
      <div><div style="font-size:1.7rem;font-weight:800;color:#1e40af;line-height:1;"><?=$my_appts?></div><div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:600;">Upcoming Appts</div></div>
    </div>
    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;border-left:4px solid #059669;">
      <div style="width:44px;height:44px;border-radius:12px;background:#ecfdf5;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">📋</div>
      <div><div style="font-size:1.7rem;font-weight:800;color:#059669;line-height:1;"><?=$my_proposals?></div><div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:600;">My Proposals</div></div>
    </div>
    <div class="card" style="padding:18px;display:flex;align-items:center;gap:14px;border-left:4px solid #d97706;">
      <div style="width:44px;height:44px;border-radius:12px;background:#fffbeb;display:flex;align-items:center;justify-content:center;font-size:1.4rem;">🏢</div>
      <div><div style="font-size:1.7rem;font-weight:800;color:#d97706;line-height:1;"><?=$my_reserv?></div><div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;font-weight:600;">Room Reservations</div></div>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;">
    <!-- Main col -->
    <div style="display:flex;flex-direction:column;gap:18px;">

      <!-- Upcoming appointments -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid var(--border);">
          <h3 style="font-size:.9rem;font-weight:700;">📅 Upcoming Appointments</h3>
          <a href="appointments.php" class="btn btn-ghost btn-sm">Book New</a>
        </div>
        <div style="padding:14px;">
          <?php if(!$upcoming_q||$upcoming_q->num_rows===0):?>
          <div class="empty-state" style="padding:24px;"><p>No upcoming appointments. <a href="appointments.php" style="color:var(--primary-light);">Book one now →</a></p></div>
          <?php else: while($ap=$upcoming_q->fetch_assoc()): $c=$status_colors[$ap['status']]??['#f9fafb','#374151'];?>
          <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border);">
            <div style="width:42px;height:42px;border-radius:10px;background:<?=$c[0]?>;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;">
              <div style="font-size:.7rem;font-weight:700;color:<?=$c[1]?>;text-transform:uppercase;"><?=date('M',strtotime($ap['slot_date']))?></div>
              <div style="font-size:1.1rem;font-weight:800;color:<?=$c[1]?>;line-height:1;"><?=date('j',strtotime($ap['slot_date']))?></div>
            </div>
            <div style="flex:1;"><div style="font-weight:600;font-size:.88rem;"><?=date('l',strtotime($ap['slot_date']))?></div><div style="font-size:.75rem;color:var(--muted);"><?=date('g:i A',strtotime($ap['start_time']))?> – <?=date('g:i A',strtotime($ap['end_time']))?> · <?=htmlspecialchars($ap['location'])?></div></div>
            <span style="background:<?=$c[0]?>;color:<?=$c[1]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;"><?=ucfirst($ap['status'])?></span>
          </div>
          <?php endwhile; endif;?>
        </div>
      </div>

      <!-- My proposals -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1px solid var(--border);">
          <h3 style="font-size:.9rem;font-weight:700;">📋 My Proposals</h3>
          <a href="proposals.php" class="btn btn-ghost btn-sm">Submit New</a>
        </div>
        <div style="padding:14px;">
          <?php if(!$props_q||$props_q->num_rows===0):?>
          <div class="empty-state" style="padding:24px;"><p>No proposals yet. <a href="proposals.php" style="color:var(--primary-light);">Submit your first one →</a></p></div>
          <?php else: while($pr=$props_q->fetch_assoc()): $c=$status_colors[$pr['status']]??['#f9fafb','#374151'];?>
          <div style="display:flex;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid var(--border);">
            <div style="flex:1;min-width:0;"><div style="font-weight:600;font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=htmlspecialchars($pr['title'])?></div><div style="font-size:.72rem;color:var(--muted);"><?=htmlspecialchars($pr['proposal_code']??'—')?> · <?=date('M j, Y',strtotime($pr['updated_at']))?></div></div>
            <span style="background:<?=$c[0]?>;color:<?=$c[1]?>;padding:3px 9px;border-radius:20px;font-size:.7rem;font-weight:700;white-space:nowrap;"><?=ucfirst(str_replace('_',' ',$pr['status']))?></span>
          </div>
          <?php endwhile; endif;?>
        </div>
      </div>
    </div>

    <!-- Sidebar: Quick actions + Announcements -->
    <div style="display:flex;flex-direction:column;gap:16px;">
      <div class="card" style="padding:16px;">
        <h3 style="font-size:.85rem;font-weight:700;margin-bottom:12px;">⚡ Quick Actions</h3>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <a href="appointments.php" class="btn btn-primary" style="width:100%;justify-content:center;display:flex;align-items:center;gap:6px;">📅 Book Appointment</a>
          <a href="proposals.php" class="btn btn-outline" style="width:100%;justify-content:center;display:flex;align-items:center;gap:6px;">📋 Submit Proposal</a>
          <a href="appointments.php?tab=reservations" class="btn btn-outline" style="width:100%;justify-content:center;display:flex;align-items:center;gap:6px;">🏢 Reserve a Room</a>
          <a href="profile.php" class="btn btn-ghost" style="width:100%;justify-content:center;display:flex;align-items:center;gap:6px;">👤 My Profile</a>
        </div>
      </div>
      <div class="card" style="padding:16px;">
        <h3 style="font-size:.85rem;font-weight:700;margin-bottom:12px;">📢 Announcements</h3>
        <?php if(!$anns_q||$anns_q->num_rows===0):?>
        <p style="font-size:.8rem;color:var(--muted);">No announcements at this time.</p>
        <?php else: while($ann=$anns_q->fetch_assoc()): $ac=$ann_colors[$ann['type']]??['#f9fafb','#374151','📢'];?>
        <div style="border-left:3px solid <?=$ac[1]?>;padding:8px 12px;margin-bottom:8px;background:<?=$ac[0]?>;border-radius:0 8px 8px 0;">
          <div style="font-size:.78rem;font-weight:700;color:<?=$ac[1]?>;margin-bottom:3px;"><?=$ac[2]?> <?=htmlspecialchars($ann['title'])?></div>
          <div style="font-size:.73rem;color:var(--muted);line-height:1.4;"><?=htmlspecialchars(mb_substr($ann['body'],0,80))?><?=mb_strlen($ann['body'])>80?'…':''?></div>
        </div>
        <?php endwhile; endif;?>
      </div>
    </div>
  </div>
</div>
</div>
</body></html>
