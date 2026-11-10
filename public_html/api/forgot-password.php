<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('POST');
same_origin_check();
$d = api_body();
student_current();
csrf_check_header();

$ip = client_ip();
if (rl_count('reset_ip', $ip, 3600) >= 5) {
    json_out(429, ['ok' => false, 'code' => 'rate_limited', 'message' => 'Too many requests. Try again later.']);
}
rl_hit('reset_ip', $ip);

$name = str_field($d, 'fullName', 150);
$adm = str_field($d, 'admissionNo', 40);
$contact = str_field($d, 'contact', 190);
if ($name === '' || $adm === '') {
    json_out(422, ['ok' => false, 'code' => 'missing', 'message' => 'Enter your full name and admission number.']);
}

// The answer is identical whether or not a record matches, so the form cannot be used to discover who is registered.
$s = row("SELECT id, full_name FROM students WHERE full_name_key = ? AND UPPER(REPLACE(admission_no, ' ', '')) = ? AND status = 'active'",
    [name_key($name), normalize_initial($adm)]);
if ($s) {
    $open = row("SELECT id FROM password_reset_requests WHERE student_id = ? AND status = 'open' AND requested_at >= ?",
        [(int)$s['id'], date('Y-m-d H:i:s', time() - 86400)]);
    if (!$open) {
        q('INSERT INTO password_reset_requests (student_id, contact, status, requested_at) VALUES (?,?,?,?)',
            [(int)$s['id'], $contact !== '' ? $contact : null, 'open', now()]);
        audit('student', (int)$s['id'], 'reset_requested');
        $to = (string)cfg('mail.notify_to', '');
        if ($to !== '') {
            send_mail($to, 'Student password reset requested',
                "A student has asked for a password reset.\n\nName: " . $s['full_name'] . "\nContact given: " . ($contact !== '' ? $contact : '(none)') .
                "\n\nVerify the student's identity, then handle the request in the admin panel (Reset requests).");
        }
    }
}
json_out(200, ['ok' => true]);
