<?php
// Administration panel entry point. All logic lives in the private backend folder.
declare(strict_types=1);
$hxBackend = getenv('HX_BACKEND') ?: dirname(__DIR__, 2) . '/backend';
require $hxBackend . '/src/bootstrap.php';
require $hxBackend . '/src/admin_app.php';
