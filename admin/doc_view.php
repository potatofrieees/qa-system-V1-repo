<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);
$active_nav = 'documents';

$doc_id = (int)($_GET['id'] ?? 0);
if (!$doc_id) { header("Location: documents.php"); exit; }

$doc = $conn->query("
    SELECT d.*, p.program_name, dp.department_name, c.college_name, c.college_code,
           al.level_name, a.area_name,
           u.name AS uploader_name, u.email AS uploader_email,
           r2.role_label AS uploader_role
    FROM documents d
    LEFT JOIN programs p    ON p.id = d.program_id
        LEFT JOIN colleges c    ON c.id = dp.college_id
    LEFT JOIN accreditation_levels al ON al.id = d.accreditation_level_id
    LEFT JOIN areas a       ON a.id = d.area_id
    LEFT JOIN users u       ON u.id = d.uploaded_by
    LEFT JOIN roles r2      ON r2.id = u.role_id
    WHERE d.id = $doc_id AND d.deleted_at IS NULL
")->fetch_assoc();

if (!$doc) {
    header("Location: documents.php?msg=".urlencode('Document not found.')."&typ=e");
    exit;
}

// Version history
$versions = $conn->query("
    SELECT dv.*, u.name AS uploader_name
    FROM document_versions dv
    LEFT JOIN users u ON u.id = dv.uploaded_by
    WHERE dv.document_id = $doc_id
    ORDER BY dv.version_number DESC
");

// Review history
$reviews = $conn->query("
    SELECT dr.*, u.name AS reviewer_name, r.role_label
    FROM document_reviews dr
    LEFT JOIN users u ON u.id = dr.reviewer_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE dr.document_id = $doc_id
    ORDER BY dr.reviewed_at DESC
");

$uid         = (int)$_SESSION['user_id'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
$is_reviewable = in_array($doc['status'], ['submitted','under_review','revision_requested']);
$back = (($_GET['from'] ?? '') === 'queue') ? 'reviews_queue.php' : 'documents.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Document Details — QA System</title>
    <?php include 'head.php'; ?>
    <style>
    .doc-grid { display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start; }
    @media(max-width:900px){ .doc-grid { grid-template-columns:1fr; } }
    .meta-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:3px; }
    .meta-value { font-weight:600; font-size:.9rem; }
    .review-item { padding:16px 20px; border-bottom:1px solid var(--border); }
    .review-item:last-child { border-bottom:none; }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Document Details</div>
        <div class="topbar-right">
            <?php if ($is_reviewable): ?>
            <a href="review_doc.php?id=<?=$doc_id?>" class="btn btn-primary btn-sm">
                🔍 Review this Document
            </a>
            <?php endif; ?>
            <a href="notifications.php" class="notif-btn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <a href="<?=$back?>" style="font-size:.82rem;color:var(--muted);text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:14px;">
            <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
            ← Back
        </a>

        <div class="doc-grid">
            <!-- LEFT -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Document Header -->
                <div class="card">
                    <div style="padding:20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:14px;justify-content:space-between;">
                        <div style="display:flex;align-items:flex-start;gap:14px;">
                            <div style="width:48px;height:48px;background:rgba(99,102,241,.1);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;">📄</div>
                            <div>
                                <h2 style="margin:0 0 4px;font-size:1.1rem;"><?= htmlspecialchars($doc['title']) ?></h2>
                                <?php if($doc['document_code']): ?>
                                <div class="text-sm text-muted"><?= htmlspecialchars($doc['document_code']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge badge-<?= $doc['status'] ?>"><?= ucwords(str_replace('_',' ',$doc['status'])) ?></span>
                    </div>
                    <div style="padding:20px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                            <?php $fields = [
                                ['Program',  $doc['program_name']   ?? '—'],
                                                                ['College',  ($doc['college_code'] ? '['.$doc['college_code'].'] ' : '') . ($doc['college_name'] ?? '—')],
                                ['Area',     $doc['area_name']      ?? '—'],
                                ['Level',    $doc['level_name']     ?? '—'],
                                ['Academic Year', $doc['academic_year'] ?? '—'],
                                ['Semester', $doc['semester']       ?? '—'],
                                ['Deadline', $doc['deadline'] ? date('M d, Y', strtotime($doc['deadline'])) : '—'],
                            ];
                            foreach ($fields as [$l, $v]): ?>
                            <div>
                                <div class="meta-label"><?=$l?></div>
                                <div class="meta-value"><?= htmlspecialchars($v) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if($doc['description']): ?>
                        <div style="background:var(--bg);border-radius:8px;padding:14px;font-size:.88rem;">
                            <?= nl2br(htmlspecialchars($doc['description'])) ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($is_reviewable): ?>
                        <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);">
                            <a href="review_doc.php?id=<?=$doc_id?>" class="btn btn-primary">
                                🔍 Review this Document
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Version History -->
                <?php if ($versions && $versions->num_rows > 0): ?>
                <div class="card">
                    <div style="padding:14px 20px;border-bottom:1px solid var(--border);">
                        <span style="font-weight:700;font-size:.9rem;">📁 Version History</span>
                    </div>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr><th>Version</th><th>File</th><th>Size</th><th>Uploaded By</th><th>Date</th><th>Remarks</th><th></th></tr>
                            </thead>
                            <tbody>
                            <?php while($v = $versions->fetch_assoc()): ?>
                            <tr>
                                <td><span style="font-family:monospace;font-size:.8rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:2px 8px;"><?= htmlspecialchars($v['version_label'] ?? 'v'.$v['version_number']) ?></span>
                                    <?php if ($v['version_number'] == $doc['current_version']): ?>
                                    <span style="font-size:.68rem;background:#dcfce7;color:#16a34a;border-radius:3px;padding:1px 5px;margin-left:4px;">CURRENT</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-sm"><?= htmlspecialchars($v['file_name'] ?? '—') ?></td>
                                <td class="text-sm text-muted"><?= $v['file_size'] ? round($v['file_size']/1024,1).' KB' : '—' ?></td>
                                <td class="text-sm"><?= htmlspecialchars($v['uploader_name'] ?? '—') ?></td>
                                <td class="text-sm text-muted"><?= $v['created_at'] ? date('M d, Y', strtotime($v['created_at'])) : '—' ?></td>
                                <td class="text-sm"><?= htmlspecialchars(mb_substr($v['remarks'] ?? '',0,60)) ?></td>
                                <td>
                                    <?php if($v['file_path']): ?>
                                    <a href="view_file.php?id=<?=$doc_id?>&download=1" class="btn btn-ghost btn-sm">⬇ Download</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Review History -->
                <div class="card">
                    <div style="padding:14px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-weight:700;font-size:.9rem;">🔍 Review History</span>
                        <?php $rev_count = $reviews ? $reviews->num_rows : 0; ?>
                        <span class="text-sm text-muted"><?=$rev_count?> review<?=$rev_count!=1?'s':''?></span>
                    </div>
                    <?php if ($rev_count === 0): ?>
                    <div style="padding:24px;text-align:center;color:var(--muted);">
                        <div style="font-size:2rem;margin-bottom:8px;">📋</div>
                        <p class="text-sm">No reviews yet.</p>
                    </div>
                    <?php else: while ($rv = $reviews->fetch_assoc()):
                        $dc = ['approved'=>'badge-approved','revision_requested'=>'badge-revision_requested','rejected'=>'badge-rejected'];
                        $icons = ['approved'=>'✅','revision_requested'=>'🔄','rejected'=>'❌'];
                    ?>
                    <div class="review-item">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <div>
                                <span style="font-weight:700;font-size:.88rem;"><?= htmlspecialchars($rv['reviewer_name'] ?? 'Unknown') ?></span>
                                <?php if($rv['role_label']): ?>
                                <span class="text-sm text-muted" style="margin-left:6px;"><?= htmlspecialchars($rv['role_label']) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="badge badge-<?=$rv['decision']?>"><?= ($icons[$rv['decision']]??'') . ' ' . ucwords(str_replace('_',' ',$rv['decision'])) ?></span>
                        </div>
                        <div class="text-sm text-muted" style="margin-bottom:8px;">
                            <?= date('M d, Y g:i A', strtotime($rv['reviewed_at'])) ?>
                            · Round <?= $rv['review_round'] ?> · v<?= $rv['version_number_reviewed'] ?? '?' ?>
                        </div>
                        <?php if($rv['comments']): ?>
                        <div style="background:var(--bg);border-radius:8px;padding:12px;font-size:.85rem;line-height:1.6;">
                            <?= nl2br(htmlspecialchars($rv['comments'])) ?>
                        </div>
                        <?php endif; ?>
                        <?php if($rv['internal_notes']): ?>
                        <div style="margin-top:8px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:6px;padding:10px;font-size:.8rem;color:#7c3aed;">
                            🔒 <strong>Internal notes:</strong> <?= htmlspecialchars($rv['internal_notes']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; endif; ?>
                </div>

            </div>

            <!-- RIGHT sidebar -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Document Info Card -->
                <div class="card" style="padding:20px;">
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:14px;">Document Info</div>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                        <div>
                            <div class="meta-label">Uploaded By</div>
                            <div class="meta-value"><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></div>
                            <div class="text-sm text-muted"><?= htmlspecialchars($doc['uploader_role'] ?? '') ?></div>
                        </div>
                        <div>
                            <div class="meta-label">Date Created</div>
                            <div style="font-size:.88rem;"><?= $doc['created_at'] ? date('M d, Y', strtotime($doc['created_at'])) : '—' ?></div>
                        </div>
                        <?php if($doc['submitted_at']): ?>
                        <div>
                            <div class="meta-label">Submitted</div>
                            <div style="font-size:.88rem;"><?= date('M d, Y', strtotime($doc['submitted_at'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="meta-label">Current Version</div>
                            <div style="font-family:monospace;font-weight:700;">v<?= $doc['current_version'] ?></div>
                        </div>
                        <?php if($doc['approved_at']): ?>
                        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px;">
                            <div style="font-size:.72rem;color:#16a34a;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;">✅ Approved On</div>
                            <div style="font-size:.88rem;font-weight:700;color:#16a34a;"><?= date('M d, Y', strtotime($doc['approved_at'])) ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if($doc['file_name']): ?>
                        <div>
                            <div class="meta-label">File</div>
                            <div style="font-size:.83rem;"><?= htmlspecialchars($doc['file_name']) ?></div>
                            <?php if($doc['file_size']): ?>
                            <div class="text-sm text-muted"><?= round($doc['file_size']/1024,1) ?> KB</div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if($doc['file_path']): ?>
                    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:8px;">
                        <a href="view_file.php?id=<?=$doc_id?>" target="_blank" class="btn btn-outline" style="width:100%;text-align:center;">
                            👁 Open / Preview File
                        </a>
                        <a href="view_file.php?id=<?=$doc_id?>&download=1" class="btn btn-outline" style="width:100%;text-align:center;">
                            ⬇️ Download File
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions -->
                <div class="card" style="padding:16px 20px;">
                    <div style="font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);margin-bottom:12px;">Quick Actions</div>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <?php if ($is_reviewable): ?>
                        <a href="review_doc.php?id=<?=$doc_id?>" class="btn btn-primary" style="text-align:center;">🔍 Review Document</a>
                        <?php endif; ?>
                        <a href="documents.php?search=<?=urlencode($doc['document_code']??$doc['title'])?>" class="btn btn-outline" style="text-align:center;">← All Documents</a>
                        <a href="reviews_queue.php" class="btn btn-ghost" style="text-align:center;">📋 Reviews Queue</a>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
</body>
</html>
