<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);

// ── Build filters ─────────────────────────────────────────────
$college_ids = array_filter(array_map('intval', (array)($_GET['college_ids'] ?? [])));
$status_f    = $_GET['status']   ?? '';
$program_f   = (int)($_GET['program'] ?? 0);
$search      = trim($_GET['search'] ?? '');
$export_type = $_GET['type'] ?? 'csv';

// ── Helper: sanitize folder/file name ────────────────────────
function sanitize_folder($name) {
    $name = preg_replace('/[\/\\\\:*?"<>|]/', '', $name);
    $name = trim(preg_replace('/\s+/', '_', $name));
    $name = trim($name, '._');
    return $name ?: 'Unknown';
}

// ── Build WHERE ───────────────────────────────────────────────
$where = ["d.deleted_at IS NULL"];
if (in_array($export_type, ['zip', 'count'])) {
    $where[] = "d.file_path IS NOT NULL";
    $where[] = "d.file_path != ''";
}
if (!empty($college_ids)) {
    $ids_str = implode(',', $college_ids);
    $where[] = "c.id IN ($ids_str)";
}
if ($status_f)  $where[] = "d.status = '" . $conn->real_escape_string($status_f) . "'";
if ($program_f) $where[] = "d.program_id = $program_f";
if ($search)    $where[] = "(d.title LIKE '%" . $conn->real_escape_string($search) . "%' OR d.document_code LIKE '%" . $conn->real_escape_string($search) . "%')";

$sql = "
    SELECT d.id, d.title, d.file_path, d.file_name, d.file_type, d.status,
           d.academic_year, d.semester, d.document_code,
           p.program_name, p.program_code,
           c.college_name, c.college_code,
           u.name AS uploader_name,
           al.level_name,
           a.area_name
    FROM documents d
    LEFT JOIN programs p            ON p.id  = d.program_id
        LEFT JOIN colleges c            ON c.id  = P.college_id
    LEFT JOIN users u               ON u.id  = d.uploaded_by
    LEFT JOIN accreditation_levels al ON al.id = d.accreditation_level_id
    LEFT JOIN areas a               ON a.id  = d.area_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY c.college_name, p.program_name, d.title
";
$docs = $conn->query($sql);
if (!$docs) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Database error: ' . $conn->error]);
    exit;
}

// ── Count-only ────────────────────────────────────────────────
if ($export_type === 'count') {
    $count = 0;
    $colleges_found = [];
    while ($d = $docs->fetch_assoc()) {
        $count++;
        $cn = $d['college_name'] ?? 'Unknown';
        $colleges_found[$cn] = ($colleges_found[$cn] ?? 0) + 1;
    }
    header('Content-Type: application/json');
    echo json_encode(['count' => $count, 'colleges' => $colleges_found]);
    exit;
}

// ── CSV Export ────────────────────────────────────────────────
if ($export_type === 'csv') {
    $filename = 'documents_export_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['College','Program','Title','Document Code','Status','Academic Year','Semester','Area','Level','Uploaded By','File Name']);
    while ($d = $docs->fetch_assoc()) {
        fputcsv($out, [
            $d['college_name']    ?? '',
                        $d['program_name']    ?? '',
            $d['title'],
            $d['document_code']   ?? '',
            ucwords(str_replace('_', ' ', $d['status'])),
            $d['academic_year']   ?? '',
            $d['semester']        ?? '',
            $d['area_name']       ?? '',
            $d['level_name']      ?? '',
            $d['uploader_name']   ?? '',
            $d['file_name']       ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ── ZIP Export ────────────────────────────────────────────────
if ($export_type === 'zip') {
    if (!class_exists('ZipArchive')) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'ZipArchive extension not available on this server.']);
        exit;
    }

    $zip_file = tempnam(sys_get_temp_dir(), 'qa_exp_') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Cannot create ZIP file on server.']);
        exit;
    }

    $file_count = 0;
    $real_upload = realpath(__DIR__ . '/../uploads');
    $used_paths  = [];

    while ($d = $docs->fetch_assoc()) {
        if (empty($d['file_path'])) continue;

        // Resolve real file path
        $real_file = realpath($d['file_path']);
        if (!$real_file) $real_file = realpath(__DIR__ . '/../' . ltrim($d['file_path'], '/'));
        if (!$real_file) $real_file = realpath(__DIR__ . '/' . ltrim($d['file_path'], '/'));

        if (!$real_file || !file_exists($real_file)) continue;
        // Security: must be inside uploads
        if ($real_upload && strpos($real_file, $real_upload) !== 0) continue;

        // Build ZIP folder structure: College / Department / Program / file
        $college_folder = sanitize_folder($d['college_name']    ?? 'Unknown_College');
        $dept_folder    = sanitize_folder($d['college_name'] ?? 'Unknown_College');
        $prog_folder    = sanitize_folder($d['program_name']    ?? 'Unknown_Program');

        $orig_ext    = strtolower(pathinfo($real_file, PATHINFO_EXTENSION));
        $safe_title  = sanitize_folder($d['title']);
        $safe_title  = substr($safe_title, 0, 80);
        $code_prefix = !empty($d['document_code']) ? sanitize_folder($d['document_code']) . '_' : '';
        $base_name   = $code_prefix . $safe_title . '.' . $orig_ext;

        $zip_path = $college_folder . '/' . $dept_folder . '/' . $prog_folder . '/' . $base_name;

        // Deduplicate
        if (isset($used_paths[$zip_path])) {
            $dir   = dirname($zip_path);
            $fname = pathinfo($base_name, PATHINFO_FILENAME);
            $ext   = pathinfo($base_name, PATHINFO_EXTENSION);
            $n = 2;
            do { $zip_path = $dir . '/' . $fname . '_' . $n++ . '.' . $ext; }
            while (isset($used_paths[$zip_path]));
        }
        $used_paths[$zip_path] = true;

        $zip->addFile($real_file, $zip_path);
        $file_count++;
    }

    $zip->close();

    if ($file_count === 0) {
        @unlink($zip_file);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'No files found to export. Make sure documents have been uploaded to the server.']);
        exit;
    }

    // Verify ZIP integrity before sending
    $chk = new ZipArchive();
    if ($chk->open($zip_file) !== true) {
        @unlink($zip_file);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Generated ZIP is invalid. Please try again.']);
        exit;
    }
    $chk->close();

    $zip_size = filesize($zip_file);
    $dl_name  = 'QA_Documents_' . date('Ymd_His') . '.zip';

    // Clear any output buffering to prevent corrupting binary
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $dl_name . '"');
    header('Content-Length: ' . $zip_size);
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');

    readfile($zip_file);
    @unlink($zip_file);
    exit;
}

header('Content-Type: application/json');
echo json_encode(['error' => 'Invalid export type.']);
