<?php
// api/sync/settings.php
header('Content-Type: application/json');

require_once __DIR__ . '/_auth.php';
$syncUser = sync_auth();

$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}
// _auth.php already loaded api/config.php → db_connect() handles credentials.
$env = sync_env();

$companyId = sync_company_id($syncUser, $_GET['company_id'] ?? null);

if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Missing company_id']);
    exit;
}

try {
    $pdoLocal = db_connect();

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
