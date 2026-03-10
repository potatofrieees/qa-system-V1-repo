<?php
session_start();
include '../database/db_connect.php';
header('Content-Type: application/json');

$uid = (int)($_SESSION['user_id'] ?? 0);
if (!$uid) { echo json_encode(['items'=>[]]); exit; }

$notifs = $conn->query("SELECT * FROM notifications WHERE user_id=$uid ORDER BY created_at DESC LIMIT 20");

$type_icons = [
    'document_submitted'     => '📄',
    'review_decision'        => '✅',
    'revision_requested'     => '🔄',
    'deadline_reminder'      => '⏰',
    'assignment'             => '👤',
    'system'                 => '⚙️',
    'general'                => '📢',
    'account_access_request' => '🔑',
];

function time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)        return 'Just now';
    if ($diff < 3600)      return floor($diff/60).'m ago';
    if ($diff < 86400)     return floor($diff/3600).'h ago';
    if ($diff < 604800)    return floor($diff/86400).'d ago';
    return date('M j', strtotime($datetime));
}

$items = [];
if ($notifs) {
    while ($n = $notifs->fetch_assoc()) {
        $items[] = [
            'id'       => (int)$n['id'],
            'title'    => htmlspecialchars($n['message'] ?? $n['title'] ?? 'Notification'),
            'icon'     => $type_icons[$n['type']] ?? '📢',
            'is_read'  => (bool)$n['is_read'],
            'time_ago' => time_ago($n['created_at']),
            'link'     => $n['link'] ?? null,
            'mark_url' => 'notifications.php',
        ];
    }
}

echo json_encode(['items' => $items]);
