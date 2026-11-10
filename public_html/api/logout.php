<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('POST');
same_origin_check();
$s = student_current();
csrf_check_header();
if ($s) {
    audit('student', (int)$s['id'], 'logout');
}
session_reset();
json_out(200, ['ok' => true]);
