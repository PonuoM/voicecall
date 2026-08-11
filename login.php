<?php
/**
 * Voice Call Dashboard — Login API
 * ตรวจสอบ username/password จาก database primacom_mini_erp
 *
 * Upload ไปวาง: https://www.prima49.com/voicecall/login.php
 * หรือ run local: php -S localhost:8888 login.php
 */

// Same-origin only. This used to send `Access-Control-Allow-Origin: *`, which let any website
// script a login attempt against this endpoint and read the minted token straight out of the
// JSON response.
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/api/core/env.php';
require_once __DIR__ . '/api/core/session_cookie.php';
load_env(__DIR__ . '/.env');

/** Failed logins allowed per window before the account/IP is locked out. */
const LOGIN_WINDOW_MINUTES = 15;
const LOGIN_MAX_FAILURES_PER_IP = 15;
const LOGIN_MAX_FAILURES_PER_USER = 6;
// Deliberately identical for "no such user" and "wrong password" — the old pair of messages let
// anyone enumerate valid usernames one request at a time.
const LOGIN_BAD_CREDENTIALS = 'username หรือรหัสผ่านไม่ถูกต้อง';

// Read input
$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาใส่ username และ password']);
    exit;
}

$clientIp = substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);

// The voicecall_ai connection is opened up front (it used to be opened only after a successful
// password check) because it now also backs the brute-force throttle. It stays best-effort: if
// this DB is down, login still works, it just goes unthrottled and mints no API token.
$aiConn = null;
try {
    $aiConn = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '12345678',
        getenv('DB_NAME') ?: 'voicecall_ai'
    );
    $aiConn->set_charset('utf8mb4');
} catch (Throwable $e) {
    $aiConn = null;
}

if ($aiConn && login_is_locked_out($aiConn, $username, $clientIp)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'พยายามเข้าสู่ระบบผิดหลายครั้งเกินไป กรุณารอ ' . LOGIN_WINDOW_MINUTES . ' นาทีแล้วลองใหม่'
    ]);
    exit;
}

// Load config (DB credentials kept outside the repo — see config.example.php)
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'message' => 'Server config missing']);
    exit;
}
$config = require $configPath;
$db = $config['db'];

// Database connection
$conn = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
$conn->set_charset($db['charset'] ?? 'utf8mb4');

if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูล']);
    exit;
}

