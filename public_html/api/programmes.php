<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
api_method('GET');

// Public: only the programmes the administration has enabled for online learning.
$list = rows('SELECT slug, online_summary FROM programmes WHERE online_enabled = 1 ORDER BY id');
json_out(200, ['ok' => true, 'programmes' => array_map(
    static fn(array $p): array => ['slug' => $p['slug'], 'summary' => (string)($p['online_summary'] ?? '')],
    $list
)]);
