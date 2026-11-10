<?php
/**
 * Administration panel (server-rendered, no JavaScript). Loaded by public_html/admin/index.php.
 * Staff accounts are separate from student accounts. All output is escaped with h(); all SQL is parameterised.
 */
declare(strict_types=1);

hx_require_https();
hx_session('staff');

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/* ---------- helpers ---------- */

function a_flash(string $msg, string $kind = 'ok'): void { $_SESSION['flash'] = [$kind, $msg]; }
function a_redirect(?string $to = null): void
{
    header('Location: ' . ($to ?? (string)($_SERVER['REQUEST_URI'] ?? 'index.php')));
    exit;
}
function a_csrf(): string
{
    if (empty($_SESSION['acsrf'])) { $_SESSION['acsrf'] = bin2hex(random_bytes(32)); }
    return (string)$_SESSION['acsrf'];
}
function a_form(string $action, bool $multipart = false): string
{
    return '<form method="post" action=""' . ($multipart ? ' enctype="multipart/form-data"' : '') . '><input type="hidden" name="_csrf" value="' . h(a_csrf()) . '"><input type="hidden" name="a" value="' . h($action) . '">';
}
function a_staff(): ?array
{
    $id = $_SESSION['staff'] ?? null;
    if (!$id) { return null; }
    $t = time();
    if ($t - (int)($_SESSION['seen'] ?? 0) > (int)cfg('session.idle_minutes', 30) * 60 || $t - (int)($_SESSION['born'] ?? 0) > (int)cfg('session.absolute_hours', 8) * 3600) {
        session_reset();
        return null;
    }
    $s = row('SELECT * FROM staff WHERE id = ? AND active = 1', [(int)$id]);
    if (!$s) { session_reset(); return null; }
    $_SESSION['seen'] = $t;
    return $s;
}
function a_post(string $k, int $max = 500): string
{
    $v = $_POST[$k] ?? '';
    if (!is_string($v)) { return ''; }
    $v = trim($v);
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
}
function a_int(string $k): int { return (int)filter_var($_POST[$k] ?? 0, FILTER_VALIDATE_INT, ['options' => ['default' => 0]]); }
function a_bool(string $k): int { return !empty($_POST[$k]) ? 1 : 0; }
function a_date(?string $s): string { return $s ? h(date('j M Y, H:i', strtotime($s))) : ''; }
function a_https_url(string $u): bool { return (bool)preg_match('#^https://[^\s]+$#i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false; }

function a_layout(string $title, string $body, ?array $staff): void
{
    $nav = '';
    if ($staff) {
        $items = ['dashboard' => 'Dashboard', 'students' => 'Students', 'programmes' => 'Programmes', 'announcements' => 'Announcements', 'resets' => 'Reset requests', 'enquiries' => 'Enquiries'];
        if ($staff['role'] === 'admin') { $items['staff'] = 'Staff'; }
        $items['account'] = 'My account';
        foreach ($items as $k => $l) { $nav .= '<a href="?r=' . $k . '">' . h($l) . '</a>'; }
        $nav = '<nav aria-label="Admin">' . $nav . a_form('logout') . '<button class="link" type="submit">Sign out</button></form></nav>';
    }
    $flash = '';
    if (!empty($_SESSION['flash'])) {
        [$k, $m] = $_SESSION['flash'];
        unset($_SESSION['flash']);
        $flash = '<p class="flash ' . h($k) . '" role="status">' . h($m) . '</p>';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>'
        . h($title) . ' | Harvard Extention admin</title><link rel="stylesheet" href="admin.css"></head><body><header><strong>Harvard Extention Training College</strong> <span>Administration</span>'
        . ($staff ? '<small>Signed in as ' . h($staff['full_name']) . ' (' . h($staff['role']) . ')</small>' : '') . '</header>' . $nav
        . '<main>' . $flash . '<h1>' . h($title) . '</h1>' . $body . '</main></body></html>';
    exit;
}

function a_programme_options(?int $selected = null, bool $allOption = false): string
{
    $o = $allOption ? '<option value="">All students</option>' : '<option value="">Choose a programme</option>';
    foreach (rows('SELECT p.id, p.name, p.online_enabled, d.name AS dept FROM programmes p JOIN departments d ON d.id = p.department_id ORDER BY d.sort_order, p.sort_order') as $p) {
        $o .= '<option value="' . (int)$p['id'] . '"' . ($selected === (int)$p['id'] ? ' selected' : '') . '>' . h($p['dept'] . ': ' . $p['name'] . ($p['online_enabled'] ? '' : ' (not online)')) . '</option>';
    }
    return $o;
}

function a_save_upload(array $f): array
{
    $codes = [UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows.', UPLOAD_ERR_FORM_SIZE => 'The file is too large.', UPLOAD_ERR_NO_FILE => 'Choose a file to upload.'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($codes[$f['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'The upload failed. Try again.');
    }
    if ((int)$f['size'] > (int)cfg('uploads.max_mb', 20) * 1048576) {
        throw new RuntimeException('The file is larger than ' . (int)cfg('uploads.max_mb', 20) . ' MB.');
    }
    $allowed = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $orig = mb_substr(basename((string)$f['name']), 0, 190);
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) {
        throw new RuntimeException('That file type is not allowed. Allowed: ' . implode(', ', array_keys($allowed)) . '.');
    }
    if (!is_uploaded_file((string)$f['tmp_name'])) { throw new RuntimeException('The upload failed. Try again.'); }
    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $dir = HX_ROOT . '/storage/materials';
    if (!is_dir($dir) || !is_writable($dir)) { throw new RuntimeException('The storage folder is not writable. Contact the web developer.'); }
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $stored)) { throw new RuntimeException('The file could not be saved.'); }
    @chmod($dir . '/' . $stored, 0640);
    return [$stored, $orig, $allowed[$ext], (int)$f['size']];
}

