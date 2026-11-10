<?php
/** Verifies the installation from the command line: php backend/bin/check-setup.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
$ok = true;
$say = function (bool $pass, string $msg) use (&$ok) { echo ($pass ? '[ok]   ' : '[FAIL] ') . $msg . "\n"; $ok = $ok && $pass; };
$say(PHP_VERSION_ID >= 80000, 'PHP 8.0 or newer (running ' . PHP_VERSION . ')');
$say(extension_loaded('pdo_mysql'), 'pdo_mysql extension');
$say(extension_loaded('mbstring'), 'mbstring extension');
try { $n = (int)row('SELECT COUNT(*) AS c FROM programmes')['c']; $say($n === 50, "database reachable; programmes table has $n rows (expected 50)"); }
catch (Throwable $e) { $say(false, 'database: ' . $e->getMessage()); }
foreach (['storage/materials', 'storage/sessions', 'storage/logs'] as $d) { $say(is_dir(HX_ROOT . "/$d") && is_writable(HX_ROOT . "/$d"), "$d is writable"); }
$say(!str_contains(realpath(HX_ROOT) ?: '', 'public_html'), 'backend folder is outside public_html');
$say((bool)cfg('app.require_https', true), 'HTTPS is required (app.require_https)');
$say((string)cfg('mail.from', '') !== '', 'mail.from is set (needed for email notifications)');
try { $a = (int)row("SELECT COUNT(*) AS c FROM staff WHERE role = 'admin' AND active = 1")['c']; $say($a > 0, "$a active administrator account(s)"); } catch (Throwable $e) { $say(false, 'staff table: ' . $e->getMessage()); }
echo $ok ? "\nAll checks passed.\n" : "\nSome checks failed. Fix them before going live.\n";
exit($ok ? 0 : 1);
