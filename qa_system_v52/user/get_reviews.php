<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login();

$doc_id = (int)($_GET['doc_id'] ?? 0);
$uid = (int)$_SESSION['user_id'];

// Security: only owner or admin can view
$check = $conn->query("SELECT id FROM documents WHERE id=$doc_id AND uploaded_by=$uid");
if (!$check || $check->num_rows === 0) {
    echo '<p class="text-muted text-sm">Access denied.</p>'; exit;
}

$reviews = $conn->query("
    SELECT dr.*, u.name AS reviewer_name, r.role_label
    FROM document_reviews dr
    LEFT JOIN users u ON u.id = dr.reviewer_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE dr.document_id = $doc_id
    ORDER BY dr.review_round, dr.reviewed_at DESC
");

if ($reviews->num_rows === 0) {
    echo '<p class="text-sm text-muted">No reviews yet.</p>';
    exit;
}

$decision_colors = ['approved'=>'var(--status-approved)','revision_requested'=>'var(--status-revision)','rejected'=>'var(--status-rejected)'];
$decision_labels = ['approved'=>'Approved','revision_requested'=>'Revision Requested','rejected'=>'Rejected'];

while ($rv = $reviews->fetch_assoc()):
    $dc = $decision_colors[$rv['decision']] ?? '#888';
    $dl = $decision_labels[$rv['decision']] ?? $rv['decision'];
?>
<div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:12px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
        <div>
            <span style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($rv['reviewer_name'] ?? 'Unknown') ?></span>
            <span class="text-sm text-muted" style="margin-left:8px;"><?= htmlspecialchars($rv['role_label'] ?? '') ?></span>
        </div>
        <span style="font-size:.75rem;padding:3px 10px;border-radius:20px;background:<?= $dc ?>22;color:<?= $dc ?>;font-weight:600;"><?= $dl ?></span>
    </div>
    <div style="font-size:.75rem;color:var(--muted);margin-bottom:8px;">
        Round <?= $rv['review_round'] ?> — <?= date('M d, Y H:i', strtotime($rv['reviewed_at'])) ?>
    </div>
    <?php if ($rv['comments']): ?>
    <p style="font-size:.85rem;color:var(--text);background:var(--bg);border-radius:6px;padding:10px;margin:0;">
        <?= nl2br(htmlspecialchars($rv['comments'])) ?>
    </p>
    <?php endif; ?>
</div>
<?php endwhile; ?>