// Query user
$stmt = $conn->prepare("
    SELECT u.id, u.username, u.password, u.first_name, u.last_name,
           u.company_id, u.role_id, u.status,
           c.name as company_name,
           r.name as role_name
    FROM users u
    LEFT JOIN companies c ON c.id = u.company_id
    LEFT JOIN roles r ON r.id = u.role_id
    WHERE u.username = ?
    LIMIT 1
");
$stmt->bind_param('s', $username);
$stmt->execute();
$result = $stmt->get_result();

$user = $result->num_rows === 0 ? null : $result->fetch_assoc();

// NOTE: passwords in primacom_mini_erp.users are still plaintext — the ERP application itself
// reads that column, so switching to password_hash()/password_verify() has to be coordinated
// across both systems and cannot be done from this repo alone. hash_equals() at least removes
// the timing side channel from the comparison here.
$passwordOk = $user !== null && hash_equals((string) $user['password'], (string) $password);

if (!$passwordOk) {
    login_record_attempt($aiConn, $username, $clientIp, false);
    echo json_encode(['success' => false, 'message' => LOGIN_BAD_CREDENTIALS]);
    $conn->close();
    exit;
}

// Check status
if ($user['status'] !== 'active') {
    login_record_attempt($aiConn, $username, $clientIp, false);
    echo json_encode(['success' => false, 'message' => 'บัญชีนี้ถูกปิดใช้งาน']);
    $conn->close();
    exit;
}

login_record_attempt($aiConn, $username, $clientIp, true);

// Mint a bearer token for the AI pipeline API (api/index.php) in the local voicecall_ai DB.
// Additive only — never blocks login if it fails, since the existing dashboard doesn't need it.
$apiToken = null;
$apiTokenExpires = null;
try {
    if (!$aiConn) {
        throw new RuntimeException('voicecall_ai unavailable');
    }
    $apiToken = bin2hex(random_bytes(32));
    // Only the hash is stored: a leaked api_tokens table (or a dump of it) can no longer be
    // replayed as a live session. See migrations/010_auth_hardening.sql.
    $tokenHash = hash('sha256', $apiToken);
    $uid = (int)$user['id'];
    $cid = (int)$user['company_id'];
    $rid = (int)$user['role_id'];
    $isSuperAdmin = in_array($rid, [1, 10, 14]) ? 1 : 0;
    $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
    // expires_at is computed by MySQL, not PHP, on purpose: this host runs PHP in UTC while the
    // MySQL server runs Asia/Bangkok, so a PHP-side strtotime('+7 days') was written 7h behind the
    // NOW() that api/config.php later compares it against — every token silently died 7 hours
    // early (6d17h, not 7d). Same clock on both sides of the comparison now.
    $tokenStmt = $aiConn->prepare('
        INSERT INTO api_tokens (erp_user_id, erp_company_id, erp_role_id, erp_is_super_admin, erp_username, erp_full_name, token_hash, expires_at)
        VALUES (?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL 7 DAY))
    ');
    $tokenStmt->bind_param(
        'iiiisss',
        $uid,
        $cid,
        $rid,
        $isSuperAdmin,
        $user['username'],
        $fullName,
        $tokenHash
    );
    $tokenStmt->execute();

    // Hand the browser the real expiry as a UNIX epoch so index.html can stop replaying a token
    // the server would reject. Epoch (not a datetime string) keeps the comparison immune to the
    // client's own timezone.
    $expStmt = $aiConn->prepare('SELECT UNIX_TIMESTAMP(expires_at) FROM api_tokens WHERE token_hash = ?');
    $expStmt->bind_param('s', $tokenHash);
    $expStmt->execute();
    $expStmt->bind_result($apiTokenExpires);
    $expStmt->fetch();
    $expStmt->close();

    // Media requests can't carry an Authorization header: <audio src> and the download link are
    // plain browser navigations. This HttpOnly cookie is how audio_proxy.php authenticates them
    // without putting the token in a URL (where it would leak via Referer, history and access
    // logs). SameSite=Lax also stops another site from embedding a recording as a subresource.
    set_session_cookie($apiToken, (int) $apiTokenExpires);
} catch (Throwable $e) {
    $apiToken = null; // AI API simply won't be usable this session; dashboard login still succeeds
    $apiTokenExpires = null;
}

if ($aiConn) {
    $aiConn->close();
}

// Success — return user info
echo json_encode([
    'success' => true,
    'user' => [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'name' => trim($user['first_name'] . ' ' . $user['last_name']),
        'company_id' => (int)$user['company_id'],
        'company_name' => $user['company_name'] ?? 'ไม่ระบุ',
        'role_id' => (int)$user['role_id'],
        'role_name' => $user['role_name'] ?? 'ไม่ระบุ',
        'is_super_admin' => in_array((int)$user['role_id'], [1, 10, 14]), // Super Admin, Admin System, CEO
        'api_token' => $apiToken, // bearer token for api/* (AI pipeline) endpoints; null if minting failed
        'api_token_expires_at' => $apiTokenExpires === null ? null : (int)$apiTokenExpires // UNIX epoch
    ]
]);

$conn->close();

// ---------------------------------------------------------------------------

/**
 * True when this username or this IP has burned through its failure budget for the current
 * window. Both are checked: per-user stops one account being ground down from a botnet, per-IP
 * stops one host spraying many usernames.
 */
function login_is_locked_out(mysqli $aiConn, string $username, string $ip): bool
{
    try {
        // The window is a constant, not input — inlined so the placeholders stay on the two
        // values that actually come from the request.
        $stmt = $aiConn->prepare('
            SELECT
                SUM(username = ?) AS user_fails,
                SUM(ip = ?)       AS ip_fails
            FROM login_attempts
            WHERE success = 0
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ' . (int) LOGIN_WINDOW_MINUTES . ' MINUTE)
              AND (username = ? OR ip = ?)
        ');
        $stmt->bind_param('ssss', $username, $ip, $username, $ip);
        $stmt->execute();
        $stmt->bind_result($userFails, $ipFails);
        $stmt->fetch();
        $stmt->close();

        return (int) $userFails >= LOGIN_MAX_FAILURES_PER_USER
            || (int) $ipFails >= LOGIN_MAX_FAILURES_PER_IP;
    } catch (Throwable $e) {
        return false; // never lock everyone out because the throttle table is unhappy
    }
}

/** Records one attempt and opportunistically prunes rows older than the window. */
function login_record_attempt(?mysqli $aiConn, string $username, string $ip, bool $success): void
{
    if (!$aiConn) {
        return;
    }
    try {
        $stmt = $aiConn->prepare('INSERT INTO login_attempts (username, ip, success) VALUES (?,?,?)');
        $ok = $success ? 1 : 0;
        $shortUsername = substr($username, 0, 64);
        $stmt->bind_param('ssi', $shortUsername, $ip, $ok);
        $stmt->execute();
        $stmt->close();

        if (mt_rand(1, 50) === 1) {
            $aiConn->query('DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        }
    } catch (Throwable $e) {
        // throttle bookkeeping must never break login
    }
}
