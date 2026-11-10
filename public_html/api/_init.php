<?php
// Shared bootstrap for every API endpoint. Not callable directly (blocked in api/.htaccess).
declare(strict_types=1);
$hxBackend = getenv('HX_BACKEND') ?: dirname(__DIR__, 2) . '/backend';
require $hxBackend . '/src/bootstrap.php';
hx_require_https();
