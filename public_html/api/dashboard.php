<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('GET');

$s = student_current();
if (!$s) {
    json_out(401, ['ok' => false, 'code' => 'unauthenticated', 'message' => 'Your session has ended. Sign in again.']);
}
if ($s['must_change_password']) {
    json_out(403, ['ok' => false, 'code' => 'password_change_required', 'message' => 'Create a new password before continuing.']);
}

$sid = (int)$s['id'];
// Only programmes the student is enrolled in AND that are currently enabled for online learning.
$progs = rows('SELECT p.id, p.slug, p.name, d.name AS department
               FROM enrolments e JOIN programmes p ON p.id = e.programme_id JOIN departments d ON d.id = p.department_id
               WHERE e.student_id = ? AND p.online_enabled = 1 ORDER BY d.sort_order, p.sort_order', [$sid]);
$ids = array_map(static fn(array $p): int => (int)$p['id'], $progs);
$in = $ids ? implode(',', array_fill(0, count($ids), '?')) : '';

$lessonsBy = [];
$assignBy = [];
if ($ids) {
    $lessons = rows("SELECT id, programme_id, title, description FROM lessons WHERE programme_id IN ($in) AND published = 1 ORDER BY sort_order, id", $ids);
    $lids = array_map(static fn(array $l): int => (int)$l['id'], $lessons);
    $matsBy = [];
    if ($lids) {
        $lin = implode(',', array_fill(0, count($lids), '?'));
        foreach (rows("SELECT id, lesson_id, kind, title, original_name, size_bytes, url FROM materials WHERE lesson_id IN ($lin) AND published = 1 ORDER BY id", $lids) as $m) {
            $matsBy[(int)$m['lesson_id']][] = [
                'id' => (int)$m['id'], 'kind' => $m['kind'], 'title' => $m['title'],
                'fileName' => $m['kind'] === 'file' ? $m['original_name'] : null,
                'size' => $m['kind'] === 'file' ? (int)$m['size_bytes'] : null,
                'url' => $m['kind'] === 'file' ? null : $m['url'],
            ];
        }
    }
    foreach ($lessons as $l) {
        $lessonsBy[(int)$l['programme_id']][] = ['title' => $l['title'], 'description' => (string)($l['description'] ?? ''), 'materials' => $matsBy[(int)$l['id']] ?? []];
    }
    foreach (rows("SELECT programme_id, title, instructions, due_at FROM assignments WHERE programme_id IN ($in) AND published = 1 ORDER BY due_at IS NULL, due_at, id", $ids) as $a) {
        $assignBy[(int)$a['programme_id']][] = ['title' => $a['title'], 'instructions' => (string)($a['instructions'] ?? ''), 'dueAt' => $a['due_at']];
    }
}

$news = $ids
    ? rows("SELECT title, body, created_at FROM announcements WHERE published = 1 AND (programme_id IS NULL OR programme_id IN ($in)) ORDER BY created_at DESC LIMIT 20", $ids)
    : rows('SELECT title, body, created_at FROM announcements WHERE published = 1 AND programme_id IS NULL ORDER BY created_at DESC LIMIT 20');

json_out(200, [
    'ok' => true,
    'student' => ['fullName' => $s['full_name'], 'admissionNo' => $s['admission_no']],
    'idleMinutes' => (int)cfg('session.idle_minutes', 30),
    'programmes' => array_map(static fn(array $p): array => [
        'slug' => $p['slug'], 'name' => $p['name'], 'department' => $p['department'],
        'lessons' => $lessonsBy[(int)$p['id']] ?? [], 'assignments' => $assignBy[(int)$p['id']] ?? [],
    ], $progs),
    'announcements' => array_map(static fn(array $n): array => ['title' => $n['title'], 'body' => $n['body'], 'postedAt' => $n['created_at']], $news),
]);
