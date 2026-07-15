<?php
// api/sync/settings.php
header('Content-Type: application/json');

$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}
$env = parse_ini_file($envPath);

$localDb = [
    'host'     => $env['DB_HOST'] ?? 'localhost',
    'database' => $env['DB_NAME'] ?? 'primacom_voicelog',
    'username' => $env['DB_USER'] ?? 'root',
    'password' => $env['DB_PASS'] ?? ''
];

$companyId = (int)($_GET['company_id'] ?? 0);

if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Missing company_id']);
    exit;
}

try {
    $pdoLocal = new PDO("mysql:host={$localDb['host']};dbname={$localDb['database']};charset=utf8mb4", $localDb['username'], $localDb['password']);
    $pdoLocal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Ensure table exists (just in case this runs before admin visits the page)
    $pdoLocal->exec("
        CREATE TABLE IF NOT EXISTS company_sync_settings (
            company_id INT PRIMARY KEY,
            prevent_duplicate TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $stmt = $pdoLocal->prepare("SELECT prevent_duplicate FROM company_sync_settings WHERE company_id = ?");
    $stmt->execute([$companyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $preventDuplicate = $row ? (bool)$row['prevent_duplicate'] : true; // Default to true

    echo json_encode(['success' => true, 'prevent_duplicate' => $preventDuplicate]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
