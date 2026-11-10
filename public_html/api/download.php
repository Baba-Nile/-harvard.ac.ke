<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('GET');

function deny(int $code, string $msg): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

$s = student_current();
if (!$s) {
    deny(401, 'Sign in to download learning materials.');
}
if ($s['must_change_password']) {
    deny(403, 'Create a new password before continuing.');
}
$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    deny(404, 'File not found.');
}

// Authorisation: the file must belong to a published lesson of a programme that is enabled online AND the student is enrolled in.
$m = row("SELECT m.stored_name, m.original_name, m.mime
          FROM materials m
          JOIN lessons l ON l.id = m.lesson_id
          JOIN programmes p ON p.id = l.programme_id
          JOIN enrolments e ON e.programme_id = p.id AND e.student_id = ?
          WHERE m.id = ? AND m.kind = 'file' AND m.published = 1 AND l.published = 1 AND p.online_enabled = 1", [(int)$s['id'], $id]);
if (!$m || !$m['stored_name']) {
    deny(404, 'File not found.');
}
$path = HX_ROOT . '/storage/materials/' . basename((string)$m['stored_name']);
if (!is_file($path) || !is_readable($path)) {
    error_log('[hx] missing material file for id ' . $id);
    deny(404, 'File not found.');
}

audit('student', (int)$s['id'], 'download', 'material ' . $id);
$orig = (string)($m['original_name'] ?: 'download');
$ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $orig);
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . ($m['mime'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($orig));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
