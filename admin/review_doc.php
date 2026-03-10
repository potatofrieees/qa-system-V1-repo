<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
require_once '../mail/emails.php';
//require_login(['qa_director', 'qa_staff']);
$active_nav = 'reviews_queue';

$doc_id = (int)($_GET['id'] ?? 0);
if (!$doc_id) { header("Location: reviews_queue.php"); exit; }

// Fetch document
$doc = $conn->query("
    SELECT d.*, p.program_name, c.college_name,
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

if (!$doc) { header("Location: reviews_queue.php?msg=".urlencode('Document not found.')."&typ=e"); exit; }

// Only allow review if in a reviewable status
$reviewable = in_array($doc['status'], ['submitted','under_review','revision_requested']);

// Auto-set to under_review when opened
if ($doc['status'] === 'submitted') {
    $conn->query("UPDATE documents SET status='under_review', updated_at=NOW() WHERE id=$doc_id");
    $doc['status'] = 'under_review';
    // Audit log
    $uid = (int)$_SESSION['user_id'];
    $ip  = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
    $conn->query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address)
                  VALUES ($uid, 'DOC_UNDERREVIEW', 'documents', $doc_id, 'Opened for review: {$doc['title']}', '$ip')");
}

// Fetch version history
$versions = $conn->query("
    SELECT dv.*, u.name AS uploaded_by_name
    FROM document_versions dv
    LEFT JOIN users u ON u.id = dv.uploaded_by
    WHERE dv.document_id = $doc_id
    ORDER BY dv.version_number DESC
");

// Fetch previous reviews
$prevReviews = $conn->query("
    SELECT dr.*, u.name AS reviewer_name, r.role_label
    FROM document_reviews dr
    LEFT JOIN users u ON u.id = dr.reviewer_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE dr.document_id = $doc_id
    ORDER BY dr.reviewed_at DESC
");

$errors  = [];
$success = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $reviewable) {
    $decision      = $_POST['decision'] ?? '';
    $comments      = trim($_POST['comments'] ?? '');
    $internalNotes = trim($_POST['internal_notes'] ?? '');
    $rev_uid       = (int)$_SESSION['user_id'];
    $ip            = $conn->real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');

    if (!in_array($decision, ['approved','revision_requested','rejected'])) {
        $errors[] = 'Please select a review decision.';
    }
    if (empty($comments)) {
        $errors[] = 'Review comments are required.';
    }

    if (empty($errors)) {
        // Get next review round
        $roundR = $conn->query("SELECT COALESCE(MAX(review_round),0)+1 nr FROM document_reviews WHERE document_id=$doc_id");
        $round  = (int)$roundR->fetch_assoc()['nr'];
        $version= (int)$doc['current_version'];

        $esc_comments = $conn->real_escape_string($comments);
        $esc_notes    = $conn->real_escape_string($internalNotes);
        $esc_decision = $conn->real_escape_string($decision);

        // Insert review record
        $conn->query("INSERT INTO document_reviews
            (document_id, reviewer_id, version_number_reviewed, review_round, decision, comments, internal_notes)
            VALUES ($doc_id, $rev_uid, $version, $round, '$esc_decision', '$esc_comments', " .
            ($internalNotes ? "'$esc_notes'" : 'NULL') . ")");

        // Update document status
        $new_status = $decision === 'approved' ? 'approved' : ($decision === 'rejected' ? 'rejected' : 'revision_requested');
        $extra_sql  = $decision === 'approved' ? ', approved_at=NOW()' : '';
        $conn->query("UPDATE documents SET status='$new_status' $extra_sql, updated_at=NOW() WHERE id=$doc_id");

        // Notify uploader
        $notif_titles = [
            'approved'           => '✅ Document Approved',
            'rejected'           => '❌ Document Rejected',
            'revision_requested' => '🔄 Revision Requested',
        ];
        $notif_msg = $conn->real_escape_string("Your document \"{$doc['title']}\" has been reviewed: " . ucwords(str_replace('_',' ',$decision)));
        $notif_title = $conn->real_escape_string($notif_titles[$decision]);
        $uploader_id = (int)$doc['uploaded_by'];
        $notif_type  = $decision === 'revision_requested' ? 'revision_requested' : 'review_decision';
        $notif_priority = $decision === 'revision_requested' ? 'high' : 'normal';
        if ($uploader_id) {
            $conn->query("INSERT INTO notifications (user_id, type, title, message, link, priority)
                VALUES ($uploader_id, '$notif_type', '$notif_title', '$notif_msg', 'my_documents.php?highlight=$doc_id', '$notif_priority')");
            // Send email notification to uploader
            $uploader_row = $conn->query("SELECT email, name FROM users WHERE id=$uploader_id")->fetch_assoc();
            if ($uploader_row) {
                mail_review_decision($uploader_row['email'], $uploader_row['name'], $doc['title'], $decision, $comments);
            }
        }

        // Audit
        $conn->query("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address)
            VALUES ($rev_uid, 'DOC_REVIEW', 'documents', $doc_id,
            'Review decision: $new_status for doc #$doc_id', '$ip')");

        header("Location: reviews_queue.php?tab=" . ($new_status === 'approved' ? 'approved' : ($new_status === 'rejected' ? 'rejected' : 'revision_requested')) . "&msg=" . urlencode('Review submitted successfully.') . "&typ=s");
        exit;
    }
}

$uid         = (int)$_SESSION['user_id'];
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Review Document — QA System</title>
    <?php include 'head.php'; ?>
    <style>
    .review-grid { display:grid; grid-template-columns:1fr 360px; gap:20px; align-items:start; }
    @media(max-width:900px){ .review-grid { grid-template-columns:1fr; } }
    .doc-field { margin-bottom:14px; }
    .doc-field-label { font-size:.72rem; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:3px; }
    .doc-field-val   { font-weight:600; font-size:.9rem; }
    .decision-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
    .decision-card { padding:16px 12px; border-radius:10px; text-align:center; border:2px solid var(--border);
                     background:var(--bg); cursor:pointer; transition:all .2s; user-select:none; }
    .decision-card:hover { border-color:var(--primary-light); background:var(--primary-xlight); }
    .decision-card.selected-approve  { border-color:#10b981; background:#f0fdf4; }
    .decision-card.selected-revision { border-color:#f59e0b; background:#fffbeb; }
    .decision-card.selected-reject   { border-color:#ef4444; background:#fef2f2; }
    .decision-emoji { font-size:1.8rem; margin-bottom:6px; }
    .decision-label { font-size:.88rem; font-weight:700; }
    .decision-hint  { font-size:.72rem; color:var(--muted); margin-top:4px; line-height:1.3; }
    .review-hist-item { padding:14px 16px; border-bottom:1px solid var(--border); }
    .review-hist-item:last-child { border-bottom:none; }
    .version-row td { font-size:.82rem; padding:8px 12px; }
    .back-link { font-size:.82rem; color:var(--muted); text-decoration:none; display:inline-flex; align-items:center; gap:4px; margin-bottom:12px; }
    .back-link:hover { color:var(--primary); }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
            </button>
            <div class="topbar-title">Review Document</div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if($notif_count>0):?><span class="notif-badge"><?=$notif_count?></span><?php endif;?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <a href="reviews_queue.php" class="back-link">
            <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
            ← Back to Reviews Queue
        </a>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error" style="margin-bottom:16px;">
            <?php foreach($errors as $e): ?><div>• <?= htmlspecialchars($e) ?></div><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="margin-bottom:20px;">
            <h1 class="page-heading" style="margin-bottom:4px;"><?= htmlspecialchars(mb_substr($doc['title'],0,80)) ?></h1>
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="badge badge-<?= $doc['status'] ?>"><?= ucwords(str_replace('_',' ',$doc['status'])) ?></span>
                <?php if($doc['document_code']): ?>
                <span class="text-sm text-muted"><?= htmlspecialchars($doc['document_code']) ?></span>
                <?php endif; ?>
                <span style="font-family:monospace;font-size:.82rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:1px 8px;">v<?= $doc['current_version'] ?></span>
            </div>
        </div>

        <div class="review-grid">

            <!-- LEFT: Document info + Review Form -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Document Summary -->
                <div class="card">
                    <div class="modal-header" style="padding:16px 20px;border-bottom:1px solid var(--border);">
                        <span style="font-weight:700;font-size:.9rem;">📋 Document Summary</span>
                        <?php if($doc['file_path']): ?>
                        <a href="view_file.php?id=<?=$doc_id?>" target="_blank" class="btn btn-outline btn-sm">
                            <svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;"><path d="M10 12a2 2 0 100-4 2 2 0 000 4z"/><path fill-rule="evenodd" d="M.458 10C1.732 5.943 5.522 3 10 3s8.268 2.943 9.542 7c-1.274 4.057-5.064 7-9.542 7S1.732 14.057.458 10zM14 10a4 4 0 11-8 0 4 4 0 018 0z" clip-rule="evenodd"/></svg>
                            Open File
                        </a>
                        <a href="view_file.php?id=<?=$doc_id?>&download=1" class="btn btn-outline btn-sm">⬇ Download</a>
                        <?php endif; ?>
                    </div>
                    <div style="padding:16px 20px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                            <?php $fields = [
                                ['Program',  $doc['program_name'] ?? '—'],
                                
                                ['Area',     $doc['area_name'] ?? '—'],
                                ['Level',    $doc['level_name'] ?? '—'],
                                ['Academic Year', $doc['academic_year'] ?? '—'],
                                ['Semester', $doc['semester'] ?? '—'],
                                ['Deadline', $doc['deadline'] ? date('M d, Y', strtotime($doc['deadline'])) : '—'],
                                ['College',  $doc['college_name'] ?? '—'],
                            ];
                            foreach ($fields as [$l, $v]): ?>
                            <div class="doc-field">
                                <div class="doc-field-label"><?= $l ?></div>
                                <div class="doc-field-val"><?= htmlspecialchars($v) ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if($doc['description']): ?>
                        <div style="margin-top:14px;padding:12px;background:var(--bg);border-radius:8px;font-size:.88rem;color:var(--text);">
                            <?= nl2br(htmlspecialchars($doc['description'])) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($reviewable): ?>
                <!-- Review Decision Form -->
                <div class="card">
                    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
                        <span style="font-weight:700;font-size:.9rem;">⚖️ Review Decision</span>
                    </div>
                    <form method="POST">
                        <div style="padding:20px;">
                            <!-- Decision Cards -->
                            <div style="margin-bottom:20px;">
                                <label style="font-weight:600;font-size:.85rem;display:block;margin-bottom:10px;">
                                    Decision <span style="color:#dc2626;">*</span>
                                </label>
                                <div class="decision-grid">
                                    <label for="dec_approve" style="cursor:pointer;">
                                        <input type="radio" name="decision" id="dec_approve" value="approved"
                                               class="decision-radio" style="display:none;"
                                               <?= ($_POST['decision']??'')=='approved'?'checked':'' ?>>
                                        <div class="decision-card" data-sel="selected-approve">
                                            <div class="decision-emoji">✅</div>
                                            <div class="decision-label">Approve</div>
                                            <div class="decision-hint">Meets all requirements</div>
                                        </div>
                                    </label>
                                    <label for="dec_revise" style="cursor:pointer;">
                                        <input type="radio" name="decision" id="dec_revise" value="revision_requested"
                                               class="decision-radio" style="display:none;"
                                               <?= ($_POST['decision']??'')=='revision_requested'?'checked':'' ?>>
                                        <div class="decision-card" data-sel="selected-revision">
                                            <div class="decision-emoji">🔄</div>
                                            <div class="decision-label">Request Revision</div>
                                            <div class="decision-hint">Changes needed before approval</div>
                                        </div>
                                    </label>
                                    <label for="dec_reject" style="cursor:pointer;">
                                        <input type="radio" name="decision" id="dec_reject" value="rejected"
                                               class="decision-radio" style="display:none;"
                                               <?= ($_POST['decision']??'')=='rejected'?'checked':'' ?>>
                                        <div class="decision-card" data-sel="selected-reject">
                                            <div class="decision-emoji">❌</div>
                                            <div class="decision-label">Reject</div>
                                            <div class="decision-hint">Does not meet requirements</div>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Review Comments -->
                            <div class="field" style="margin-bottom:16px;">
                                <label>Review Comments <span style="color:#dc2626;">*</span></label>
                                <textarea name="comments" rows="5" required
                                    placeholder="Provide detailed feedback about the document quality, completeness, and any issues found…"
                                    style="resize:vertical;"><?= htmlspecialchars($_POST['comments'] ?? '') ?></textarea>
                            </div>

                            <!-- Internal Notes (QA only) -->
                            <div class="field" style="margin-bottom:20px;">
                                <label>
                                    Internal Notes
                                    <span style="font-weight:400;font-size:.78rem;color:var(--muted);margin-left:6px;">(QA staff only — not visible to uploader)</span>
                                </label>
                                <textarea name="internal_notes" rows="3"
                                    placeholder="Private notes for QA team reference…"
                                    style="resize:vertical;"><?= htmlspecialchars($_POST['internal_notes'] ?? '') ?></textarea>
                            </div>

                            <div style="display:flex;gap:10px;">
                                <button type="button" class="btn btn-primary" style="padding:10px 28px;font-size:.9rem;" onclick="submitReviewWithSwal(this)">
                                    ✓ Submit Review
                                </button>
                                <a href="reviews_queue.php" class="btn btn-outline">Cancel</a>
                            </div>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="card" style="padding:20px;text-align:center;color:var(--muted);">
                    <p style="font-size:1.5rem;margin-bottom:8px;">
                        <?= $doc['status'] === 'approved' ? '✅' : ($doc['status'] === 'rejected' ? '❌' : '📋') ?>
                    </p>
                    <p>This document has been <strong><?= ucwords(str_replace('_',' ',$doc['status'])) ?></strong> and is no longer in the review queue.</p>
                    <a href="doc_view.php?id=<?=$doc_id?>" class="btn btn-outline" style="margin-top:12px;">View Full Document</a>
                </div>
                <?php endif; ?>

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
                            <tbody class="version-row">
                            <?php while($v = $versions->fetch_assoc()): ?>
                            <tr>
                                <td><span style="font-family:monospace;font-size:.8rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:2px 8px;"><?= htmlspecialchars($v['version_label'] ?? 'v'.$v['version_number']) ?></span></td>
                                <td style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($v['file_name'] ?? '—') ?></td>
                                <td><?= $v['file_size'] ? round($v['file_size']/1024/1024,2).'MB' : '—' ?></td>
                                <td><?= htmlspecialchars($v['uploaded_by_name'] ?? '—') ?></td>
                                <td><?= $v['created_at'] ? date('M d, Y', strtotime($v['created_at'])) : '—' ?></td>
                                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($v['remarks'] ?? '') ?></td>
                                <td>
                                    <?php if($v['file_path']): ?>
                                    <a href="view_file.php?id=<?=$doc_id?>&version=<?=$v['id']?>&download=1" class="btn btn-ghost btn-sm">⬇</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT: Uploader info + Review history -->
            <div style="display:flex;flex-direction:column;gap:16px;">

                <!-- Uploader Info -->
                <div class="card" style="padding:20px;">
                    <div style="font-weight:700;font-size:.88rem;margin-bottom:14px;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;">👤 Submitted By</div>
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:44px;height:44px;background:var(--primary);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.1rem;flex-shrink:0;">
                            <?= strtoupper(substr($doc['uploader_name'] ?? 'U', 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:.92rem;"><?= htmlspecialchars($doc['uploader_name'] ?? '—') ?></div>
                            <div class="text-sm text-muted"><?= htmlspecialchars($doc['uploader_role'] ?? '') ?></div>
                            <div class="text-sm text-muted"><?= htmlspecialchars($doc['uploader_email'] ?? '') ?></div>
                        </div>
                    </div>
                    <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <span class="text-sm text-muted">Submitted</span>
                            <span class="text-sm"><?= $doc['submitted_at'] ? date('M d, Y', strtotime($doc['submitted_at'])) : '—' ?></span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span class="text-sm text-muted">Current Version</span>
                            <span style="font-family:monospace;font-size:.82rem;font-weight:700;">v<?= $doc['current_version'] ?></span>
                        </div>
                    </div>
                </div>

                <!-- Previous Reviews -->
                <?php $prevCount = $prevReviews ? $prevReviews->num_rows : 0; ?>
                <div class="card">
                    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                        <span style="font-weight:700;font-size:.88rem;">📝 Review History</span>
                        <span style="font-size:.78rem;color:var(--muted);"><?=$prevCount?> review<?=$prevCount!=1?'s':''?></span>
                    </div>
                    <?php if ($prevCount === 0): ?>
                    <div style="padding:20px;text-align:center;color:var(--muted);font-size:.85rem;">No previous reviews</div>
                    <?php else: ?>
                    <?php while ($r = $prevReviews->fetch_assoc()):
                        $dc = ['approved'=>['#10b981','✅'], 'revision_requested'=>['#f59e0b','🔄'], 'rejected'=>['#ef4444','❌']];
                        [$dcolor, $dicon] = $dc[$r['decision']] ?? ['#94a3b8','📋'];
                    ?>
                    <div class="review-hist-item">
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
                            <div>
                                <span style="font-weight:700;font-size:.85rem;"><?= htmlspecialchars($r['reviewer_name'] ?? 'Unknown') ?></span>
                                <?php if($r['role_label']): ?>
                                <span class="text-sm text-muted" style="margin-left:6px;"><?= htmlspecialchars($r['role_label']) ?></span>
                                <?php endif; ?>
                            </div>
                            <span class="badge badge-<?= $r['decision'] ?>"><?=$dicon?> <?= ucwords(str_replace('_',' ',$r['decision'])) ?></span>
                        </div>
                        <div style="font-size:.75rem;color:var(--muted);margin-bottom:6px;">
                            <?= date('M d, Y g:i A', strtotime($r['reviewed_at'])) ?>
                            · Round <?= $r['review_round'] ?>
                        </div>
                        <?php if($r['comments']): ?>
                        <div style="background:var(--bg);border-radius:6px;padding:10px;font-size:.82rem;line-height:1.5;">
                            <?= nl2br(htmlspecialchars(mb_substr($r['comments'],0,200))) ?>
                            <?= strlen($r['comments'])>200 ? '…' : '' ?>
                        </div>
                        <?php endif; ?>
                        <?php if($r['internal_notes'] && ($_SESSION['role'] === 'qa_director' || $_SESSION['user_id'] == $r['reviewer_id'])): ?>
                        <div style="margin-top:6px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:6px;padding:8px;font-size:.78rem;color:#7c3aed;">
                            🔒 <strong>Internal:</strong> <?= htmlspecialchars(mb_substr($r['internal_notes'],0,150)) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endwhile; ?>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
// Decision card visual highlighting
function highlightDecisions() {
    document.querySelectorAll('.decision-card').forEach(c => {
        c.classList.remove('selected-approve','selected-revision','selected-reject');
    });
    const checked = document.querySelector('.decision-radio:checked');
    if (checked) {
        const card = checked.closest('label').querySelector('.decision-card');
        const sel  = card.dataset.sel;
        if (sel) card.classList.add(sel);
    }
}
document.querySelectorAll('.decision-radio').forEach(r => {
    r.addEventListener('change', highlightDecisions);
});
document.querySelectorAll('.decision-card').forEach(card => {
    card.addEventListener('click', function() {
        const input = this.closest('label').querySelector('input');
        if (input) { input.checked = true; highlightDecisions(); }
    });
});
highlightDecisions();

// SweetAlert2 review submission
function submitReviewWithSwal(btn) {
    var form     = btn.closest('form');
    var decision = form.querySelector('.decision-radio:checked');
    var comments = form.querySelector('textarea[name="comments"]');

    if (!decision) {
        Swal.fire({ icon:'warning', title:'No Decision Selected',
            html:'Please select a review decision (Approve, Revision, or Reject) before submitting.',
            confirmButtonText:'OK',
            customClass:{ popup:'qa-popup', confirmButton:'qa-btn qa-btn-purple' },
            buttonsStyling:false });
        return;
    }
    if (!comments || !comments.value.trim()) {
        Swal.fire({ icon:'warning', title:'Comments Required',
            html:'Please provide review comments before submitting.',
            confirmButtonText:'OK',
            customClass:{ popup:'qa-popup', confirmButton:'qa-btn qa-btn-purple' },
            buttonsStyling:false });
        return;
    }

    var decisionMap = {
        approved:           { title:'Approve Document?',    html:'The document will be marked as <strong>Approved</strong> and the uploader will be notified.',          icon:'success', confirmText:'Yes, Approve',          confirmClass:'qa-btn qa-btn-green' },
        revision_requested: { title:'Request Revision?',   html:'The document will be sent back for <strong>Revision</strong> and the uploader will be notified.',      icon:'warning', confirmText:'Yes, Request Revision', confirmClass:'qa-btn qa-btn-purple' },
        rejected:           { title:'Reject Document?',    html:'The document will be marked as <strong>Rejected</strong> and the uploader will be notified.',           icon:'error',   confirmText:'Yes, Reject',           confirmClass:'qa-btn qa-btn-red'  },
    };
    var dl = decisionMap[decision.value] || { title:'Submit Review?', html:'Are you sure you want to submit this review?', icon:'question', confirmText:'Submit Review', confirmClass:'qa-btn qa-btn-purple' };

    Swal.fire({
        title:             dl.title,
        html:              dl.html,
        icon:              dl.icon,
        showCancelButton:  true,
        confirmButtonText: dl.confirmText,
        cancelButtonText:  'Go Back',
        reverseButtons:    true,
        focusCancel:       true,
        customClass:{ popup:'qa-popup', confirmButton: dl.confirmClass, cancelButton:'qa-btn qa-btn-gray' },
        buttonsStyling:false,
    }).then(function(r) {
        if (r.isConfirmed) {
            btn.disabled = true;
            btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:6px;">'
                + '<svg style="width:14px;height:14px;animation:qa-spin 1s linear infinite" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10" opacity=".2"/><path d="M12 2a10 10 0 0 1 10 10" stroke-linecap="round"/></svg>'
                + 'Submitting\u2026</span>';
            HTMLFormElement.prototype.submit.call(form);
        }
    });
}
</script>
</body>
</html>
