<?php
// api/sync/get_pending_list.php
header('Content-Type: application/json');

require_once __DIR__ . '/../Services/OneCallClient.php';
$configPath = __DIR__ . '/../../config.php';

if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'message' => 'Config file not found.']);
    exit;
}

$config = require $configPath;
$dbConfig = $config['db'] ?? [];
$onecallConfig = $config['onecall'] ?? [];

$dateStart = $_GET['start_date'] ?? '';
$dateEnd = $_GET['end_date'] ?? '';

if (!$dateStart || !$dateEnd) {
    echo json_encode(['success' => false, 'message' => 'Missing start_date or end_date']);
    exit;
}

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $oneCall = new OneCallClient($onecallConfig['base_url'], $onecallConfig['username'], $onecallConfig['password']);
    
    // Fetch all recordings for this date range from OneCall
    $recordingsData = $oneCall->getRecordings($dateStart, $dateEnd);
    $recordings = $recordingsData['recordings'] ?? [];
    
    // Fetch all already synced IDs from DB
    $stmt = $pdo->prepare("SELECT call_id FROM sync_logs WHERE status = 'synced'");
    $stmt->execute();
    $syncedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $syncedIdsSet = array_flip($syncedIds); // O(1) lookup
    
    $pendingList = [];
    foreach ($recordings as $rec) {
        $callId = $rec['id'] ?? null;
        if (!$callId) continue;
        
        // Only include if NOT already synced
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
