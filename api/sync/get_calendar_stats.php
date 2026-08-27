<?php
// api/sync/get_calendar_stats.php
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

try {
    $pdo = db_connect();

    $stmt = $pdo->prepare("SELECT call_date, COUNT(*) as file_count FROM gdrive_file_index WHERE company_id = ? AND call_date IS NOT NULL GROUP BY call_date ORDER BY call_date DESC");
    $stmt->execute([$companyId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [];
    foreach ($results as $row) {
        $stats[$row['call_date']] = (int)$row['file_count'];
    }

    echo json_encode(['success' => true, 'data' => $stats]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
