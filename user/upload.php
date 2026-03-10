<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login();
require_once '../mail/emails.php';

$active_nav = 'upload';
$uid        = (int)$_SESSION['user_id'];
$message = $msg_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $program_id  = (int)($_POST['program_id']             ?? 0);
    $area_id     = (int)($_POST['area_id']                ?? 0);
    $level_id    = (int)($_POST['accreditation_level_id'] ?? 0);
    $acad_year   = trim($_POST['academic_year'] ?? '');
    $semester    = in_array($_POST['semester'] ?? '', ['1st','2nd','Summer']) ? $_POST['semester'] : '1st';
    $deadline    = null;

    if (empty($title)) {
        $message = "Document title is required."; $msg_type = 'error';
    } else {
        $allowed_types = ['pdf','doc','docx','xls','xlsx','ppt','pptx','png','jpg','jpeg'];
        $max_size      = 20 * 1024 * 1024;
        $upload_dir    = '../uploads/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $title_e   = $conn->real_escape_string($title);
        $desc_e    = $conn->real_escape_string($description);
        $acad_e    = $conn->real_escape_string($acad_year);
        $sem_e     = $conn->real_escape_string($semester);
        $prog_sql  = $program_id ? $program_id : 'NULL';
        $area_sql  = $area_id    ? $area_id    : 'NULL';
        $level_sql = $level_id   ? $level_id   : 'NULL';
        $dead_sql  = $deadline ? "'".$conn->real_escape_string($deadline)."'" : 'NULL';

        $files = [];
        if (!empty($_FILES['document_files']['name'])) {
            $raw   = $_FILES['document_files'];
            $count = is_array($raw['name']) ? count($raw['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $files[] = is_array($raw['name']) ? [
                    'name'     => $raw['name'][$i],
                    'tmp_name' => $raw['tmp_name'][$i],
                    'type'     => $raw['type'][$i],
                    'size'     => $raw['size'][$i],
                    'error'    => $raw['error'][$i],
                ] : [
                    'name'     => $raw['name'],
                    'tmp_name' => $raw['tmp_name'],
                    'type'     => $raw['type'],
                    'size'     => $raw['size'],
                    'error'    => $raw['error'],
                ];
            }
        }
        $files = array_values(array_filter($files, function($f) { return !empty($f['name']) && $f['error'] === UPLOAD_ERR_OK; }));

        $errors = [];
        foreach ($files as $f) {
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_types))
                $errors[] = htmlspecialchars($f['name']) . ': file type not allowed.';
            elseif ($f['size'] > $max_size)
                $errors[] = htmlspecialchars($f['name']) . ': exceeds 20 MB limit.';
        }

        if ($errors) {
            $message  = implode('<br>', $errors);
            $msg_type = 'error';
        } else {
            $file_set = !empty($files) ? $files : [null];
            $saved    = 0;
            $fail_doc = '';

            foreach ($file_set as $idx => $f) {
                $doc_code = 'DOC-' . strtoupper(uniqid());
                $code_e   = $conn->real_escape_string($doc_code);

                $file_title   = $title . ($saved > 0 ? ' (' . ($saved + 1) . ')' : '');
                $file_title_e = $conn->real_escape_string($file_title);

                $file_name = $file_path = $file_type_db = '';
                $file_size = 0;

                if ($f !== null) {
                    $ext       = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
                    $safe_name = $doc_code . '.' . $ext;
                    $dest      = $upload_dir . $safe_name;
                    if (!move_uploaded_file($f['tmp_name'], $dest)) {
                        $fail_doc .= ($fail_doc ? ', ' : '') . htmlspecialchars($f['name']);
                        continue;
                    }
                    $file_name    = $conn->real_escape_string($f['name']);
                    $file_path    = $conn->real_escape_string($dest);
                    $file_type_db = $conn->real_escape_string($f['type']);
                    $file_size    = (int)$f['size'];
                }

                $r = $conn->query("
                    INSERT INTO documents
                        (document_code, title, description, program_id, area_id, accreditation_level_id,
                         academic_year, semester, deadline, uploaded_by, file_name, file_path, file_type, file_size, status)
                    VALUES
                        ('$code_e','$file_title_e','$desc_e',$prog_sql,$area_sql,$level_sql,
                         '$acad_e','$sem_e',$dead_sql,$uid,'$file_name','$file_path','$file_type_db',$file_size,'draft')
                ");

                if ($r) {
                    $new_id = $conn->insert_id;
                    if ($file_name) {
                        $conn->query("INSERT INTO document_versions
                            (document_id, version_number, file_name, file_path, file_type, file_size, uploaded_by)
                            VALUES ($new_id, 1, '$file_name', '$file_path', '$file_type_db', $file_size, $uid)");
                    }
                    $saved++;
                }
            }

            if ($saved > 0) {
                $message  = $saved === 1
                    ? "Document saved as draft successfully!"
                    : "$saved documents saved as drafts successfully!";
                $msg_type = 'success';
                if ($fail_doc) $message .= " (Upload failed for: $fail_doc)";
            } else {
                $message  = $fail_doc
                    ? "Upload failed for: $fail_doc. Check server permissions."
                    : "Database error: " . $conn->error;
                $msg_type = 'error';
            }
        }
    }
}

