<?php
// api/sync/companies_admin.php
header('Content-Type: application/json');

// Both branches are super-admin surface: GET enumerates every company in the ERP, POST rewrites
// another company's sync settings.
require_once __DIR__ . '/_auth.php';
$syncUser = sync_auth();
sync_require_super_admin($syncUser);

$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}
// _auth.php already loaded api/config.php, which exposes db_connect()/erp_connect(). Use those
// instead of rebuilding PDO from $env — they share one credential-handling path with the rest of
// the codebase, so an empty `DB_PASS ?? ''` here can't silently downgrade the connection.
$env = sync_env();

try {
    // 1. Connect to both DBs
    $pdoLocal = db_connect();
    $pdoErp = erp_connect();

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

        if (!$companyId) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit;
        }

        // The old "Security Check" here looked up the role of whatever `user_id` the request
        // carried, which authorised the claim rather than the caller. sync_require_super_admin()
        // above now checks the verified token instead.

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
