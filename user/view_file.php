<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login();
$uid    = (int)$_SESSION['user_id'];
$doc_id = (int)($_GET['id']??0);
if (!$doc_id) { http_response_code(400); echo "Invalid request."; exit; }

// Users can only view their own documents
$row = $conn->query("SELECT file_path,file_name,file_type FROM documents
                     WHERE id=$doc_id AND uploaded_by=$uid AND deleted_at IS NULL")->fetch_assoc();
if (!$row) { http_response_code(403); echo "Access denied or document not found."; exit; }

$real_upload = realpath(__DIR__.'/../uploads');
$real_file   = realpath($row['file_path']);
if (!$real_file) $real_file = realpath(__DIR__.'/../'.ltrim($row['file_path'],'/'));
if (!$real_file) $real_file = realpath(__DIR__.'/'.$row['file_path']);
if (!$real_file || !$real_upload || strpos($real_file,$real_upload)!==0) { http_response_code(403); echo "Access denied."; exit; }
if (!file_exists($real_file)) { http_response_code(404); echo "File not found on server."; exit; }

$ext_map=['pdf'=>'application/pdf','doc'=>'application/msword',
  'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'xls'=>'application/vnd.ms-excel',
  'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'ppt'=>'application/vnd.ms-powerpoint',
  'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg'];
$ext  = strtolower(pathinfo($real_file,PATHINFO_EXTENSION));
$mime = $ext_map[$ext]??'application/octet-stream';
$name = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $row['file_name']?:basename($real_file));
$disp = in_array($ext,['pdf','png','jpg','jpeg'])?'inline':'attachment';
header("Content-Type: $mime");
header("Content-Disposition: $disp; filename=\"$name\"");
header("Content-Length: ".filesize($real_file));
header("Cache-Control: private, max-age=3600");
readfile($real_file); exit;
