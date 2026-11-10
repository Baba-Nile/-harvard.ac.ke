<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('GET');

$s = student_current();
$out = ['ok' => true, 'authenticated' => false, 'csrf' => csrf_token()];
if ($s) {
    $out['authenticated'] = true;
    $out['mustChangePassword'] = (bool)$s['must_change_password'];
    $out['student'] = ['fullName' => $s['full_name'], 'admissionNo' => $s['admission_no']];
}
json_out(200, $out);
