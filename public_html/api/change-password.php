<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('POST');
same_origin_check();
$d = api_body();
$s = student_current();
if (!$s) {
    json_out(401, ['ok' => false, 'code' => 'unauthenticated', 'message' => 'Your session has ended. Sign in again.']);
}
csrf_check_header();

$id = (int)$s['id'];
if (rl_count('pw_change', (string)$id, 900) >= 10) {
    json_out(429, ['ok' => false, 'code' => 'rate_limited', 'message' => 'Too many attempts. Try again in 15 minutes.']);
}

$cur = pw_field($d, 'currentPassword');
$new = pw_field($d, 'newPassword', 128);
$conf = pw_field($d, 'confirmPassword', 128);
$try = $s['must_change_password'] ? normalize_initial($cur) : $cur;

if ($cur === '' || !password_verify($try, (string)$s['password_hash'])) {
    rl_hit('pw_change', (string)$id);
    json_out(422, ['ok' => false, 'code' => 'invalid', 'message' => 'Your current password is not correct.',
        'errors' => ['currentPassword' => 'Your current password is not correct.']]);
}

$err = null;
if (strlen($new) < 8) {
    $err = ['newPassword', 'Use at least 8 characters.'];
} elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
    $err = ['newPassword', 'Include at least one letter and one number.'];
} elseif ($new === $cur) {
    $err = ['newPassword', 'Choose a password different from your current one.'];
} elseif (normalize_initial($new) === normalize_initial((string)$s['admission_no']) || name_key($new) === (string)$s['full_name_key']) {
    $err = ['newPassword', 'Do not use your name or admission number as your password.'];
} elseif ($new !== $conf) {
    $err = ['confirmPassword', 'The two new passwords do not match.'];
}
if ($err) {
    json_out(422, ['ok' => false, 'code' => 'invalid', 'message' => $err[1], 'errors' => [$err[0] => $err[1]]]);
}

q('UPDATE students SET password_hash = ?, must_change_password = 0, password_changed_at = ? WHERE id = ?',
    [password_hash($new, PASSWORD_DEFAULT), now(), $id]);
rl_clear('pw_change', (string)$id);
session_regenerate_id(true);
$_SESSION['csrf'] = bin2hex(random_bytes(32));
audit('student', $id, 'password_changed');
json_out(200, ['ok' => true]);
