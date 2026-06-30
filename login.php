<?php
/**
 * Voice Call Dashboard — Login API
 * ตรวจสอบ username/password จาก database primacom_mini_erp
 * 
 * Upload ไปวาง: https://www.prima49.com/voicecall/login.php
 * หรือ run local: php -S localhost:8888 login.php
 */

// CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Read input
$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาใส่ username และ password']);
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

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'ไม่พบ username นี้ในระบบ']);
    $conn->close();
    exit;
}

$user = $result->fetch_assoc();

// Check password (plaintext comparison as stored in DB)
if ($user['password'] !== $password) {
    echo json_encode(['success' => false, 'message' => 'รหัสผ่านไม่ถูกต้อง']);
    $conn->close();
    exit;
}

// Check status
if ($user['status'] !== 'active') {
    echo json_encode(['success' => false, 'message' => 'บัญชีนี้ถูกปิดใช้งาน']);
    $conn->close();
    exit;
}

// Mint a bearer token for the AI pipeline API (api/index.php) in the local voicecall_ai DB.
// Additive only — never blocks login if it fails, since the existing dashboard doesn't need it.
$apiToken = null;
try {
    require_once __DIR__ . '/api/core/env.php';
    load_env(__DIR__ . '/.env');
    $aiConn = new mysqli(
        getenv('DB_HOST') ?: 'localhost',
        getenv('DB_USER') ?: 'root',
        getenv('DB_PASS') ?: '12345678',
        getenv('DB_NAME') ?: 'voicecall_ai'
    );
    $aiConn->set_charset('utf8mb4');
    $apiToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    $uid = (int)$user['id'];
    $cid = (int)$user['company_id'];
    $rid = (int)$user['role_id'];
    $isSuperAdmin = in_array($rid, [1, 10, 14]) ? 1 : 0;
    $fullName = trim($user['first_name'] . ' ' . $user['last_name']);
    $tokenStmt = $aiConn->prepare('
        INSERT INTO api_tokens (erp_user_id, erp_company_id, erp_role_id, erp_is_super_admin, erp_username, erp_full_name, token, expires_at)
        VALUES (?,?,?,?,?,?,?,?)
    ');
    $tokenStmt->bind_param(
        'iiiissss',
        $uid,
        $cid,
        $rid,
        $isSuperAdmin,
        $user['username'],
        $fullName,
        $apiToken,
        $expiresAt
    );
    $tokenStmt->execute();
    $aiConn->close();
} catch (Throwable $e) {
    $apiToken = null; // AI API simply won't be usable this session; dashboard login still succeeds
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
        'api_token' => $apiToken // bearer token for api/* (AI pipeline) endpoints; null if minting failed
    ]
]);

$conn->close();