function a_new_student(string $adm, string $name, string $email, string $phone): ?string
{
    if (!preg_match('#^[A-Za-z0-9/._-]{3,40}$#', $adm)) { return 'Admission number must be 3 to 40 characters: letters, numbers and / . _ - only.'; }
    if (mb_strlen($name) < 2) { return 'Enter the student\'s full registered name.'; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { return 'The email address is not valid.'; }
    if (row('SELECT id FROM students WHERE admission_no = ?', [$adm])) { return 'Admission number ' . $adm . ' already exists.'; }
    q('INSERT INTO students (admission_no, full_name, full_name_key, email, phone, status, password_hash, must_change_password, created_at) VALUES (?,?,?,?,?,?,?,1,?)',
        [$adm, $name, name_key($name), $email ?: null, $phone ?: null, 'active', password_hash(normalize_initial($adm), PASSWORD_DEFAULT), now()]);
    return null;
}

/* ---------- authentication ---------- */

$staff = a_staff();
$route = preg_replace('/[^a-z_]/', '', (string)($_GET['r'] ?? 'dashboard'));
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($post) {
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(a_csrf(), $sent)) {
        hx_fail(403, 'csrf', 'Your page has expired. Go back, reload it and try again.');
    }
}

if (!$staff) {
    $err = '';
    if ($post && ($_POST['a'] ?? '') === 'login') {
        $u = a_post('username', 60);
        $ip = client_ip();
        if (rl_count('staff_ip', $ip, 900) >= 10 || rl_count('staff_user', strtolower($u), 900) >= 5) {
            $err = 'Too many unsuccessful attempts. Try again in 15 minutes.';
        } else {
            $s = row('SELECT * FROM staff WHERE username = ? AND active = 1', [$u]);
            $pw = (string)($_POST['password'] ?? '');
            $ok = password_verify($pw, $s ? (string)$s['password_hash'] : password_hash($pw, PASSWORD_DEFAULT));
            if ($s && $ok) {
                rl_clear('staff_user', strtolower($u));
                session_reset();
                $_SESSION['staff'] = (int)$s['id'];
                $_SESSION['born'] = $_SESSION['seen'] = time();
                q('UPDATE staff SET last_login_at = ? WHERE id = ?', [now(), (int)$s['id']]);
                audit('staff', (int)$s['id'], 'login');
                a_redirect('index.php');
            }
            rl_hit('staff_ip', $ip);
            rl_hit('staff_user', strtolower($u));
            audit('system', null, 'staff_login_failed');
            $err = 'The username or password is incorrect.';
        }
    }
    a_layout('Staff sign in', ($err ? '<p class="flash error" role="alert">' . h($err) . '</p>' : '')
        . a_form('login') . '<label>Username<input name="username" autocomplete="username" required></label>'
        . '<label>Password<input name="password" type="password" autocomplete="current-password" required></label><button type="submit">Sign in</button></form>', null);
}

$sid = (int)$staff['id'];
$isAdmin = $staff['role'] === 'admin';

/* ---------- POST actions ---------- */

