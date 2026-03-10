<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);
$active_nav = 'reviews_queue';

$tab = in_array($_GET['tab'] ?? '', ['submitted','under_review','revision_requested','approved','rejected']) ? $_GET['tab'] : 'submitted';

// Status tab config
$statuses = [
    'submitted'          => 'Submitted',
    'under_review'       => 'Under Review',
    'revision_requested' => 'Revision Requested',
    'approved'           => 'Approved',
    'rejected'           => 'Rejected',
];

// Get counts for each tab
$counts = [];
foreach (array_keys($statuses) as $s) {
    $r = $conn->query("SELECT COUNT(*) c FROM documents WHERE status='$s' AND deleted_at IS NULL");
    $counts[$s] = $r ? (int)$r->fetch_assoc()['c'] : 0;
}

// College filter
$college_f = (int)($_GET['college'] ?? 0);
$search_f  = $conn->real_escape_string(trim($_GET['search'] ?? ''));

$where = ["doc.status = '$tab'", "doc.deleted_at IS NULL"];
if ($college_f) $where[] = "c.id = $college_f";
if ($search_f)  $where[] = "(doc.title LIKE '%$search_f%' OR doc.document_code LIKE '%$search_f%' OR u.name LIKE '%$search_f%')";

/* ── Pagination ─────────────────────────────────── */
$rq_per_page_opts=[10,25,50,100];
$rq_per_page_raw=(int)($_GET['per_page']??0); $rq_per_page=in_array($rq_per_page_raw,$rq_per_page_opts)?$rq_per_page_raw:25;
$rq_page=max(1,(int)($_GET['page']??1));
$rq_count_q=$conn->query("SELECT COUNT(*) c FROM documents doc LEFT JOIN programs p ON p.id=doc.program_id LEFT JOIN colleges c ON c.id=p.college_id WHERE ".implode(' AND ',$where));
$rq_total=$rq_count_q?(int)$rq_count_q->fetch_assoc()['c']:0;
$rq_pages=max(1,(int)ceil($rq_total/$rq_per_page));
$rq_page=min($rq_page,$rq_pages);
$rq_offset=($rq_page-1)*$rq_per_page;

function rq_url(array $extra=[]): string {
    global $tab,$search_f,$college_f,$rq_per_page,$rq_page;
    $p=['tab'=>$tab,'search'=>$search_f,'college'=>$college_f,'per_page'=>$rq_per_page,'page'=>$rq_page];
    foreach($extra as $k=>$v) $p[$k]=$v;
    $p=array_filter($p,function($v){return $v!==''&&$v!==0&&$v!==null;});
    return 'reviews_queue.php'.($p?'?'.http_build_query($p):'');
}

