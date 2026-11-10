<?php
/**
 * Creates the FIRST administrator account. Run once from the command line (cPanel > Terminal, or SSH):
 *   php backend/bin/create-admin.php username "Full Name"
 * The password is typed when prompted (it is not stored in shell history or in any file).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

[$self, $user, $name] = array_pad($argv, 3, '');
if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $user) || $name === '') {
    fwrite(STDERR, "Usage: php create-admin.php username \"Full Name\"\n"); exit(1);
}
if (row('SELECT id FROM staff WHERE username = ?', [$user])) { fwrite(STDERR, "That username already exists.\n"); exit(1); }
echo 'Password (min. 10 characters): ';
system('stty -echo 2>/dev/null');
$pw = trim((string)fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";
if (strlen($pw) < 10) { fwrite(STDERR, "Password must be at least 10 characters.\n"); exit(1); }
q('INSERT INTO staff (username, full_name, password_hash, role, active, created_at) VALUES (?,?,?,?,1,?)',
    [$user, $name, password_hash($pw, PASSWORD_DEFAULT), 'admin', now()]);
echo "Administrator '$user' created. Sign in at /admin/\n";
