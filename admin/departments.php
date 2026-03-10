<?php
session_start();
include '../database/db_connect.php';
//include '../auth.php';
//require_login(['qa_director', 'qa_staff']);

$active_nav = 'departments';
$active_tab = in_array($_GET['tab'] ?? '', ['colleges','programs','areas','levels']) ? $_GET['tab'] : 'colleges';

// ── POST → Redirect ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['form_action'] ?? '';

    // ── COLLEGES ──────────────────────────────────────────────
    if ($act === 'create_college') {
        $name = $conn->real_escape_string(trim($_POST['college_name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['college_code'] ?? ''));
        if ($name && $code) {
            $r = $conn->query("INSERT INTO colleges (college_code, college_name) VALUES ('$code','$name')");
            $msg = $r ? 'College added successfully.' : 'Error: '.$conn->error;
            $typ = $r ? 's' : 'e';
        } else { $msg = 'Code and name are required.'; $typ = 'e'; }
        header("Location: departments.php?tab=colleges&msg=".urlencode($msg)."&typ=$typ"); exit;

    } elseif ($act === 'edit_college') {
        $id   = (int)$_POST['college_id'];
        $name = $conn->real_escape_string(trim($_POST['college_name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['college_code'] ?? ''));
        $stat = in_array($_POST['status']??'', ['active','inactive']) ? $_POST['status'] : 'active';
        $conn->query("UPDATE colleges SET college_code='$code', college_name='$name', status='$stat' WHERE id=$id");
        header("Location: departments.php?tab=colleges&msg=".urlencode('College updated.')."&typ=s"); exit;

    } elseif ($act === 'delete_college') {
        $id = (int)$_POST['college_id'];
        $doc_cnt = (int)$conn->query("
            SELECT COUNT(*) c FROM documents d
            JOIN programs p ON p.id = d.program_id
            WHERE p.college_id = $id AND d.deleted_at IS NULL
        ")->fetch_assoc()['c'];
        if ($doc_cnt > 0) {
            header("Location: departments.php?tab=colleges&msg=".urlencode("Cannot delete: this college has $doc_cnt active document(s).")."&typ=e"); exit;
        }
        $conn->query("DELETE FROM programs WHERE college_id=$id");
        $conn->query("DELETE FROM colleges WHERE id=$id");
        header("Location: departments.php?tab=colleges&msg=".urlencode('College and all its programs deleted.')."&typ=s"); exit;

    // ── PROGRAMS ──────────────────────────────────────────────
    } elseif ($act === 'create_program') {
        $name = $conn->real_escape_string(trim($_POST['program_name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['program_code'] ?? ''));
        $cid  = (int)($_POST['college_id'] ?? 0);
        if ($name && $code && $cid) {
            $r = $conn->query("INSERT INTO programs (program_code, program_name, college_id, department_id) VALUES ('$code','$name',$cid,NULL)");
            $msg = $r ? 'Program added.' : 'Error: '.$conn->error;
            $typ = $r ? 's' : 'e';
        } else { $msg = 'Code, name and college are required.'; $typ = 'e'; }
        header("Location: departments.php?tab=programs&msg=".urlencode($msg)."&typ=$typ"); exit;

    } elseif ($act === 'edit_program') {
        $id   = (int)$_POST['prog_id'];
        $name = $conn->real_escape_string(trim($_POST['program_name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['program_code'] ?? ''));
        $cid  = (int)($_POST['college_id'] ?? 0);
        $stat = in_array($_POST['status']??'', ['active','inactive']) ? $_POST['status'] : 'active';
        $conn->query("UPDATE programs SET program_code='$code', program_name='$name', college_id=$cid, status='$stat' WHERE id=$id");
        header("Location: departments.php?tab=programs&msg=".urlencode('Program updated.')."&typ=s"); exit;

    } elseif ($act === 'delete_program') {
        $id  = (int)$_POST['prog_id'];
        $cnt = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE program_id=$id AND deleted_at IS NULL")->fetch_assoc()['c'];
        if ($cnt > 0) { header("Location: departments.php?tab=programs&msg=".urlencode('Cannot delete: program has active documents.')."&typ=e"); exit; }
        $conn->query("DELETE FROM programs WHERE id=$id");
        header("Location: departments.php?tab=programs&msg=".urlencode('Program deleted.')."&typ=s"); exit;

    // ── AREAS ─────────────────────────────────────────────────
    } elseif ($act === 'create_area') {
        $name = $conn->real_escape_string(trim($_POST['area_name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['area_code'] ?? ''));
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        if ($name) {
            $r = $conn->query("INSERT INTO areas (area_name, area_code, description, sort_order) VALUES ('$name', " . ($code ? "'$code'" : 'NULL') . ", " . ($desc ? "'$desc'" : 'NULL') . ", $sort)");
            $msg = $r ? 'Area added successfully.' : 'Error: ' . $conn->error;
            $typ = $r ? 's' : 'e';
        } else { $msg = 'Area name is required.'; $typ = 'e'; }
        header("Location: departments.php?tab=areas&msg=".urlencode($msg)."&typ=$typ"); exit;

    } elseif ($act === 'edit_area') {
        $id   = (int)$_POST['area_id'];
        $name = $conn->real_escape_string(trim($_POST['area_name'] ?? ''));
        $code = $conn->real_escape_string(trim($_POST['area_code'] ?? ''));
        $desc = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $sort = (int)($_POST['sort_order'] ?? 0);
        $conn->query("UPDATE areas SET area_name='$name', area_code=" . ($code ? "'$code'" : 'NULL') . ", description=" . ($desc ? "'$desc'" : 'NULL') . ", sort_order=$sort WHERE id=$id");
        header("Location: departments.php?tab=areas&msg=".urlencode('Area updated.')."&typ=s"); exit;

    } elseif ($act === 'delete_area') {
        $id  = (int)$_POST['area_id'];
        $cnt = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE area_id=$id AND deleted_at IS NULL")->fetch_assoc()['c'];
        if ($cnt > 0) { header("Location: departments.php?tab=areas&msg=".urlencode('Cannot delete: area has documents.')."&typ=e"); exit; }
        $conn->query("DELETE FROM areas WHERE id=$id");
        header("Location: departments.php?tab=areas&msg=".urlencode('Area deleted.')."&typ=s"); exit;

    // ── LEVELS ────────────────────────────────────────────────
    } elseif ($act === 'create_level') {
        $name  = $conn->real_escape_string(trim($_POST['level_name'] ?? ''));
        $order = (int)($_POST['level_order'] ?? 1);
        $desc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        if ($name) {
            $r = $conn->query("INSERT INTO accreditation_levels (level_name, level_order, description) VALUES ('$name', $order, " . ($desc ? "'$desc'" : 'NULL') . ")");
            $msg = $r ? 'Level added successfully.' : 'Error: ' . $conn->error;
            $typ = $r ? 's' : 'e';
        } else { $msg = 'Level name is required.'; $typ = 'e'; }
        header("Location: departments.php?tab=levels&msg=".urlencode($msg)."&typ=$typ"); exit;

    } elseif ($act === 'edit_level') {
        $id    = (int)$_POST['level_id'];
        $name  = $conn->real_escape_string(trim($_POST['level_name'] ?? ''));
        $order = (int)($_POST['level_order'] ?? 1);
        $desc  = $conn->real_escape_string(trim($_POST['description'] ?? ''));
        $conn->query("UPDATE accreditation_levels SET level_name='$name', level_order=$order, description=" . ($desc ? "'$desc'" : 'NULL') . " WHERE id=$id");
        header("Location: departments.php?tab=levels&msg=".urlencode('Level updated.')."&typ=s"); exit;

    } elseif ($act === 'delete_level') {
        $id  = (int)$_POST['level_id'];
        $cnt = (int)$conn->query("SELECT COUNT(*) c FROM documents WHERE accreditation_level_id=$id AND deleted_at IS NULL")->fetch_assoc()['c'];
        if ($cnt > 0) { header("Location: departments.php?tab=levels&msg=".urlencode('Cannot delete: level is used by documents.')."&typ=e"); exit; }
        $conn->query("DELETE FROM accreditation_levels WHERE id=$id");
        header("Location: departments.php?tab=levels&msg=".urlencode('Level deleted.')."&typ=s"); exit;
    }
    header("Location: departments.php?tab=$active_tab"); exit;
}

// Flash from redirect
$message  = htmlspecialchars(urldecode($_GET['msg'] ?? ''));
$msg_type = ($_GET['typ'] ?? '') === 'e' ? 'error' : 'success';

$colleges = $conn->query("SELECT * FROM colleges ORDER BY college_name");
$programs = $conn->query("SELECT p.*, c.college_name, c.college_code FROM programs p JOIN colleges c ON c.id=p.college_id ORDER BY c.college_name, p.program_name");
$areas    = $conn->query("SELECT * FROM areas ORDER BY sort_order, area_name");
$levels   = $conn->query("SELECT * FROM accreditation_levels ORDER BY level_order, level_name");

$uid         = (int)($_SESSION['user_id'] ?? 0);
$notif_count = $uid ? (int)$conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0")->fetch_assoc()['c'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Colleges, Programs & Areas — QA System</title>
    <?php include 'head.php'; ?>
    <style>
    .tab-btn { padding:8px 18px;border-radius:8px;font-family:inherit;font-size:.85rem;color:var(--muted);cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;border:none;background:none; }
    .tab-btn.active { background:var(--primary);color:white; }
    .tab-btn:hover:not(.active) { background:var(--primary-xlight);color:var(--primary); }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>

<div class="main-content">
    <div class="topbar">
        <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Toggle menu">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 10a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zM3 15a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
        </button>
        <div class="topbar-title">Colleges, Programs & Areas</div>
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
        </div>
        <?php endif; ?>

        <div class="page-header">
            <h1 class="page-heading">Colleges, Programs & Areas</h1>
        </div>

        <!-- Tabs: Colleges | Programs | Areas | Levels (NO Majors tab) -->
        <div style="display:flex;gap:4px;background:white;padding:6px;border-radius:12px;border:1px solid var(--border);margin-bottom:24px;width:fit-content;">
            <a href="?tab=colleges" class="tab-btn <?= $active_tab==='colleges'?'active':'' ?>">
                Colleges <span style="font-size:.72rem;opacity:.7;">(<?= $colleges->num_rows ?>)</span>
            </a>
            <a href="?tab=programs" class="tab-btn <?= $active_tab==='programs'?'active':'' ?>">
                Programs <span style="font-size:.72rem;opacity:.7;">(<?= $programs->num_rows ?>)</span>
            </a>
            <a href="?tab=areas" class="tab-btn <?= $active_tab==='areas'?'active':'' ?>">
                Areas <span style="font-size:.72rem;opacity:.7;">(<?= $areas->num_rows ?>)</span>
            </a>
            <a href="?tab=levels" class="tab-btn <?= $active_tab==='levels'?'active':'' ?>">
                Accreditation Levels <span style="font-size:.72rem;opacity:.7;">(<?= $levels->num_rows ?>)</span>
            </a>
        </div>

        <?php if ($active_tab === 'colleges'): ?>
        <!-- ══ COLLEGES ══ -->
        <div style="display:grid;grid-template-columns:1fr 360px;gap:20px;">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Colleges</span>
                    <span class="text-sm text-muted"><?= $colleges->num_rows ?> records</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Code</th><th>Name</th><th>Programs</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php $colleges->data_seek(0); while ($c = $colleges->fetch_assoc()):
                            $prog_count = (int)$conn->query("SELECT COUNT(*) c FROM programs WHERE college_id={$c['id']} AND status='active'")->fetch_assoc()['c'];
                        ?>
                        <tr>
                            <td class="text-sm"><strong><?= htmlspecialchars($c['college_code']) ?></strong></td>
                            <td><?= htmlspecialchars($c['college_name']) ?></td>
                            <td><span class="badge badge-active"><?= $prog_count ?> program<?= $prog_count != 1 ? 's' : '' ?></span></td>
                            <td><span class="badge badge-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick='openEditCollege(<?= json_encode($c) ?>)'>Edit</button>
                                <form method="POST" style="display:inline;" class="swal-confirm-form" data-title="Confirm Delete" data-text="Delete this college and ALL its programs?" data-icon="warning" data-confirm="Yes, Delete">
                                    <input type="hidden" name="form_action" value="delete_college">
                                    <input type="hidden" name="college_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($colleges->num_rows === 0): ?>
                        <tr><td colspan="5"><div class="empty-state"><p>No colleges yet.</p></div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Add College</span></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="form_action" value="create_college">
                        <div class="field" style="margin-bottom:12px;"><label>College Code *</label><input type="text" name="college_code" required placeholder="e.g. CTE" maxlength="20"></div>
                        <div class="field" style="margin-bottom:16px;"><label>College Name *</label><input type="text" name="college_name" required placeholder="e.g. College of Teacher Education"></div>
                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Add College</button>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($active_tab === 'programs'): ?>
        <!-- ══ PROGRAMS (directly under College, no Major) ══ -->
        <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Programs</span>
                    <span class="text-sm text-muted"><?= $programs->num_rows ?> records</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Code</th><th>Program Name</th><th>College</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php $programs->data_seek(0); while ($p = $programs->fetch_assoc()): ?>
                        <tr>
                            <td class="text-sm"><strong><?= htmlspecialchars($p['program_code']) ?></strong></td>
                            <td><?= htmlspecialchars($p['program_name']) ?></td>
                            <td class="text-sm"><?= htmlspecialchars($p['college_name'] ?? '—') ?></td>
                            <td><span class="badge badge-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                            <td>
                                <button class="btn btn-ghost btn-sm" onclick='openEditProg(<?= json_encode($p) ?>)'>Edit</button>
                                <form method="POST" style="display:inline;" class="swal-confirm-form" data-title="Confirm Delete" data-text="Delete this program?" data-icon="warning" data-confirm="Yes, Delete">
                                    <input type="hidden" name="form_action" value="delete_program">
                                    <input type="hidden" name="prog_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($programs->num_rows === 0): ?>
                        <tr><td colspan="5"><div class="empty-state"><p>No programs yet.</p></div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Add Program</span></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="form_action" value="create_program">
                        <div class="field" style="margin-bottom:12px;">
                            <label>College *</label>
                            <select name="college_id" required>
                                <option value="">Select college…</option>
                                <?php $colleges->data_seek(0); while ($c = $colleges->fetch_assoc()): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['college_name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="field" style="margin-bottom:12px;"><label>Program Code *</label><input type="text" name="program_code" required placeholder="e.g. BSED-MATH" maxlength="30"></div>
                        <div class="field" style="margin-bottom:16px;"><label>Program Name *</label><input type="text" name="program_name" required placeholder="e.g. Bachelor of Secondary Education Major in Mathematics"></div>
                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Add Program</button>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($active_tab === 'areas'): ?>
        <!-- ══ AREAS ══ -->
        <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Accreditation Areas</span>
                    <span class="text-sm text-muted"><?= $areas->num_rows ?> areas</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Order</th><th>Code</th><th>Area Name</th><th>Description</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php $areas->data_seek(0); while ($a = $areas->fetch_assoc()): ?>
                        <tr>
                            <td class="text-sm text-muted"><?= $a['sort_order'] ?></td>
                            <td><span style="font-family:monospace;font-size:.8rem;background:var(--bg);border:1px solid var(--border);border-radius:4px;padding:2px 8px;"><?= htmlspecialchars($a['area_code'] ?? '—') ?></span></td>
                            <td style="font-weight:600;font-size:.88rem;"><?= htmlspecialchars($a['area_name']) ?></td>
                            <td class="text-sm text-muted"><?= htmlspecialchars(mb_substr($a['description'] ?? '',0,60)) ?></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-ghost btn-sm" onclick='openEditArea(<?= json_encode($a) ?>)'>Edit</button>
                                    <form method="POST" style="display:inline;" class="swal-confirm-form" data-title="Confirm Delete" data-text="Delete this area?" data-icon="warning" data-confirm="Yes, Delete">
                                        <input type="hidden" name="form_action" value="delete_area">
                                        <input type="hidden" name="area_id" value="<?= $a['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($areas->num_rows === 0): ?>
                        <tr><td colspan="5"><div class="empty-state"><p>No areas yet.</p></div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Add Area</span></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="form_action" value="create_area">
                        <div class="field" style="margin-bottom:12px;"><label>Area Name *</label><input type="text" name="area_name" required placeholder="e.g. Curriculum and Instruction"></div>
                        <div class="field" style="margin-bottom:12px;"><label>Area Code</label><input type="text" name="area_code" placeholder="e.g. A3" maxlength="30"></div>
                        <div class="field" style="margin-bottom:12px;"><label>Description</label><textarea name="description" rows="2" placeholder="Brief description…"></textarea></div>
                        <div class="field" style="margin-bottom:16px;"><label>Sort Order</label><input type="number" name="sort_order" value="0" min="0"></div>
                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Add Area</button>
                    </form>
                </div>
            </div>
        </div>

        <?php elseif ($active_tab === 'levels'): ?>
        <!-- ══ ACCREDITATION LEVELS ══ -->
        <div style="display:grid;grid-template-columns:1fr 380px;gap:20px;">
            <div class="card">
                <div class="card-header">
                    <span class="card-title">Accreditation Levels</span>
                    <span class="text-sm text-muted"><?= $levels->num_rows ?> levels</span>
                </div>
                <div class="table-wrapper">
                    <table>
                        <thead><tr><th>Order</th><th>Level Name</th><th>Description</th><th>Actions</th></tr></thead>
                        <tbody>
                        <?php $levels->data_seek(0); while ($lv = $levels->fetch_assoc()): ?>
                        <tr>
                            <td><span style="font-family:monospace;font-size:.8rem;font-weight:700;color:var(--primary);"><?= $lv['level_order'] ?></span></td>
                            <td style="font-weight:600;font-size:.88rem;"><?= htmlspecialchars($lv['level_name']) ?></td>
                            <td class="text-sm text-muted"><?= htmlspecialchars(mb_substr($lv['description'] ?? '',0,80)) ?></td>
                            <td>
                                <div style="display:flex;gap:6px;">
                                    <button class="btn btn-ghost btn-sm" onclick='openEditLevel(<?= json_encode($lv) ?>)'>Edit</button>
                                    <form method="POST" style="display:inline;" class="swal-confirm-form" data-title="Confirm Delete" data-text="Delete this level?" data-icon="warning" data-confirm="Yes, Delete">
                                        <input type="hidden" name="form_action" value="delete_level">
                                        <input type="hidden" name="level_id" value="<?= $lv['id'] ?>">
                                        <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php if ($levels->num_rows === 0): ?>
                        <tr><td colspan="4"><div class="empty-state"><p>No accreditation levels yet.</p></div></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><span class="card-title">Add Accreditation Level</span></div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="form_action" value="create_level">
                        <div class="field" style="margin-bottom:12px;"><label>Level Name *</label><input type="text" name="level_name" required placeholder="e.g. Level I Candidate Status"></div>
                        <div class="field" style="margin-bottom:12px;"><label>Order</label><input type="number" name="level_order" value="<?= $levels->num_rows + 1 ?>" min="1"></div>
                        <div class="field" style="margin-bottom:16px;"><label>Description</label><textarea name="description" rows="3" placeholder="Brief description of this accreditation level…"></textarea></div>
                        <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">Add Level</button>
                    </form>
                </div>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>

<!-- ══ Edit College Modal ══ -->
<div class="modal-overlay" id="editCollegeModal">
    <div class="modal" style="max-width:440px;">
        <div class="modal-header">
            <span class="modal-title">Edit College</span>
            <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" value="edit_college">
                <input type="hidden" name="college_id" id="ec_id">
                <div class="form-row cols-2">
                    <div class="field"><label>Code *</label><input type="text" name="college_code" id="ec_code" required maxlength="20"></div>
                    <div class="field"><label>Status</label>
                        <select name="status" id="ec_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label>College Name *</label><input type="text" name="college_name" id="ec_name" required></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Edit Program Modal ══ -->
<div class="modal-overlay" id="editProgModal">
    <div class="modal" style="max-width:500px;">
        <div class="modal-header">
            <span class="modal-title">Edit Program</span>
            <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" value="edit_program">
                <input type="hidden" name="prog_id" id="ep_id">
                <div class="form-row cols-2">
                    <div class="field"><label>Code *</label><input type="text" name="program_code" id="ep_code" required maxlength="30"></div>
                    <div class="field"><label>Status</label>
                        <select name="status" id="ep_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label>College *</label>
                        <select name="college_id" id="ep_college" required>
                            <option value="">Select college…</option>
                            <?php $colleges->data_seek(0); while ($c = $colleges->fetch_assoc()): ?>
                            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['college_name']) ?></option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="field"><label>Program Name *</label><input type="text" name="program_name" id="ep_name" required></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Edit Area Modal ══ -->
<div class="modal-overlay" id="editAreaModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <span class="modal-title">Edit Area</span>
            <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" value="edit_area">
                <input type="hidden" name="area_id" id="ea_id">
                <div class="form-row"><div class="field"><label>Area Name *</label><input type="text" name="area_name" id="ea_name" required></div></div>
                <div class="form-row cols-2">
                    <div class="field"><label>Area Code</label><input type="text" name="area_code" id="ea_code" maxlength="30"></div>
                    <div class="field"><label>Sort Order</label><input type="number" name="sort_order" id="ea_sort" min="0"></div>
                </div>
                <div class="form-row"><div class="field"><label>Description</label><textarea name="description" id="ea_desc" rows="3"></textarea></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ══ Edit Level Modal ══ -->
<div class="modal-overlay" id="editLevelModal">
    <div class="modal" style="max-width:480px;">
        <div class="modal-header">
            <span class="modal-title">Edit Accreditation Level</span>
            <button type="button" class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('open')">✕</button>
        </div>
        <form method="POST">
            <div class="modal-body">
                <input type="hidden" name="form_action" value="edit_level">
                <input type="hidden" name="level_id" id="el_id">
                <div class="form-row"><div class="field"><label>Level Name *</label><input type="text" name="level_name" id="el_name" required></div></div>
                <div class="form-row"><div class="field"><label>Order</label><input type="number" name="level_order" id="el_order" min="1"></div></div>
                <div class="form-row"><div class="field"><label>Description</label><textarea name="description" id="el_desc" rows="3"></textarea></div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="this.closest('.modal-overlay').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditCollege(c) {
    document.getElementById('ec_id').value     = c.id;
    document.getElementById('ec_code').value   = c.college_code;
    document.getElementById('ec_name').value   = c.college_name;
    document.getElementById('ec_status').value = c.status;
    document.getElementById('editCollegeModal').classList.add('open');
}

function openEditProg(p) {
    document.getElementById('ep_id').value      = p.id;
    document.getElementById('ep_code').value    = p.program_code;
    document.getElementById('ep_name').value    = p.program_name;
    document.getElementById('ep_status').value  = p.status;
    document.getElementById('ep_college').value = p.college_id;
    document.getElementById('editProgModal').classList.add('open');
}

function openEditArea(a) {
    document.getElementById('ea_id').value   = a.id;
    document.getElementById('ea_code').value = a.area_code || '';
    document.getElementById('ea_name').value = a.area_name;
    document.getElementById('ea_desc').value = a.description || '';
    document.getElementById('ea_sort').value = a.sort_order || 0;
    document.getElementById('editAreaModal').classList.add('open');
}

function openEditLevel(lv) {
    document.getElementById('el_id').value    = lv.id;
    document.getElementById('el_name').value  = lv.level_name;
    document.getElementById('el_order').value = lv.level_order;
    document.getElementById('el_desc').value  = lv.description || '';
    document.getElementById('editLevelModal').classList.add('open');
}

// SweetAlert confirmations for edit modals
document.addEventListener('DOMContentLoaded', function() {
    var editModals = [
        { id: 'editCollegeModal', label: 'college' },
        { id: 'editProgModal',    label: 'program'  },
        { id: 'editAreaModal',    label: 'area'     },
        { id: 'editLevelModal',   label: 'level'    },
    ];
    editModals.forEach(function(m) {
        var modal = document.getElementById(m.id);
        if (!modal) return;
        var form = modal.querySelector('form');
        if (!form || form._swalWired) return;
        form._swalWired = true;
        var btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.type = 'button';
            btn.addEventListener('click', function() {
                Swal.fire({
                    title: 'Save Changes?',
                    html: 'Save changes to this ' + m.label + '?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Save',
                    cancelButtonText: 'Cancel',
                    reverseButtons: true, focusCancel: true,
                    customClass: { popup: 'qa-popup', confirmButton: 'qa-btn qa-btn-purple', cancelButton: 'qa-btn qa-btn-gray' },
                    buttonsStyling: false,
                }).then(function(r) {
                    if (r.isConfirmed) HTMLFormElement.prototype.submit.call(form);
                });
            });
        }
    });
});
</script>
</body>
</html>