if ($post) {
    $act = (string)($_POST['a'] ?? '');
    try {
        switch ($act) {
            case 'logout':
                audit('staff', $sid, 'logout');
                session_reset();
                a_redirect('index.php');
            case 'student_add':
                $e = a_new_student(a_post('admission_no', 40), a_post('full_name', 150), a_post('email', 190), a_post('phone', 30));
                if ($e) { a_flash($e, 'error'); } else { a_flash('Student registered. Their initial password is their admission number; they must change it at first sign in.'); audit('staff', $sid, 'student_add', a_post('admission_no', 40)); }
                a_redirect();
            case 'students_import':
                $f = $_FILES['csv'] ?? null;
                if (!$f || $f['error'] !== UPLOAD_ERR_OK) { a_flash('Choose a CSV file to import.', 'error'); a_redirect(); }
                $h = fopen((string)$f['tmp_name'], 'r');
                $head = $h ? fgetcsv($h) : false;
                $head = $head ? array_map(static fn($x) => strtolower(trim((string)$x, " \t\n\r\0\x0B\xEF\xBB\xBF")), $head) : [];
                $ia = array_search('admission_no', $head, true); $in = array_search('full_name', $head, true);
                if ($ia === false || $in === false) { a_flash('The first row must contain the column names admission_no and full_name (email and phone are optional).', 'error'); a_redirect(); }
                $ie = array_search('email', $head, true); $ip2 = array_search('phone', $head, true);
                $added = 0; $problems = [];
                $n = 0;
                while (($r = fgetcsv($h)) !== false && $n < 1000) {
                    $n++;
                    if (count($r) === 1 && trim((string)$r[0]) === '') { continue; }
                    $e = a_new_student(trim((string)($r[$ia] ?? '')), trim((string)($r[$in] ?? '')), $ie !== false ? trim((string)($r[$ie] ?? '')) : '', $ip2 !== false ? trim((string)($r[$ip2] ?? '')) : '');
                    if ($e) { $problems[] = 'Row ' . ($n + 1) . ': ' . $e; } else { $added++; }
                }
                fclose($h);
                audit('staff', $sid, 'students_import', $added . ' added');
                a_flash($added . ' student(s) registered.' . ($problems ? ' Not imported: ' . implode(' ', array_slice($problems, 0, 8)) . (count($problems) > 8 ? ' (and ' . (count($problems) - 8) . ' more)' : '') : ''), $problems ? 'error' : 'ok');
                a_redirect();
            case 'student_status':
                $st = a_post('status', 12);
                if (in_array($st, ['active', 'suspended', 'withdrawn'], true)) {
                    q('UPDATE students SET status = ? WHERE id = ?', [$st, a_int('id')]);
                    audit('staff', $sid, 'student_status', a_int('id') . ' ' . $st);
                    a_flash('Student status updated.');
                }
                a_redirect();
            case 'student_reset':
                $stu = row('SELECT id, admission_no FROM students WHERE id = ?', [a_int('id')]);
                if ($stu) {
                    q('UPDATE students SET password_hash = ?, must_change_password = 1 WHERE id = ?', [password_hash(normalize_initial((string)$stu['admission_no']), PASSWORD_DEFAULT), (int)$stu['id']]);
                    q("UPDATE password_reset_requests SET status = 'done', handled_by = ?, handled_at = ? WHERE student_id = ? AND status = 'open'", [$sid, now(), (int)$stu['id']]);
                    rl_clear('login_name', (string)row('SELECT full_name_key AS k FROM students WHERE id = ?', [(int)$stu['id']])['k']);
                    audit('staff', $sid, 'student_reset', (string)$stu['id']);
                    a_flash('Password reset. The student signs in with their admission number and must then create a new password.');
                }
                a_redirect();
            case 'enrol':
                $p = a_int('programme_id');
                if (a_int('id') && $p && row('SELECT id FROM programmes WHERE id = ?', [$p])) {
                    q('INSERT IGNORE INTO enrolments (student_id, programme_id, enrolled_at) VALUES (?,?,?)', [a_int('id'), $p, now()]);
                    audit('staff', $sid, 'enrol', a_int('id') . ' -> ' . $p);
                    a_flash('Student enrolled.');
                } else { a_flash('Choose a programme.', 'error'); }
                a_redirect();
            case 'unenrol':
                q('DELETE FROM enrolments WHERE student_id = ? AND programme_id = ?', [a_int('id'), a_int('programme_id')]);
                audit('staff', $sid, 'unenrol', a_int('id') . ' -x ' . a_int('programme_id'));
                a_flash('Enrolment removed.');
                a_redirect();
            case 'prog_toggle':
                q('UPDATE programmes SET online_enabled = ? WHERE id = ?', [a_bool('enable'), a_int('id')]);
                audit('staff', $sid, 'prog_toggle', a_int('id') . ' ' . a_bool('enable'));
                a_flash(a_bool('enable') ? 'Programme is now available online.' : 'Programme is no longer available online. Enrolled students lose access until it is enabled again.');
                a_redirect();
            case 'prog_summary':
                q('UPDATE programmes SET online_summary = ? WHERE id = ?', [a_post('summary', 600) ?: null, a_int('id')]);
                a_flash('Programme information saved.');
                a_redirect();
            case 'lesson_add':
            case 'lesson_save':
                $t = a_post('title', 200);
                if ($t === '') { a_flash('Enter a lesson title.', 'error'); a_redirect(); }
                if ($act === 'lesson_add') {
                    q('INSERT INTO lessons (programme_id, title, description, sort_order, published, created_at) VALUES (?,?,?,?,?,?)', [a_int('programme_id'), $t, a_post('description', 5000) ?: null, a_int('sort_order'), a_bool('published'), now()]);
                } else {
                    q('UPDATE lessons SET title = ?, description = ?, sort_order = ?, published = ? WHERE id = ?', [$t, a_post('description', 5000) ?: null, a_int('sort_order'), a_bool('published'), a_int('id')]);
                }
                a_flash('Lesson saved.');
                a_redirect();
            case 'lesson_delete':
                foreach (rows("SELECT stored_name FROM materials WHERE lesson_id = ? AND kind = 'file'", [a_int('id')]) as $m) { @unlink(HX_ROOT . '/storage/materials/' . basename((string)$m['stored_name'])); }
                q('DELETE FROM lessons WHERE id = ?', [a_int('id')]);
                a_flash('Lesson and its materials deleted.');
                a_redirect();
            case 'material_add':
                $kind = a_post('kind', 8);
                $t = a_post('title', 200);
                if ($t === '' || !in_array($kind, ['file', 'video', 'link'], true)) { a_flash('Enter a title and choose a type.', 'error'); a_redirect(); }
                if ($kind === 'file') {
                    [$stored, $orig, $mime, $size] = a_save_upload($_FILES['file'] ?? []);
                    q('INSERT INTO materials (lesson_id, kind, title, stored_name, original_name, mime, size_bytes, published, created_at) VALUES (?,?,?,?,?,?,?,?,?)', [a_int('lesson_id'), 'file', $t, $stored, $orig, $mime, $size, a_bool('published'), now()]);
                } else {
                    $u = a_post('url', 600);
                    if (!a_https_url($u)) { a_flash('The link must start with https://', 'error'); a_redirect(); }
                    q('INSERT INTO materials (lesson_id, kind, title, url, published, created_at) VALUES (?,?,?,?,?,?)', [a_int('lesson_id'), $kind, $t, $u, a_bool('published'), now()]);
                }
                audit('staff', $sid, 'material_add', (string)a_int('lesson_id'));
                a_flash('Material added.');
                a_redirect();
            case 'material_toggle':
                q('UPDATE materials SET published = ? WHERE id = ?', [a_bool('published'), a_int('id')]);
                a_flash('Material updated.');
                a_redirect();
            case 'material_delete':
                $m = row('SELECT stored_name, kind FROM materials WHERE id = ?', [a_int('id')]);
                if ($m && $m['kind'] === 'file' && $m['stored_name']) { @unlink(HX_ROOT . '/storage/materials/' . basename((string)$m['stored_name'])); }
                q('DELETE FROM materials WHERE id = ?', [a_int('id')]);
                a_flash('Material deleted.');
                a_redirect();
            case 'asg_add':
            case 'asg_save':
                $t = a_post('title', 200);
                $due = a_post('due_at', 20);
                $dueSql = $due !== '' && strtotime($due) ? date('Y-m-d H:i:s', strtotime($due)) : null;
                if ($t === '') { a_flash('Enter an assignment title.', 'error'); a_redirect(); }
                if ($act === 'asg_add') {
                    q('INSERT INTO assignments (programme_id, title, instructions, due_at, published, created_at) VALUES (?,?,?,?,?,?)', [a_int('programme_id'), $t, a_post('instructions', 5000) ?: null, $dueSql, a_bool('published'), now()]);
                } else {
                    q('UPDATE assignments SET title = ?, instructions = ?, due_at = ?, published = ? WHERE id = ?', [$t, a_post('instructions', 5000) ?: null, $dueSql, a_bool('published'), a_int('id')]);
                }
                a_flash('Assignment saved.');
                a_redirect();
            case 'asg_delete':
                q('DELETE FROM assignments WHERE id = ?', [a_int('id')]);
                a_flash('Assignment deleted.');
                a_redirect();
            case 'ann_add':
                if (a_post('title', 200) === '' || a_post('body', 5000) === '') { a_flash('Enter a title and a message.', 'error'); a_redirect(); }
                q('INSERT INTO announcements (programme_id, title, body, published, created_at) VALUES (?,?,?,?,?)', [a_int('programme_id') ?: null, a_post('title', 200), a_post('body', 5000), a_bool('published'), now()]);
                a_flash('Announcement saved.');
                a_redirect();
            case 'ann_toggle':
                q('UPDATE announcements SET published = ? WHERE id = ?', [a_bool('published'), a_int('id')]);
                a_flash('Announcement updated.');
                a_redirect();
            case 'ann_delete':
                q('DELETE FROM announcements WHERE id = ?', [a_int('id')]);
                a_flash('Announcement deleted.');
                a_redirect();
            case 'reset_dismiss':
                q("UPDATE password_reset_requests SET status = 'dismissed', handled_by = ?, handled_at = ? WHERE id = ?", [$sid, now(), a_int('id')]);
                a_flash('Request dismissed.');
                a_redirect();
            case 'staff_add':
                if (!$isAdmin) { hx_fail(403, 'forbidden', 'Only administrators can manage staff accounts.'); }
                $u = a_post('username', 60); $pw = (string)($_POST['password'] ?? '');
                if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $u) || a_post('full_name', 150) === '' || strlen($pw) < 10 || !in_array(a_post('role', 8), ['admin', 'editor'], true)) {
                    a_flash('Enter a username (letters, numbers . _ -), a name, a role and a password of at least 10 characters.', 'error'); a_redirect();
                }
                if (row('SELECT id FROM staff WHERE username = ?', [$u])) { a_flash('That username is taken.', 'error'); a_redirect(); }
                q('INSERT INTO staff (username, full_name, password_hash, role, active, created_at) VALUES (?,?,?,?,1,?)', [$u, a_post('full_name', 150), password_hash($pw, PASSWORD_DEFAULT), a_post('role', 8), now()]);
                audit('staff', $sid, 'staff_add', $u);
                a_flash('Staff account created.');
                a_redirect();
            case 'staff_active':
                if (!$isAdmin) { hx_fail(403, 'forbidden', 'Only administrators can manage staff accounts.'); }
                if (a_int('id') === $sid) { a_flash('You cannot deactivate your own account.', 'error'); a_redirect(); }
                q('UPDATE staff SET active = ? WHERE id = ?', [a_bool('active'), a_int('id')]);
                audit('staff', $sid, 'staff_active', a_int('id') . ' ' . a_bool('active'));
                a_flash('Staff account updated.');
                a_redirect();
            case 'account_password':
                $cur = (string)($_POST['current'] ?? ''); $new = (string)($_POST['new'] ?? '');
                if (!password_verify($cur, (string)$staff['password_hash'])) { a_flash('Your current password is not correct.', 'error'); a_redirect(); }
                if (strlen($new) < 10 || $new !== (string)($_POST['confirm'] ?? '')) { a_flash('Use at least 10 characters and repeat the new password exactly.', 'error'); a_redirect(); }
                q('UPDATE staff SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $sid]);
                session_regenerate_id(true);
                audit('staff', $sid, 'account_password');
                a_flash('Password changed.');
                a_redirect();
            default:
                a_flash('Unknown action.', 'error');
                a_redirect();
        }
    } catch (RuntimeException $e) {
        a_flash($e->getMessage(), 'error');
        a_redirect();
    }
}

