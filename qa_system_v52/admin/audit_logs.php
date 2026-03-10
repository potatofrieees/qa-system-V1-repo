<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director']);
$active_nav = 'audit';

if (isset($_GET['export'])) {
    $sq=$conn->real_escape_string(trim($_GET['q']??'')); $aq=$conn->real_escape_string(trim($_GET['action_f']??'')); $uf=(int)($_GET['user_f']??0); $dq=$conn->real_escape_string(trim($_GET['date_f']??''));
    $w=["1=1"]; if($sq)$w[]="(al.action LIKE '%$sq%' OR al.description LIKE '%$sq%' OR u.name LIKE '%$sq%')"; if($aq)$w[]="al.action='$aq'"; if($uf)$w[]="al.user_id=$uf"; if($dq)$w[]="DATE(al.created_at)='$dq'";
    $res=$conn->query("SELECT al.created_at,u.name,u.email,al.action,al.entity_type,al.entity_id,al.description,al.ip_address FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE ".implode(' AND ',$w)." ORDER BY al.created_at DESC LIMIT 10000");
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="audit_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w'); fputcsv($out,['Timestamp','User','Email','Action','Entity Type','Entity ID','Description','IP']);
    while($r=$res->fetch_assoc()) fputcsv($out,[$r['created_at'],$r['name'],$r['email'],$r['action'],$r['entity_type'],$r['entity_id'],$r['description'],$r['ip_address']]);
    fclose($out); exit;
}

