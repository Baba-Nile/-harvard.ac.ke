<?php
/**
 * Harvard Extention Training College - online learning backend core.
 * Requires PHP 8.0+ with PDO MySQL. Lives OUTSIDE public_html (see docs/BACKEND_AND_DEPLOYMENT.md).
 * Nothing in this file is ever served directly by the web server.
 */
declare(strict_types=1);

define('HX_ROOT', dirname(__DIR__));
mb_internal_encoding('UTF-8');

function hx_is_api(): bool
{
    return str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/');
}

function hx_fail(int $status, string $code, string $message): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    if (hx_is_api()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'code' => $code, 'message' => $message]);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message;
    }
    exit;
}

$hx_cfg_file = HX_ROOT . '/config/config.php';
if (!is_file($hx_cfg_file)) {
    hx_fail(503, 'service_unconfigured', 'The online learning service has not been configured yet.');
}
$GLOBALS['HX_CFG'] = require $hx_cfg_file;

function cfg(string $path, $default = null)
{
    $v = $GLOBALS['HX_CFG'] ?? [];
    foreach (explode('.', $path) as $k) {
        if (!is_array($v) || !array_key_exists($k, $v)) {
            return $default;
        }
        $v = $v[$k];
    }
    return $v;
}

date_default_timezone_set((string)cfg('app.timezone', 'Africa/Nairobi'));
ini_set('display_errors', '0');
ini_set('log_errors', '1');
if (is_dir(HX_ROOT . '/storage/logs') && is_writable(HX_ROOT . '/storage/logs')) {
    ini_set('error_log', HX_ROOT . '/storage/logs/php-error.log');
}

set_exception_handler(function (Throwable $e): void {
    error_log('[hx] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        hx_fail(500, 'server_error', 'Something went wrong on the server. Please try again later.');
    }
});

if (strlen((string)cfg('app.secret', '')) < 32) {
    hx_fail(503, 'service_unconfigured', 'The online learning service has not been configured yet.');
}

