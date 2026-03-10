<?php
session_start();
include '../database/db_connect.php';
include '../auth.php';
require_login(['qa_director', 'qa_staff']);

$doc_id = (int)($_GET['id'] ?? 0);
if (!$doc_id) { http_response_code(400); echo "Invalid request."; exit; }

$row = $conn->query("SELECT file_path, file_name, file_type, title FROM documents WHERE id=$doc_id AND deleted_at IS NULL")->fetch_assoc();
if (!$row) { http_response_code(404); echo "Document not found."; exit; }

if (empty($row['file_path'])) { http_response_code(404); echo "No file attached to this document."; exit; }

$file_path  = $row['file_path'];
$real_upload = realpath(__DIR__ . '/../uploads');

// Try multiple path resolutions
$real_file = realpath($file_path);
if (!$real_file) $real_file = realpath(__DIR__ . '/../' . ltrim($file_path, '/'));
if (!$real_file) $real_file = realpath(__DIR__ . '/../uploads/' . basename($file_path));

// Security: must be inside uploads directory
if (!$real_file || !$real_upload || strpos($real_file, $real_upload) !== 0) {
    http_response_code(403);
    echo "Access denied: file path is not within the uploads directory.";
    exit;
}

if (!file_exists($real_file)) {
    http_response_code(404);
    echo "File not found on server. It may have been moved or deleted.";
    exit;
}

$force_download = isset($_GET['download']);
$ext_map = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'svg'  => 'image/svg+xml',
    'txt'  => 'text/plain',
];
$ext         = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
$mime        = $ext_map[$ext] ?? 'application/octet-stream';
$safe_name   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $row['file_name'] ?: basename($real_file));

$inline_types = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'];
$disposition  = (!$force_download && in_array($ext, $inline_types)) ? 'inline' : 'attachment';

// Clear any output buffers to prevent file corruption
while (ob_get_level()) ob_end_clean();

header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . $safe_name . '"');
header('Content-Length: ' . filesize($real_file));
header('Cache-Control: private, max-age=3600');
header('Accept-Ranges: bytes');
readfile($real_file);
exit;
