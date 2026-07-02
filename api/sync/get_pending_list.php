<?php
// api/sync/get_pending_list.php
header('Content-Type: application/json');

require_once __DIR__ . '/../Services/OneCallClient.php';
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

$erpDb = [
    'host'     => $env['ERP_DB_HOST'] ?? 'localhost',
    'database' => $env['ERP_DB_NAME'] ?? 'primacom_mini_erp',
    'username' => $env['ERP_DB_USER'] ?? 'root',
    'password' => $env['ERP_DB_PASS'] ?? ''
];

$dateStart = $_GET['start_date'] ?? '';
$dateEnd = $_GET['end_date'] ?? '';

if (!$dateStart || !$dateEnd) {
    echo json_encode(['success' => false, 'message' => 'Missing start_date or end_date']);
    exit;
}

$companyId = 1; // Default to 1 based on planning

try {
    // 1. Connect to both DBs
    $pdoLocal = new PDO("mysql:host={$localDb['host']};dbname={$localDb['database']};charset=utf8mb4", $localDb['username'], $localDb['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoErp = new PDO("mysql:host={$erpDb['host']};dbname={$erpDb['database']};charset=utf8mb4", $erpDb['username'], $erpDb['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 2. Fetch OneCall Credentials from ERP DB
    $stmt = $pdoErp->prepare("SELECT `key`, `value` FROM env WHERE `key` IN (?, ?)");
    $stmt->execute(["ONECALL_USERNAME_{$companyId}", "ONECALL_PASSWORD_{$companyId}"]);
    $erpEnv = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $oneCallUser = $erpEnv["ONECALL_USERNAME_{$companyId}"] ?? '';
    $oneCallPass = $erpEnv["ONECALL_PASSWORD_{$companyId}"] ?? '';

    if (!$oneCallUser || !$oneCallPass) {
        throw new Exception("OneCall credentials not found in ERP DB for company $companyId");
    }

    $onecallConfig = [
        'base_url' => 'https://onecallvoicerecord.dtac.co.th/',
        'username' => $oneCallUser,
        'password' => $oneCallPass
    ];

    $oneCall = new OneCallClient($onecallConfig['base_url'], $onecallConfig['username'], $onecallConfig['password']);
    
    // 3. Fetch recordings from OneCall
    $recordingsData = $oneCall->getRecordings($dateStart, $dateEnd);
    $recordings = $recordingsData['recordings'] ?? [];
    
    // 4. Check local gdrive_file_index for existing call_codes
    $stmt = $pdoLocal->prepare("SELECT call_code FROM gdrive_file_index WHERE company_id = ? AND call_code IS NOT NULL");
    $stmt->execute([$companyId]);
    $syncedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $syncedIdsSet = array_flip($syncedIds); 
    
    // 5. Filter out synced
    $pendingList = [];
    foreach ($recordings as $rec) {
        $callId = $rec['id'] ?? null;
        if (!$callId) continue;
        
        if (!isset($syncedIdsSet[$callId])) {
            $pendingList[] = [
                'id' => $callId,
                'start' => $rec['start'] ?? '',
                'caller' => $rec['caller'] ?? $rec['from'] ?? '',
                'receiver' => $rec['receiver'] ?? $rec['to'] ?? '',
                'direction' => $rec['direction'] ?? 'OUT'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'count' => count($pendingList),
        'total_fetched' => count($recordings),
        'data' => $pendingList
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