$docs = $conn->query("
    SELECT doc.id, doc.title, doc.document_code, doc.status, doc.deadline,
           doc.current_version, doc.submitted_at, doc.updated_at, doc.file_path, doc.file_name,
           p.program_name, c.college_name, c.college_code,
           al.level_name, a.area_name,
           u.name AS uploader_name, u.email AS uploader_email
    FROM documents doc
    LEFT JOIN programs p               ON p.id  = doc.program_id
    LEFT JOIN colleges c               ON c.id  = p.college_id
    LEFT JOIN accreditation_levels al  ON al.id = doc.accreditation_level_id
    LEFT JOIN areas a                  ON a.id  = doc.area_id
    LEFT JOIN users u                  ON u.id  = doc.uploaded_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY doc.submitted_at ASC
    LIMIT $rq_per_page OFFSET $rq_offset
");

$all_colleges = $conn->query("SELECT id, college_code, college_name FROM colleges WHERE status='active' ORDER BY college_name");
$uid          = (int)$_SESSION['user_id'];
$notif_count  = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
$pending_total = $counts['submitted'] + $counts['under_review'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Reviews Queue — QA System</title>
    <?php include 'head.php'; ?>
    <style>
    .queue-tabs { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:0; }
    .queue-tab  { padding:10px 18px; border-radius:8px 8px 0 0; font-size:.85rem; font-weight:500; cursor:pointer;
                  color:var(--muted); background:var(--bg); border:1.5px solid var(--border); border-bottom:none;
                  text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all .15s; }
    .queue-tab:hover:not(.active) { color:var(--primary); background:var(--primary-xlight); }
    .queue-tab.active { background:white; color:var(--primary); font-weight:600; border-color:var(--primary);
                        border-bottom:1.5px solid white; margin-bottom:-1px; z-index:1; }
    .tab-count { background:var(--primary); color:white; border-radius:20px; padding:1px 8px; font-size:.72rem; font-weight:700; }
    .tab-count.zero { background:var(--border); color:var(--muted); }
    .tab-count.urgent { background:#dc2626; }
    .queue-body { border-radius:0 12px 12px 12px; border:1.5px solid var(--primary); }
    .overdue-badge { display:inline-block; background:#fef2f2; color:#dc2626; border:1px solid #fecaca; 
                     border-radius:4px; font-size:.65rem; font-weight:700; padding:1px 6px; margin-top:2px; }
    .version-tag { font-family:monospace; font-size:.78rem; background:var(--bg); border:1px solid var(--border);
                   border-radius:4px; padding:1px 7px; color:var(--primary); }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">
            Reviews Queue
            <?php if ($pending_total > 0): ?>
            <span style="margin-left:10px;background:#dc2626;color:white;border-radius:20px;padding:2px 10px;font-size:.75rem;font-weight:700;"><?= $pending_total ?> pending</span>
            <?php endif; ?>
        </div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <div class="page-header">
            <div>
                
                <h1 class="page-heading">Reviews Queue</h1>
                <p class="page-subheading">Documents pending QA review and decision</p>
            </div>
            <!-- Search + College filter -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <input type="text" id="qSearch" class="search-input" placeholder="Search title, code, uploader…"
                       value="<?= htmlspecialchars($search_f) ?>" style="min-width:200px;"
                       onkeydown="if(event.key==='Enter') applyQFilter()">
                <select id="qCollege" style="padding:8px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:.85rem;">
                    <option value="">All Colleges</option>
                    <?php while($col=$all_colleges->fetch_assoc()): ?>
                    <option value="<?=$col['id']?>" <?=$college_f==$col['id']?'selected':''?>>
                        [<?=htmlspecialchars($col['college_code'])?>] <?=htmlspecialchars($col['college_name'])?>
                    </option>
                    <?php endwhile; ?>
                </select>
                <select id="rqPerPage" style="padding:8px 28px 8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:.82rem;">
                  <?php foreach([10,25,50,100] as $opt):?>
                  <option value="<?=$opt?>"<?=$rq_per_page==$opt?' selected':''?>><?=$opt?> / page</option>
                  <?php endforeach;?>
                </select>
                <button class="btn btn-outline btn-sm" onclick="applyQFilter()">Filter</button>
                <?php if ($search_f || $college_f): ?>
                <a href="reviews_queue.php?tab=<?=$tab?>" class="btn btn-ghost btn-sm">✕ Clear</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabs -->
        <div class="queue-tabs">
            <?php foreach ($statuses as $s => $label):
                $cnt = $counts[$s];
                $isActive = $tab === $s;
                $urgent   = in_array($s, ['submitted','under_review']) && $cnt > 0;
            ?>
            <a href="reviews_queue.php?tab=<?=$s?>&college=<?=$college_f?>&search=<?=urlencode($search_f)?>"
               class="queue-tab <?=$isActive?'active':''?>">
                <?= $label ?>
                <span class="tab-count <?= $cnt===0?'zero':'' ?> <?= $urgent?'urgent':'' ?>"><?=$cnt?></span>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="card queue-body" style="margin-top:0;">
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Program / College</th>
                            <th>Area</th>
                            <th>Level</th>
                            <th>Version</th>
                            <th>Submitted</th>
                            <th>Deadline</th>
                            <th>Submitted By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$docs || $docs->num_rows === 0): ?>
                    <tr><td colspan="9">
                        <div class="empty-state">
                            <div style="font-size:3rem;margin-bottom:12px;">🎉</div>
                            <p style="font-weight:600;margin-bottom:4px;">Queue is clear</p>
                            <p class="text-sm text-muted">No documents with status: <?= ucwords(str_replace('_',' ',$tab)) ?></p>
                        </div>
                    </td></tr>
                    <?php else: while ($doc = $docs->fetch_assoc()):
                        $isOverdue = $doc['deadline'] && strtotime($doc['deadline']) < time()
                                     && !in_array($tab, ['approved','rejected']);
                    ?>
                    <tr>
                        <td style="max-width:240px;">
                            <div style="font-weight:600;font-size:.88rem;line-height:1.3;">
                                <a href="doc_view.php?id=<?=$doc['id']?>" style="color:var(--primary);text-decoration:none;">
                                    <?= htmlspecialchars(mb_substr($doc['title'],0,60)) ?>
                                </a>
                            </div>
                            <?php if($doc['document_code']): ?>
                            <div class="text-sm text-muted"><?= htmlspecialchars($doc['document_code']) ?></div>
                            <?php endif; ?>
                            <?php if($isOverdue): ?>
                            <span class="overdue-badge">OVERDUE</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm">
                            <div style="font-weight:500;"><?= htmlspecialchars($doc['program_name'] ?? '—') ?></div>
                            <div class="text-muted" style="font-size:.75rem;"><?= htmlspecialchars($doc['college_name'] ?? '') ?>
                                <?php if($doc['college_code']): ?>
                                · <span style="font-weight:600;"><?= htmlspecialchars($doc['college_code']) ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="text-sm"><?= htmlspecialchars($doc['area_name'] ?? '—') ?></td>
                        <td class="text-sm"><?= htmlspecialchars($doc['level_name'] ?? '—') ?></td>
                        <td><span class="version-tag">v<?= $doc['current_version'] ?></span></td>
                        <td class="text-sm text-muted">
                            <?= $doc['submitted_at'] ? date('M d, Y', strtotime($doc['submitted_at'])) : '—' ?>
                        </td>
                        <td>
                            <?php if($doc['deadline']): ?>
                            <span style="font-size:.82rem;font-weight:600;color:<?=$isOverdue?'#dc2626':'var(--muted)'?>;">
                                <?= date('M d, Y', strtotime($doc['deadline'])) ?>
                            </span>
                            <?php else: ?><span class="text-muted text-sm">—</span><?php endif; ?>
                        </td>
                        <td class="text-sm"><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <a href="doc_view.php?id=<?=$doc['id']?>" class="btn btn-ghost btn-sm">
                                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                                    View
                                </a>
                                <?php if(in_array($tab, ['submitted','under_review','revision_requested'])): ?>
                                <a href="review_doc.php?id=<?=$doc['id']?>" class="btn btn-primary btn-sm">
                                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                    Review
                                </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function applyQFilter() {
    const s   = encodeURIComponent(document.getElementById('qSearch').value);
    const col = encodeURIComponent(document.getElementById('qCollege').value);
    const pp  = document.getElementById('rqPerPage').value;
    window.location.href = 'reviews_queue.php?tab=<?=$tab?>&search='+s+'&college='+col+'&per_page='+pp+'&page=1';
}

// Live search with highlight
const qInput = document.getElementById('qSearch');
let qDebounce = null;
const qRows = document.querySelectorAll('tbody tr');
const qOriginals = new Map();
qRows.forEach(row => { qOriginals.set(row, row.innerHTML); });

function highlightNode(node, term) {
    if (node.nodeType === Node.TEXT_NODE) {
        const idx = node.textContent.toLowerCase().indexOf(term.toLowerCase());
        if (idx === -1) return;
        const before = document.createTextNode(node.textContent.slice(0, idx));
        const mark = document.createElement('mark');
        mark.style.cssText = 'background:#fef08a;border-radius:2px;padding:0 2px;font-weight:600;';
        mark.textContent = node.textContent.slice(idx, idx + term.length);
        const after = document.createTextNode(node.textContent.slice(idx + term.length));
        node.parentNode.insertBefore(before, node);
        node.parentNode.insertBefore(mark, node);
        node.parentNode.insertBefore(after, node);
        node.parentNode.removeChild(node);
        highlightNode(after, term);
    } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName !== 'SCRIPT') {
        Array.from(node.childNodes).forEach(c => highlightNode(c, term));
    }
}

function qLiveSearch() {
    const term = qInput.value.trim();
    const termLow = term.toLowerCase();
    qRows.forEach(row => {
        if (qOriginals.has(row)) row.innerHTML = qOriginals.get(row);
        if (!term) { row.style.display = ''; return; }
        if (row.textContent.toLowerCase().includes(termLow)) {
            row.style.display = '';
            highlightNode(row, term);
        } else {
            row.style.display = 'none';
        }
    });
}
qInput.addEventListener('input', () => { clearTimeout(qDebounce); qDebounce = setTimeout(qLiveSearch, 180); });
qInput.addEventListener('keydown', e => { if (e.key === 'Enter') applyQFilter(); });
document.getElementById('qCollege').addEventListener('change', applyQFilter);
if (qInput.value.trim()) qLiveSearch();
</script>
</body>
</html>