/* ---------- pages ---------- */

switch ($route) {
    case 'students':
        $qs = trim((string)($_GET['q'] ?? ''));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $where = $qs !== '' ? 'WHERE full_name LIKE ? OR admission_no LIKE ?' : '';
        $params = $qs !== '' ? ['%' . $qs . '%', '%' . $qs . '%'] : [];
        $total = (int)row("SELECT COUNT(*) AS c FROM students $where", $params)['c'];
        $list = rows("SELECT id, admission_no, full_name, status, must_change_password, last_login_at FROM students $where ORDER BY full_name LIMIT 50 OFFSET " . (($page - 1) * 50), $params);
        $b = '<form method="get"><input type="hidden" name="r" value="students"><label>Search by name or admission number<input name="q" value="' . h($qs) . '"></label><button type="submit">Search</button></form>';
        $b .= '<p>' . $total . ' student(s).</p><table><thead><tr><th>Admission no.</th><th>Name</th><th>Status</th><th>First sign-in done</th><th>Last sign-in</th></tr></thead><tbody>';
        foreach ($list as $s) {
            $b .= '<tr><td>' . h($s['admission_no']) . '</td><td><a href="?r=student&amp;id=' . (int)$s['id'] . '">' . h($s['full_name']) . '</a></td><td>' . h($s['status']) . '</td><td>' . ($s['must_change_password'] ? 'No' : 'Yes') . '</td><td>' . a_date($s['last_login_at']) . '</td></tr>';
        }
        $b .= '</tbody></table>';
        if ($total > $page * 50) { $b .= '<p><a href="?r=students&amp;q=' . urlencode($qs) . '&amp;p=' . ($page + 1) . '">Next page</a></p>'; }
        $b .= '<h2>Register a student</h2><p>Use the name and admission number from the official student register. The admission number becomes the initial password.</p>'
            . a_form('student_add') . '<label>Admission number<input name="admission_no" required maxlength="40"></label><label>Full registered name<input name="full_name" required maxlength="150"></label><label>Email (optional)<input name="email" type="email"></label><label>Phone (optional)<input name="phone"></label><button type="submit">Register student</button></form>'
            . '<h2>Import students from CSV</h2><p>First row must be: <code>admission_no,full_name</code> and optionally <code>email,phone</code>. Up to 1000 rows. Duplicate admission numbers are skipped and reported.</p>'
            . a_form('students_import', true) . '<label>CSV file<input type="file" name="csv" accept=".csv,text/csv" required></label><button type="submit">Import</button></form>';
        a_layout('Students', $b, $staff);
    case 'student':
        $s = row('SELECT * FROM students WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
        if (!$s) { a_layout('Student not found', '<p><a href="?r=students">Back to students</a></p>', $staff); }
        $b = '<p><a href="?r=students">All students</a></p><dl><dt>Admission number</dt><dd>' . h($s['admission_no']) . '</dd><dt>Registered name</dt><dd>' . h($s['full_name']) . '</dd><dt>Email</dt><dd>' . h((string)$s['email']) . '</dd><dt>Phone</dt><dd>' . h((string)$s['phone']) . '</dd><dt>Must change password</dt><dd>' . ($s['must_change_password'] ? 'Yes (has not completed first sign-in)' : 'No') . '</dd><dt>Last sign-in</dt><dd>' . a_date($s['last_login_at']) . '</dd></dl>';
        $b .= '<h2>Access</h2>' . a_form('student_status') . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><label>Status<select name="status">';
        foreach (['active', 'suspended', 'withdrawn'] as $st) { $b .= '<option' . ($s['status'] === $st ? ' selected' : '') . '>' . $st . '</option>'; }
        $b .= '</select></label><button type="submit">Save status</button></form><p>Suspended and withdrawn students cannot sign in.</p>'
            . a_form('student_reset') . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><button type="submit">Reset password to admission number</button></form>';
        $b .= '<h2>Enrolments</h2><table><thead><tr><th>Programme</th><th>Online now</th><th></th></tr></thead><tbody>';
        foreach (rows('SELECT p.id, p.name, p.online_enabled FROM enrolments e JOIN programmes p ON p.id = e.programme_id WHERE e.student_id = ? ORDER BY p.name', [(int)$s['id']]) as $p) {
            $b .= '<tr><td>' . h($p['name']) . '</td><td>' . ($p['online_enabled'] ? 'Yes' : 'No') . '</td><td>' . a_form('unenrol') . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><input type="hidden" name="programme_id" value="' . (int)$p['id'] . '"><button type="submit">Remove</button></form></td></tr>';
        }
        $b .= '</tbody></table>' . a_form('enrol') . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><label>Enrol in programme<select name="programme_id">' . a_programme_options() . '</select></label><button type="submit">Enrol student</button></form>';
        a_layout($s['full_name'], $b, $staff);
    case 'programmes':
        $b = '<p>Only programmes marked <strong>available online</strong> appear as available on the website and can be accessed by enrolled students.</p><table><thead><tr><th>Programme</th><th>Online</th><th></th></tr></thead><tbody>';
        $dept = '';
        foreach (rows('SELECT p.id, p.name, p.online_enabled, d.name AS dept FROM programmes p JOIN departments d ON d.id = p.department_id ORDER BY d.sort_order, p.sort_order') as $p) {
            if ($p['dept'] !== $dept) { $dept = $p['dept']; $b .= '<tr class="group"><th colspan="3">' . h($dept) . '</th></tr>'; }
            $b .= '<tr><td><a href="?r=programme&amp;id=' . (int)$p['id'] . '">' . h($p['name']) . '</a></td><td>' . ($p['online_enabled'] ? 'Available online' : 'Not online') . '</td><td>'
                . a_form('prog_toggle') . '<input type="hidden" name="id" value="' . (int)$p['id'] . '"><input type="hidden" name="enable" value="' . ($p['online_enabled'] ? 0 : 1) . '"><button type="submit">' . ($p['online_enabled'] ? 'Disable online' : 'Enable online') . '</button></form></td></tr>';
        }
        a_layout('Programmes', $b . '</tbody></table>', $staff);
    case 'programme':
        $pid = (int)($_GET['id'] ?? 0);
        $p = row('SELECT p.*, d.name AS dept FROM programmes p JOIN departments d ON d.id = p.department_id WHERE p.id = ?', [$pid]);
        if (!$p) { a_layout('Programme not found', '<p><a href="?r=programmes">Back</a></p>', $staff); }
        $cnt = (int)row('SELECT COUNT(*) AS c FROM enrolments WHERE programme_id = ?', [$pid])['c'];
        $b = '<p><a href="?r=programmes">All programmes</a></p><p>' . h($p['dept']) . '. ' . ($p['online_enabled'] ? 'Available online.' : '<strong>Not enabled for online learning.</strong> Students cannot see any content until you enable it on the Programmes page.') . ' ' . $cnt . ' student(s) enrolled.</p>'
            . '<h2>Programme information</h2>' . a_form('prog_summary') . '<input type="hidden" name="id" value="' . $pid . '"><label>Short description shown on the Online Classes page (optional, max 600 characters)<textarea name="summary" maxlength="600" rows="3">' . h((string)$p['online_summary']) . '</textarea></label><button type="submit">Save</button></form>';
        $b .= '<h2>Lessons and materials</h2>';
        foreach (rows('SELECT * FROM lessons WHERE programme_id = ? ORDER BY sort_order, id', [$pid]) as $l) {
            $b .= '<section class="card"><h3>' . h($l['title']) . ($l['published'] ? '' : ' <em>(draft)</em>') . '</h3>' . a_form('lesson_save') . '<input type="hidden" name="id" value="' . (int)$l['id'] . '"><label>Title<input name="title" value="' . h($l['title']) . '" required></label><label>Description<textarea name="description" rows="3">' . h((string)$l['description']) . '</textarea></label><label>Order<input name="sort_order" type="number" value="' . (int)$l['sort_order'] . '"></label><label class="inline"><input type="checkbox" name="published" value="1"' . ($l['published'] ? ' checked' : '') . '> Visible to students</label><button type="submit">Save lesson</button></form>'
                . a_form('lesson_delete') . '<input type="hidden" name="id" value="' . (int)$l['id'] . '"><button type="submit">Delete lesson and its materials</button></form><h4>Materials</h4><ul>';
            foreach (rows('SELECT * FROM materials WHERE lesson_id = ? ORDER BY id', [(int)$l['id']]) as $m) {
                $b .= '<li>' . h($m['kind']) . ': ' . h($m['title']) . ($m['kind'] === 'file' ? ' (' . h((string)$m['original_name']) . ')' : ' (' . h((string)$m['url']) . ')') . ($m['published'] ? '' : ' <em>(hidden)</em>')
                    . a_form('material_toggle') . '<input type="hidden" name="id" value="' . (int)$m['id'] . '"><input type="hidden" name="published" value="' . ($m['published'] ? 0 : 1) . '"><button type="submit">' . ($m['published'] ? 'Hide' : 'Show') . '</button></form>'
                    . a_form('material_delete') . '<input type="hidden" name="id" value="' . (int)$m['id'] . '"><button type="submit">Delete</button></form></li>';
            }
            $b .= '</ul>' . a_form('material_add', true) . '<input type="hidden" name="lesson_id" value="' . (int)$l['id'] . '"><label>Type<select name="kind"><option value="file">Downloadable file</option><option value="video">Video link (https)</option><option value="link">Other link (https)</option></select></label><label>Title<input name="title" required></label><label>File (for downloadable files)<input type="file" name="file"></label><label>Link (for video or link)<input name="url" placeholder="https://"></label><label class="inline"><input type="checkbox" name="published" value="1" checked> Visible to students</label><button type="submit">Add material</button></form></section>';
        }
        $b .= '<h3>Add a lesson</h3>' . a_form('lesson_add') . '<input type="hidden" name="programme_id" value="' . $pid . '"><label>Title<input name="title" required></label><label>Description<textarea name="description" rows="3"></textarea></label><label>Order<input name="sort_order" type="number" value="0"></label><label class="inline"><input type="checkbox" name="published" value="1"> Visible to students</label><button type="submit">Add lesson</button></form>';
        $b .= '<h2>Assignments</h2>';
        foreach (rows('SELECT * FROM assignments WHERE programme_id = ? ORDER BY id', [$pid]) as $a) {
            $b .= '<section class="card">' . a_form('asg_save') . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><label>Title<input name="title" value="' . h($a['title']) . '" required></label><label>Instructions<textarea name="instructions" rows="3">' . h((string)$a['instructions']) . '</textarea></label><label>Due<input name="due_at" type="datetime-local" value="' . ($a['due_at'] ? h(date('Y-m-d\TH:i', strtotime($a['due_at']))) : '') . '"></label><label class="inline"><input type="checkbox" name="published" value="1"' . ($a['published'] ? ' checked' : '') . '> Visible to students</label><button type="submit">Save assignment</button></form>'
                . a_form('asg_delete') . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><button type="submit">Delete</button></form></section>';
        }
        $b .= '<h3>Add an assignment</h3>' . a_form('asg_add') . '<input type="hidden" name="programme_id" value="' . $pid . '"><label>Title<input name="title" required></label><label>Instructions<textarea name="instructions" rows="3"></textarea></label><label>Due<input name="due_at" type="datetime-local"></label><label class="inline"><input type="checkbox" name="published" value="1"> Visible to students</label><button type="submit">Add assignment</button></form>';
        a_layout($p['name'], $b, $staff);
    case 'announcements':
        $b = '<h2>New announcement</h2>' . a_form('ann_add') . '<label>Title<input name="title" required maxlength="200"></label><label>Message<textarea name="body" rows="4" required></textarea></label><label>Audience<select name="programme_id">' . a_programme_options(null, true) . '</select></label><label class="inline"><input type="checkbox" name="published" value="1" checked> Publish now</label><button type="submit">Save announcement</button></form><h2>All announcements</h2><table><thead><tr><th>Date</th><th>Title</th><th>Audience</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach (rows('SELECT a.*, p.name AS pname FROM announcements a LEFT JOIN programmes p ON p.id = a.programme_id ORDER BY a.created_at DESC LIMIT 100') as $a) {
            $b .= '<tr><td>' . a_date($a['created_at']) . '</td><td>' . h($a['title']) . '</td><td>' . h($a['pname'] ?: 'All students') . '</td><td>' . ($a['published'] ? 'Published' : 'Hidden') . '</td><td>'
                . a_form('ann_toggle') . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><input type="hidden" name="published" value="' . ($a['published'] ? 0 : 1) . '"><button type="submit">' . ($a['published'] ? 'Hide' : 'Publish') . '</button></form>'
                . a_form('ann_delete') . '<input type="hidden" name="id" value="' . (int)$a['id'] . '"><button type="submit">Delete</button></form></td></tr>';
        }
        a_layout('Announcements', $b . '</tbody></table>', $staff);
    case 'resets':
        $b = '<p>Verify the student\'s identity (for example by phone, using the number in their record) before resetting. A reset sets the password back to the admission number and forces a new password at the next sign in.</p><table><thead><tr><th>Requested</th><th>Student</th><th>Contact given</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach (rows('SELECT r.*, s.full_name, s.admission_no, s.phone FROM password_reset_requests r JOIN students s ON s.id = r.student_id ORDER BY r.status = \'open\' DESC, r.requested_at DESC LIMIT 100') as $r) {
            $b .= '<tr><td>' . a_date($r['requested_at']) . '</td><td><a href="?r=student&amp;id=' . (int)$r['student_id'] . '">' . h($r['full_name']) . '</a> (' . h($r['admission_no']) . ')<br>Phone on record: ' . h((string)$r['phone']) . '</td><td>' . h((string)$r['contact']) . '</td><td>' . h($r['status']) . '</td><td>';
            if ($r['status'] === 'open') {
                $b .= a_form('student_reset') . '<input type="hidden" name="id" value="' . (int)$r['student_id'] . '"><button type="submit">Reset password</button></form>' . a_form('reset_dismiss') . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><button type="submit">Dismiss</button></form>';
            }
            $b .= '</td></tr>';
        }
        a_layout('Reset requests', $b . '</tbody></table>', $staff);
    case 'enquiries':
        $b = '<table><thead><tr><th>Received</th><th>Type</th><th>Name</th><th>Phone</th><th>Email</th><th>Course</th><th>Message</th></tr></thead><tbody>';
        foreach (rows('SELECT * FROM enquiries ORDER BY id DESC LIMIT 100') as $e) {
            $b .= '<tr><td>' . a_date($e['created_at']) . '</td><td>' . h($e['type']) . '</td><td>' . h($e['name']) . '</td><td>' . h((string)$e['phone']) . '</td><td>' . h((string)$e['email']) . '</td><td>' . h(trim((string)$e['department'] . ' ' . (string)$e['course'])) . '</td><td>' . nl2br(h((string)$e['message'])) . '</td></tr>';
        }
        a_layout('Enquiries', '<p>Messages and applications sent from the website (latest 100).</p>' . $b . '</tbody></table>', $staff);
    case 'staff':
        if (!$isAdmin) { hx_fail(403, 'forbidden', 'Only administrators can manage staff accounts.'); }
        $b = '<table><thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach (rows('SELECT * FROM staff ORDER BY username') as $s) {
            $b .= '<tr><td>' . h($s['username']) . '</td><td>' . h($s['full_name']) . '</td><td>' . h($s['role']) . '</td><td>' . a_date($s['last_login_at']) . '</td><td>' . ($s['active'] ? 'Active' : 'Disabled') . '</td><td>'
                . ((int)$s['id'] !== $sid ? a_form('staff_active') . '<input type="hidden" name="id" value="' . (int)$s['id'] . '"><input type="hidden" name="active" value="' . ($s['active'] ? 0 : 1) . '"><button type="submit">' . ($s['active'] ? 'Disable' : 'Enable') . '</button></form>' : '') . '</td></tr>';
        }
        $b .= '</tbody></table><h2>Add staff account</h2><p>Administrators manage everything including staff. Editors manage students and content. Give each person their own account.</p>' . a_form('staff_add') . '<label>Username<input name="username" required></label><label>Full name<input name="full_name" required></label><label>Role<select name="role"><option value="editor">Editor</option><option value="admin">Administrator</option></select></label><label>Temporary password (min. 10 characters)<input name="password" type="password" autocomplete="new-password" required></label><button type="submit">Create account</button></form>';
        a_layout('Staff', $b, $staff);
    case 'account':
        a_layout('My account', '<p>Signed in as ' . h($staff['username']) . '.</p>' . a_form('account_password') . '<label>Current password<input name="current" type="password" autocomplete="current-password" required></label><label>New password (min. 10 characters)<input name="new" type="password" autocomplete="new-password" required></label><label>Repeat new password<input name="confirm" type="password" autocomplete="new-password" required></label><button type="submit">Change password</button></form>', $staff);
    default:
        $c = static fn(string $sql): int => (int)row($sql)['c'];
        a_layout('Dashboard', '<ul class="stats"><li><strong>' . $c("SELECT COUNT(*) AS c FROM students WHERE status = 'active'") . '</strong> active students</li><li><strong>' . $c('SELECT COUNT(*) AS c FROM enrolments') . '</strong> enrolments</li><li><strong>' . $c('SELECT COUNT(*) AS c FROM programmes WHERE online_enabled = 1') . '</strong> programmes available online</li><li><strong>' . $c("SELECT COUNT(*) AS c FROM password_reset_requests WHERE status = 'open'") . '</strong> open reset requests</li></ul><p>Start with <a href="?r=students">Students</a>, then <a href="?r=programmes">enable programmes</a> and add lessons.</p>', $staff);
}
