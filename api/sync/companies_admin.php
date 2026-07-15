<?php
// api/sync/companies_admin.php
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

$erpDb = [
    'host'     => $env['ERP_DB_HOST'] ?? 'localhost',
    'database' => $env['ERP_DB_NAME'] ?? 'primacom_mini_erp',
    'username' => $env['ERP_DB_USER'] ?? 'root',
    'password' => $env['ERP_DB_PASS'] ?? ''
];

try {
    // 1. Connect to both DBs
    $pdoLocal = new PDO("mysql:host={$localDb['host']};dbname={$localDb['database']};charset=utf8mb4", $localDb['username'], $localDb['password']);
    $pdoLocal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdoErp = new PDO("mysql:host={$erpDb['host']};dbname={$erpDb['database']};charset=utf8mb4", $erpDb['username'], $erpDb['password']);
    $pdoErp->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 2. Auto-migrate table
    $pdoLocal->exec("
        CREATE TABLE IF NOT EXISTS company_sync_settings (
            company_id INT PRIMARY KEY,
            prevent_duplicate TINYINT(1) DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        // Fetch all companies from ERP
        $stmtErp = $pdoErp->query("SELECT id, name FROM companies ORDER BY name ASC");
        $companies = $stmtErp->fetchAll(PDO::FETCH_ASSOC);

        // Fetch all settings from Local
        $stmtLocal = $pdoLocal->query("SELECT company_id, prevent_duplicate FROM company_sync_settings");
        $settingsRaw = $stmtLocal->fetchAll(PDO::FETCH_ASSOC);
        
        $settingsMap = [];
        foreach ($settingsRaw as $row) {
            $settingsMap[$row['company_id']] = (int)$row['prevent_duplicate'];
        }

        // Merge
        $result = [];
        foreach ($companies as $c) {
            $cid = (int)$c['id'];
            $result[] = [
                'id' => $cid,
                'name' => $c['name'],
                'prevent_duplicate' => isset($settingsMap[$cid]) ? $settingsMap[$cid] : 1 // Default to 1 (true)
            ];
        }

        echo json_encode(['success' => true, 'data' => $result]);
        exit;
    } 
    
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $companyId = (int)($input['company_id'] ?? 0);
        $preventDuplicate = (int)($input['prevent_duplicate'] ?? 1);
        $userId = (int)($input['user_id'] ?? 0); // User performing the action

        if (!$companyId || !$userId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        // Security Check: Is user a Super Admin in ERP?
        $stmtUser = $pdoErp->prepare("SELECT role_id FROM users WHERE id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || !in_array((int)$user['role_id'], [1, 10, 14])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized. Super Admin only.']);
            exit;
        }

        // Upsert setting
        $stmtInsert = $pdoLocal->prepare("
            INSERT INTO company_sync_settings (company_id, prevent_duplicate)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE prevent_duplicate = VALUES(prevent_duplicate)
        ");
        $stmtInsert->execute([$companyId, $preventDuplicate]);

        echo json_encode(['success' => true]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
