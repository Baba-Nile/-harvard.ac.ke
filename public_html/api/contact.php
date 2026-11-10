<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('POST');
same_origin_check();
$d = api_body();

// Honeypot: real visitors never fill this hidden field.
if (str_field($d, 'website', 100) !== '') {
    json_out(200, ['ok' => true]);
}
$ip = client_ip();
if (rl_count('form_ip', $ip, 3600) >= 5) {
    json_out(429, ['ok' => false, 'code' => 'rate_limited', 'message' => 'Too many messages. Try again later.']);
}

$type = str_field($d, 'type', 20);
if (!in_array($type, ['contact', 'application'], true)) {
    json_out(400, ['ok' => false, 'code' => 'bad_type', 'message' => 'Invalid request.']);
}
$f = [
    'name' => str_field($d, 'name', 150), 'phone' => str_field($d, 'phone', 30), 'email' => str_field($d, 'email', 190),
    'department' => str_field($d, 'department', 120), 'course' => str_field($d, 'course', 160), 'message' => str_field($d, 'message', 4000),
];
$e = [];
if ($f['name'] === '') { $e['name'] = 'Enter your full name.'; }
if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $e['email'] = 'Enter a valid email address.'; }
if ($f['phone'] !== '' && !preg_match('/^\+?[0-9\s-]{9,16}$/', $f['phone'])) { $e['phone'] = 'Enter a valid phone number.'; }
if ($type === 'contact') {
    if ($f['email'] === '') { $e['email'] = 'Enter your email address.'; }
    if ($f['message'] === '') { $e['message'] = 'Enter your message.'; }
} else {
    if ($f['phone'] === '') { $e['phone'] = 'Enter your phone number.'; }
    if ($f['email'] === '') { $e['email'] = 'Enter your email address.'; }
    if ($f['department'] === '') { $e['department'] = 'Choose a department.'; }
    if ($f['course'] === '') { $e['course'] = 'Choose a course.'; }
}
if ($e) {
    json_out(422, ['ok' => false, 'code' => 'invalid', 'message' => 'Check the highlighted fields.', 'errors' => $e]);
}

rl_hit('form_ip', $ip);
q('INSERT INTO enquiries (type, name, phone, email, department, course, message, created_at) VALUES (?,?,?,?,?,?,?,?)',
    [$type, $f['name'], $f['phone'] ?: null, $f['email'] ?: null, $f['department'] ?: null, $f['course'] ?: null, $f['message'] ?: null, now()]);

$to = (string)cfg('mail.enquiries_to', '');
if ($to !== '') {
    $lines = ['Type: ' . $type, 'Name: ' . $f['name'], 'Phone: ' . $f['phone'], 'Email: ' . $f['email']];
    if ($type === 'application') { $lines[] = 'Department: ' . $f['department']; $lines[] = 'Course: ' . $f['course']; }
    $lines[] = '';
    $lines[] = $f['message'];
    send_mail($to, ($type === 'application' ? 'New online application: ' : 'New website message: ') . $f['name'], implode("\n", $lines), $f['email']);
}
json_out(200, ['ok' => true]);