$search=$_GET['q']??''; $action_f=$_GET['action_f']??''; $user_f=(int)($_GET['user_f']??0); $date_f=$_GET['date_f']??'';
$sq=$conn->real_escape_string($search); $aq=$conn->real_escape_string($action_f); $dq=$conn->real_escape_string($date_f);
$where=["1=1"];
if($search) $where[]="(al.action LIKE '%$sq%' OR al.description LIKE '%$sq%' OR u.name LIKE '%$sq%' OR u.email LIKE '%$sq%')";
if($action_f) $where[]="al.action='$aq'"; if($user_f) $where[]="al.user_id=$user_f"; if($date_f) $where[]="DATE(al.created_at)='$dq'";
$wh=implode(' AND ',$where);
$total=(int)$conn->query("SELECT COUNT(*) c FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id WHERE $wh")->fetch_assoc()['c'];
$al_per_page_opts=[10,25,50,100];
$al_pp_raw=(int)($_GET['per_page']??0); $limit=in_array($al_pp_raw,$al_per_page_opts)?$al_pp_raw:50;
$page=max(1,(int)($_GET['page']??1)); $pages=max(1,(int)ceil($total/$limit));
$page=min($page,$pages); $off=($page-1)*$limit;
$logs=$conn->query("SELECT al.*,u.name user_name,u.email user_email,r.role_label FROM audit_logs al LEFT JOIN users u ON u.id=al.user_id LEFT JOIN roles r ON r.id=u.role_id WHERE $wh ORDER BY al.created_at DESC LIMIT $limit OFFSET $off");
$actions_q=$conn->query("SELECT DISTINCT action FROM audit_logs ORDER BY action");
$today_cnt=(int)$conn->query("SELECT COUNT(*) c FROM audit_logs WHERE DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
$week_cnt=(int)$conn->query("SELECT COUNT(*) c FROM audit_logs WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetch_assoc()['c'];
$login_cnt=(int)$conn->query("SELECT COUNT(*) c FROM audit_logs WHERE action='LOGIN' AND DATE(created_at)=CURDATE()")->fetch_assoc()['c'];
$fail_cnt=(int)$conn->query("SELECT COUNT(*) c FROM users WHERE failed_attempts>0 AND deleted_at IS NULL")->fetch_assoc()['c'];
$uid=(int)$_SESSION['user_id']; $notif_count=(int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
$qs=http_build_query(array_filter(['q'=>$search,'action_f'=>$action_f,'user_f'=>$user_f?:null,'date_f'=>$date_f]));
$abadges=['LOGIN'=>'badge-approved','LOGOUT'=>'badge-draft','PASSWORD_RESET'=>'badge-revision_requested','PASSWORD_CHANGE'=>'badge-revision_requested','USER_CREATED'=>'badge-approved','USER_UPDATED'=>'badge-submitted','USER_DELETED'=>'badge-rejected'];
?>
<!DOCTYPE html><html lang="en"><head><title>Audit Logs — QA System</title><?php include 'head.php'; ?>
<style>
.log-badge{font-family:monospace;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:5px;white-space:nowrap;}
.ip-tag{background:#f0f4f9;border:1px solid #dde3ed;border-radius:4px;padding:2px 6px;font-family:monospace;font-size:.7rem;color:#6b7a8d;}
.pg-btn{display:inline-flex;align-items:center;min-width:34px;height:32px;padding:0 10px;border-radius:7px;border:1.5px solid var(--border);background:white;font-size:.8rem;cursor:pointer;color:var(--text);text-decoration:none;transition:all .15s;justify-content:center;}
.pg-btn:hover{background:var(--primary-xlight);border-color:var(--primary-light);}
.pg-btn.active{background:var(--primary);color:white;border-color:var(--primary);}
.pg-btn.disabled{opacity:.4;pointer-events:none;}
.scard{background:white;border:1.5px solid var(--border);border-radius:12px;padding:16px 20px;}
.scard .n{font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:700;color:var(--primary);}
.scard .l{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-top:2px;}
</style>
</head>
<body><?php include 'sidebar.php'; ?>
<div class="main-content">
  <div class="topbar">
    <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Audit Logs</div>
    <div class="topbar-right">
      <a href="?<?=$qs?>&export=1" class="btn btn-outline btn-sm">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm3.293-7.707a1 1 0 011.414 0L9 10.586V3a1 1 0 112 0v7.586l1.293-1.293a1 1 0 111.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd"/></svg> Export CSV
      </a>
      <a href="notifications.php" class="notif-btn"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg><?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?></a>
    </div>
  </div>
  <div class="page-body">
    <div class="page-header"><div><h1 class="page-heading">Audit &amp; Activity Logs</h1><p class="page-subheading"><?=number_format($total)?> records · Page <?=$page?> of <?=$pages?></p></div></div>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px;">
      <div class="scard"><div class="n"><?=$today_cnt?></div><div class="l">Actions Today</div></div>
      <div class="scard"><div class="n"><?=$week_cnt?></div><div class="l">This Week</div></div>
      <div class="scard"><div class="n"><?=$login_cnt?></div><div class="l">Logins Today</div></div>
      <div class="scard" style="border-color:<?=$fail_cnt>0?'#fca5a5':'var(--border)'?>"><div class="n" style="color:<?=$fail_cnt>0?'#dc2626':'var(--primary)'?>"><?=$fail_cnt?></div><div class="l">Accts w/ Failed Logins</div></div>
    </div>

    <div class="card" style="margin-bottom:0;border-bottom:none;border-radius:12px 12px 0 0;">
      <div class="filter-bar" style="flex-wrap:wrap;">
        <input type="text" id="qInp" class="search-input" placeholder="Search user, action, description…" value="<?=htmlspecialchars($search)?>">
        <select id="actInp"><option value="">All Actions</option><?php while($a=$actions_q->fetch_assoc()):?><option value="<?=htmlspecialchars($a['action'])?>" <?=$action_f===$a['action']?'selected':''?>><?=htmlspecialchars($a['action'])?></option><?php endwhile;?></select>
        <input type="date" id="dtInp" value="<?=htmlspecialchars($date_f)?>" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;font-family:inherit;outline:none;">
        <select id="alPerPage" style="padding:8px 28px 8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
          <?php foreach([10,25,50,100] as $opt):?>
          <option value="<?=$opt?>"<?=$limit==$opt?' selected':''?>><?=$opt?> / page</option>
          <?php endforeach;?>
        </select>
        <button class="btn btn-outline btn-sm" onclick="applyFilters()">Filter</button>
        <a href="audit_logs.php" class="btn btn-ghost btn-sm">Clear</a>
      </div>
    </div>
    <div class="card" style="margin-top:0;border-top:none;border-radius:0 0 12px 12px;">
      <div class="table-wrapper">
        <table>
          <thead><tr><th style="width:130px;">Time</th><th>User</th><th style="width:180px;">Action</th><th style="width:130px;">Entity</th><th>Description</th><th style="width:110px;">IP</th></tr></thead>
          <tbody>
          <?php if(!$logs||$logs->num_rows===0): ?>
          <tr><td colspan="6"><div class="empty-state"><p>No logs found.</p></div></td></tr>
          <?php else: while($l=$logs->fetch_assoc()): $badge=$abadges[$l['action']]??'badge-draft'; ?>
          <tr>
            <td style="white-space:nowrap;"><div style="font-size:.8rem;font-weight:500;"><?=date('M d, Y',strtotime($l['created_at']))?></div><div style="font-size:.72rem;color:var(--muted);"><?=date('H:i:s',strtotime($l['created_at']))?></div></td>
            <td><?php if($l['user_name']):?><div style="font-size:.82rem;font-weight:500;"><?=htmlspecialchars($l['user_name'])?></div><div style="font-size:.72rem;color:var(--muted);"><?=htmlspecialchars($l['user_email']??'')?></div><?php else:?><span class="text-sm text-muted">System</span><?php endif;?></td>
            <td><span class="badge <?=$badge?> log-badge"><?=htmlspecialchars($l['action'])?></span></td>
            <td style="font-size:.75rem;color:var(--muted);"><?=$l['entity_type']?htmlspecialchars($l['entity_type']).($l['entity_id']?' #'.(int)$l['entity_id']:''):'—'?></td>
            <td style="font-size:.8rem;max-width:260px;"><?=htmlspecialchars($l['description']??'—')?></td>
            <td><?=$l['ip_address']?'<span class="ip-tag">'.htmlspecialchars($l['ip_address']).'</span>':'—'?></td>
          </tr>
          <?php endwhile; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if($pages>1): ?>
      <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <span class="text-sm text-muted">Showing <?=($off+1)?>–<?=min($total,$off+$limit)?> of <?=number_format($total)?></span>
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
          <a href="?<?=$qs?>&page=<?=max(1,$page-1)?>" class="pg-btn <?=$page<=1?'disabled':''?>">← Prev</a>
          <?php $s=max(1,$page-2);$e=min($pages,$page+2);if($s>1)echo"<span class='pg-btn disabled' style='pointer-events:none'>…</span>";for($i=$s;$i<=$e;$i++):?><a href="?<?=$qs?>&page=<?=$i?>" class="pg-btn <?=$i===$page?'active':''?>"><?=$i?></a><?php endfor;if($e<$pages)echo"<span class='pg-btn disabled'>…</span>";?>
          <a href="?<?=$qs?>&page=<?=min($pages,$page+1)?>" class="pg-btn <?=$page>=$pages?'disabled':''?>">Next →</a>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
function applyFilters(){const p=new URLSearchParams();const q=document.getElementById('qInp').value.trim();const a=document.getElementById('actInp').value;const d=document.getElementById('dtInp').value;const pp=document.getElementById('alPerPage').value;if(q)p.set('q',q);if(a)p.set('action_f',a);if(d)p.set('date_f',d);p.set('per_page',pp);p.set('page','1');location.href='audit_logs.php?'+p.toString();}
document.getElementById('qInp').addEventListener('keydown',e=>{if(e.key==='Enter')applyFilters();});
</script>
</body></html>
