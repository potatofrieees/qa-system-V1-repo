<?php
session_start();
include 'database/db_connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

$uid = (int)$_SESSION['user_id'];
$r   = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0");
$cnt = $r ? (int)$r->fetch_assoc()['c'] : 0;

echo json_encode(['count' => $cnt]);
?>
