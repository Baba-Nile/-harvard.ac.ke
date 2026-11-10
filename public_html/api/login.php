<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('POST');
same_origin_check();
$d = api_body();
student_current();          // starts the session
csrf_check_header();

$name = str_field($d, 'fullName', 150);
$pw = pw_field($d, 'password', 200);
if ($name === '' || $pw === '') {
    json_out(422, ['ok' => false, 'code' => 'missing', 'message' => 'Enter your full name and your admission number or password.']);
}

$key = name_key($name);
$ip = client_ip();
$window = 900; // 15 minutes
if (rl_count('login_ip', $ip, $window) >= (int)cfg('limits.login_ip', 20)
    || rl_count('login_name', $key, $window) >= (int)cfg('limits.login_name', 6)) {
    audit('system', null, 'login_blocked', '');
    json_out(429, ['ok' => false, 'code' => 'rate_limited', 'message' => 'Too many unsuccessful attempts. Try again in 15 minutes.']);
}

// Verify the registered name AND the password against the official student record.
$candidates = rows("SELECT * FROM students WHERE full_name_key = ? AND status = 'active' LIMIT 10", [$key]);
$found = null;
$tried = '';
foreach ($candidates as $c) {
    $try = $c['must_change_password'] ? normalize_initial($pw) : $pw;
    if (password_verify($try, (string)$c['password_hash'])) {
        $found = $c;
        $tried = $try;
        break;
    }
}
if (!$candidates) {
    password_hash($pw, PASSWORD_DEFAULT);   // spend comparable time so unknown names are not distinguishable by speed
}

if (!$found) {
    rl_hit('login_ip', $ip);
    rl_hit('login_name', $key);
    audit('system', null, 'login_failed', '');
    json_out(401, ['ok' => false, 'code' => 'invalid_credentials',
        'message' => 'The name or admission number is incorrect. Use your name exactly as it was registered.']);
}

rl_clear('login_name', $key);
session_reset();                                   // new session id: prevents session fixation
$_SESSION['sid'] = (int)$found['id'];
$_SESSION['born'] = time();
$_SESSION['seen'] = time();
$_SESSION['csrf'] = bin2hex(random_bytes(32));

if (password_needs_rehash((string)$found['password_hash'], PASSWORD_DEFAULT)) {
    q('UPDATE students SET password_hash = ? WHERE id = ?', [password_hash($tried, PASSWORD_DEFAULT), (int)$found['id']]);
}
q('UPDATE students SET last_login_at = ? WHERE id = ?', [now(), (int)$found['id']]);
audit('student', (int)$found['id'], 'login');

json_out(200, ['ok' => true, 'mustChangePassword' => (bool)$found['must_change_password']]);