// Always load college & program from DB (authoritative) — not from session or POST
$user_info_q = $conn->query("
    SELECT u.college_id, u.program_id,
           c.college_name, c.college_code,
           p.program_name, p.program_code
    FROM users u
    LEFT JOIN colleges c ON c.id = u.college_id
    LEFT JOIN programs p ON p.id = u.program_id
    WHERE u.id = $uid
");
$user_info = $user_info_q ? $user_info_q->fetch_assoc() : [];

$user_college_id   = (int)($user_info['college_id']   ?? 0);
$user_program_id   = (int)($user_info['program_id']   ?? 0);
$user_college_name = $user_info['college_name'] ?? '';
$user_college_code = $user_info['college_code'] ?? '';
$user_program_name = $user_info['program_name'] ?? '';
$user_program_code = $user_info['program_code'] ?? '';

$areas       = $conn->query("SELECT id, area_name, area_code FROM areas ORDER BY sort_order, area_name");
$levels      = $conn->query("SELECT id, level_name FROM accreditation_levels ORDER BY level_order");
$notif_count = (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Upload Document — QA Portal</title>
    <?php include 'head.php'; ?>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
        </button>
        <div class="topbar-title">Upload Document</div>
        <div class="topbar-right">
            <a href="notifications.php" class="notif-btn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path d="M10 2a6 6 0 00-6 6v3.586l-.707.707A1 1 0 004 14h12a1 1 0 00.707-1.707L16 11.586V8a6 6 0 00-6-6zM10 18a3 3 0 01-3-3h6a3 3 0 01-3 3z"/></svg>
                <?php if ($notif_count > 0): ?><span class="notif-badge"><?= $notif_count ?></span><?php endif; ?>
            </a>
        </div>
    </div>

    <div class="page-body">
        <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?>">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <?= $message ?>
            <?php if ($msg_type === 'success'): ?>
            &nbsp;<a href="my_documents.php" style="font-weight:700;color:inherit;">View My Documents &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h1 class="page-heading">Upload New Document</h1>
                <p class="page-subheading">Submit an accreditation document for QA review</p>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data">
            <!-- Hidden: pass the real program_id from DB to POST handler -->
            <input type="hidden" name="program_id" value="<?= $user_program_id ?>">

            <div style="display:grid;grid-template-columns:1fr 340px;gap:20px;">
                <div style="display:flex;flex-direction:column;gap:16px;">

                    <!-- ── Document Information ── -->
                    <div class="card">
                        <div class="card-header"><span class="card-title">Document Information</span></div>
                        <div class="card-body">
                            <div class="form-row">
                                <div class="field">
                                    <label>Document Title *</label>
                                    <input type="text" name="title" required placeholder="Enter document title"
                                           value="<?= isset($_POST['title']) ? htmlspecialchars($_POST['title']) : '' ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="field">
                                    <label>Description</label>
                                    <textarea name="description" rows="3" placeholder="Brief description…"><?= isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '' ?></textarea>
                                </div>
                            </div>
                            <div class="form-row cols-2">
                                <div class="field">
                                    <label>Academic Year</label>
                                    <input type="text" name="academic_year" placeholder="e.g. 2024-2025"
                                           value="<?= isset($_POST['academic_year']) ? htmlspecialchars($_POST['academic_year']) : date('Y').'-'.(date('Y')+1) ?>">
                                </div>
                                <div class="field">
                                    <label>Semester</label>
                                    <select name="semester">
                                        <option value="1st">1st Semester</option>
                                        <option value="2nd">2nd Semester</option>
                                        <option value="Summer">Summer</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Classification ── -->
                    <div class="card">
                        <div class="card-header"><span class="card-title">Classification</span></div>
                        <div class="card-body">

                            <!-- College & Program: read-only display block -->
                            <div class="readonly-info-block">
                                <div class="readonly-info-block__title">
                                    <svg viewBox="0 0 20 20" fill="currentColor" width="13" height="13"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
                                    Your College &amp; Program
                                </div>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:8px;">
                                    <div>
                                        <div class="readonly-info-block__label">College</div>
                                        <div class="readonly-info-block__value">
                                            <?php if ($user_college_id): ?>
                                                <span class="readonly-code"><?= htmlspecialchars($user_college_code) ?></span>
                                                <?= htmlspecialchars($user_college_name) ?>
                                            <?php else: ?>
                                                <span class="readonly-empty">Not assigned</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="readonly-info-block__label">Program</div>
                                        <div class="readonly-info-block__value">
                                            <?php if ($user_program_id): ?>
                                                <span class="readonly-code"><?= htmlspecialchars($user_program_code) ?></span>
                                                <?= htmlspecialchars($user_program_name) ?>
                                            <?php else: ?>
                                                <span class="readonly-empty">Not assigned</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <p class="readonly-info-block__note">
                                    🔒 These are set from your account. Contact your admin to update them.
                                </p>
                            </div>

                            <!-- Accreditation Area & Level -->
                            <div class="form-row cols-2">
                                <div class="field">
                                    <label>Accreditation Area</label>
                                    <select name="area_id">
                                        <option value="0">Select area…</option>
                                        <?php while ($a = $areas->fetch_assoc()): ?>
                                        <option value="<?= $a['id'] ?>"><?= $a['area_code'] ? '['.htmlspecialchars($a['area_code']).'] ' : '' ?><?= htmlspecialchars($a['area_name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="field">
                                    <label>Accreditation Level</label>
                                    <select name="accreditation_level_id">
                                        <option value="0">Select level…</option>
                                        <?php while ($lv = $levels->fetch_assoc()): ?>
                                        <option value="<?= $lv['id'] ?>"><?= htmlspecialchars($lv['level_name']) ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="field" style="margin-top:8px;padding:12px;background:var(--primary-xlight);border-radius:8px;border:1px solid var(--border);">
                                <span style="font-size:.8rem;color:var(--primary-light);">
                                    <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;display:inline;vertical-align:middle;margin-right:4px;"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
                                    Submission deadlines are assigned by the QA team after document review.
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ── Right column ── -->
                <div style="display:flex;flex-direction:column;gap:16px;">
                    <div class="card">
                        <div class="card-header"><span class="card-title">File Upload</span></div>
                        <div class="card-body">
                            <div class="upload-dropzone" id="dropzone" onclick="document.getElementById('fileInput').click()">
                                <div class="upload-icon">
                                    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M24 32V16m0 0L18 22m6-6l6 6" stroke-linecap="round" stroke-linejoin="round"/>
                                        <rect x="4" y="4" width="40" height="40" rx="8" stroke-dasharray="4 4"/>
                                    </svg>
                                </div>
                                <p class="upload-label">Click or drag &amp; drop</p>
                                <p class="upload-hint">PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX &mdash; max 20MB each &mdash; <strong>multiple files allowed</strong></p>
                                <div id="fileChosen" class="upload-chosen"></div>
                            </div>
                            <input type="file" id="fileInput" name="document_files[]"
                                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg"
                                   multiple style="display:none;" onchange="showFiles(this)">
                            <ul id="fileList" style="margin-top:10px;padding:0;list-style:none;display:none;"></ul>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><span class="card-title">Save</span></div>
                        <div class="card-body">
                            <p class="text-sm text-muted" style="margin-bottom:16px;">
                                Documents are saved as <strong>Draft</strong> first. Submit for review from My Documents when ready.
                            </p>
                            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
                                <svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;"><path fill-rule="evenodd" d="M3 17a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM6.293 6.707a1 1 0 010-1.414l3-3a1 1 0 011.414 0l3 3a1 1 0 01-1.414 1.414L11 5.414V13a1 1 0 11-2 0V5.414L7.707 6.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                                Save as Draft
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
/* Read-only college/program info block */
.readonly-info-block {
    background: #f8fafc;
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 16px;
}
.readonly-info-block__title {
    font-size: .68rem;
    font-weight: 800;
    color: var(--primary-light, #2563eb);
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.readonly-info-block__label {
    font-size: .72rem;
    color: var(--muted, #6b7280);
    font-weight: 600;
    margin-bottom: 4px;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.readonly-info-block__value {
    padding: 8px 12px;
    background: white;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    font-size: .85rem;
    font-weight: 600;
    color: var(--text, #1e293b);
    min-height: 38px;
    display: flex;
    align-items: center;
    gap: 5px;
    flex-wrap: wrap;
}
.readonly-code {
    background: #e0e7ff;
    color: #3730a3;
    font-size: .72rem;
    font-weight: 800;
    padding: 2px 7px;
    border-radius: 5px;
    white-space: nowrap;
}
.readonly-empty {
    color: #9ca3af;
    font-style: italic;
    font-weight: 400;
}
.readonly-info-block__note {
    font-size: .72rem;
    color: #94a3b8;
    margin: 0;
}

/* Upload dropzone */
.upload-dropzone {
    border: 2px dashed var(--border); border-radius: 10px;
    padding: 32px 20px; text-align: center; cursor: pointer;
    transition: all .2s;
}
.upload-dropzone:hover, .upload-dropzone.drag-over {
    border-color: var(--primary-light); background: var(--primary-xlight);
}
.upload-icon { color: var(--muted); margin-bottom: 12px; }
.upload-icon svg { width: 48px; height: 48px; margin: 0 auto; display: block; }
.upload-label { font-size: .88rem; font-weight: 500; color: var(--text); margin-bottom: 4px; }
.upload-hint  { font-size: .75rem; color: var(--muted); }
.upload-chosen { margin-top: 10px; font-size: .8rem; color: var(--primary-light); font-weight: 600; word-break: break-all; }
</style>

<script>
let allFiles = new DataTransfer();

function renderFileList() {
    const fi     = document.getElementById('fileInput');
    const list   = document.getElementById('fileList');
    const chosen = document.getElementById('fileChosen');
    list.innerHTML = '';
    if (allFiles.files.length === 0) {
        list.style.display = 'none';
        chosen.textContent = '';
        return;
    }
    list.style.display = 'block';
    chosen.textContent = '';
    Array.from(allFiles.files).forEach((f, i) => {
        const li = document.createElement('li');
        li.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:5px 8px;margin-bottom:4px;background:var(--primary-xlight,#eff6ff);border-radius:6px;font-size:.8rem;';
        const name = document.createElement('span');
        name.style.cssText = 'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;color:var(--primary-light,#2563eb);font-weight:600;';
        name.textContent = '✓ ' + f.name + ' (' + (f.size/1024/1024).toFixed(2) + ' MB)';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = '✕';
        btn.title = 'Remove';
        btn.style.cssText = 'margin-left:8px;background:none;border:none;cursor:pointer;color:var(--muted,#6b7280);font-size:1rem;line-height:1;padding:0 2px;';
        btn.onclick = () => {
            const dt = new DataTransfer();
            Array.from(allFiles.files).forEach((ff, j) => { if (j !== i) dt.items.add(ff); });
            allFiles = dt;
            fi.files = allFiles.files;
            renderFileList();
        };
        li.appendChild(name);
        li.appendChild(btn);
        list.appendChild(li);
    });
    fi.files = allFiles.files;
}

function showFiles(input) {
    Array.from(input.files).forEach(f => allFiles.items.add(f));
    renderFileList();
}

const dz = document.getElementById('dropzone');
dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
    e.preventDefault();
    dz.classList.remove('drag-over');
    Array.from(e.dataTransfer.files).forEach(f => allFiles.items.add(f));
    document.getElementById('fileInput').files = allFiles.files;
    renderFileList();
});
</script>
</body>
</html>