/* ---------- small helpers ---------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function hx_require_https(): void
{
    if (cfg('app.require_https', true) && !is_https()) {
        hx_fail(403, 'https_required', 'This service is only available over HTTPS.');
    }
}

/* The IP used for rate limiting. X-Forwarded-For is NOT trusted unless app.trust_proxy is true. */
function client_ip(): string
{
    if (cfg('app.trust_proxy', false) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($parts[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function keyed_hash(string $value): string
{
    return hash_hmac('sha256', $value, (string)cfg('app.secret'));
}

/* Lower-case, trim and collapse whitespace so "  MARY   Wanjiku " matches "Mary Wanjiku". */
function name_key(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s ?? '';
}

/* Initial passwords are admission numbers: ignore case and spaces so "hetc/2026/001 " works until the first change. */
function normalize_initial(string $s): string
{
    return strtoupper((string)preg_replace('/\s+/', '', $s));
}

/* ---------- database ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . cfg('db.host', 'localhost') . ';dbname=' . cfg('db.name', '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)cfg('db.user', ''), (string)cfg('db.pass', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function audit(string $actorType, ?int $actorId, string $action, string $detail = ''): void
{
    try {
        q('INSERT INTO audit_log (actor_type, actor_id, action, detail, ip_hash, created_at) VALUES (?,?,?,?,?,?)',
            [$actorType, $actorId, $action, mb_substr($detail, 0, 400), keyed_hash(client_ip()), now()]);
    } catch (Throwable $e) {
        error_log('[hx] audit failed: ' . $e->getMessage());
    }
}

/* ---------- rate limiting (counts events inside a time window) ---------- */

function rl_count(string $scope, string $value, int $windowSeconds): int
{
    $r = row('SELECT COUNT(*) AS c FROM rate_events WHERE scope = ? AND key_hash = ? AND created_at >= ?',
        [$scope, keyed_hash($value), date('Y-m-d H:i:s', time() - $windowSeconds)]);
    return (int)($r['c'] ?? 0);
}

function rl_hit(string $scope, string $value): void
{
    q('INSERT INTO rate_events (scope, key_hash, created_at) VALUES (?,?,?)', [$scope, keyed_hash($value), now()]);
    if (random_int(1, 60) === 1) {
        q('DELETE FROM rate_events WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    }
}

function rl_clear(string $scope, string $value): void
{
    q('DELETE FROM rate_events WHERE scope = ? AND key_hash = ?', [$scope, keyed_hash($value)]);
}

/* ---------- sessions ---------- */

function hx_session(string $kind): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $staff = ($kind === 'staff');
    session_name($staff ? 'hx_staff' : 'hx_student');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string)((int)cfg('session.absolute_hours', 8) * 3600));
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '50');
    $dir = HX_ROOT . '/storage/sessions';
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $staff ? '/admin' : '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* Wipes the session data and issues a new session id (used on login, logout, expiry). */
function session_reset(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

/* Returns the signed-in student's current database row (fresh on every request) or null. */
function student_current(): ?array
{
    hx_session('student');
    $id = $_SESSION['sid'] ?? null;
    if (!$id) {
        return null;
    }
    $idle = (int)cfg('session.idle_minutes', 30) * 60;
    $abs = (int)cfg('session.absolute_hours', 8) * 3600;
    $t = time();
    if ($t - (int)($_SESSION['seen'] ?? 0) > $idle || $t - (int)($_SESSION['born'] ?? 0) > $abs) {
        session_reset();
        return null;
    }
    $s = row("SELECT * FROM students WHERE id = ? AND status = 'active'", [(int)$id]);
    if (!$s) {
        session_reset();
        return null;
    }
    $_SESSION['seen'] = $t;
    return $s;
}

/* ---------- JSON API helpers ---------- */

function json_out(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_method(string $m): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $m) {
        header('Allow: ' . $m);
        json_out(405, ['ok' => false, 'code' => 'method', 'message' => 'Method not allowed.']);
    }
}

function api_body(): array
{
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        json_out(415, ['ok' => false, 'code' => 'content_type', 'message' => 'Unsupported request.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 65536);
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) {
        json_out(400, ['ok' => false, 'code' => 'bad_json', 'message' => 'Invalid request.']);
    }
    return $d;
}

/* Reject cross-site POSTs: the Origin header, when present, must match this host. */
function same_origin_check(): void
{
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o === '') {
        return;
    }
    $oh = parse_url($o, PHP_URL_HOST);
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!$oh || strcasecmp((string)$oh, (string)$host) !== 0) {
        json_out(403, ['ok' => false, 'code' => 'origin', 'message' => 'Request blocked.']);
    }
}

function csrf_check_header(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $have = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !is_string($have) || $have === '' || !hash_equals($have, $sent)) {
        json_out(403, ['ok' => false, 'code' => 'csrf', 'message' => 'Your page has expired. Reload it and try again.']);
    }
}

/* Trimmed, length-capped text field (truncates). */
function str_field(array $d, string $k, int $max): string
{
    $v = $d[$k] ?? '';
    if (!is_string($v)) {
        return '';
    }
    $v = trim($v);
    return mb_strlen($v) > $max ? mb_substr($v, 0, $max) : $v;
}

/* Password field: never trimmed or altered. Over-long values are rejected as empty. */
function pw_field(array $d, string $k, int $max = 200): string
{
    $v = $d[$k] ?? '';
    return (is_string($v) && strlen($v) <= $max) ? $v : '';
}

/* ---------- mail ---------- */

function send_mail(string $to, string $subject, string $body, ?string $replyTo = null): bool
{
    $from = (string)cfg('mail.from', '');
    if ($from === '' || $to === '') {
        return false;
    }
    $clean = static fn(string $s): string => trim(str_replace(["\r", "\n"], ' ', $s));
    $headers = [
        'From: ' . $clean((string)cfg('mail.from_name', 'Harvard Extention Training College')) . ' <' . $clean($from) . '>',
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
    ];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers[] = 'Reply-To: ' . $replyTo;
    }
    return @mail($to, '=?UTF-8?B?' . base64_encode($clean($subject)) . '?=', $body, implode("\r\n", $headers));
}
