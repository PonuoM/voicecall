<?php
// api/sync/get_calendar_stats.php
header('Content-Type: application/json');

$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}
$env = parse_ini_file($envPath);

$localDb = [
    'host'     => $env['DB_HOST'] ?? 'localhost',
    'database' => $env['DB_NAME'] ?? 'voicecall_ai',
    'username' => $env['DB_USER'] ?? 'root',
    'password' => $env['DB_PASS'] ?? ''
];

$companyId = $_GET['company_id'] ?? 1;

try {
    $pdo = new PDO(
        "mysql:host={$localDb['host']};dbname={$localDb['database']};charset=utf8mb4", 
        $localDb['username'], 
        $localDb['password'], 
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

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
