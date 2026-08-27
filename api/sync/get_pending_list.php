<?php
// api/sync/get_pending_list.php
header('Content-Type: application/json');

require_once __DIR__ . '/_auth.php';
$syncUser = sync_auth();

require_once __DIR__ . '/../Services/OneCallClient.php';
$envPath = __DIR__ . '/../../.env';

if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}

// _auth.php already loaded api/config.php → db_connect()/erp_connect() handle credentials.
$env = sync_env();

$dateStart = $_GET['start_date'] ?? '';
$dateEnd = $_GET['end_date'] ?? '';

if (!$dateStart || !$dateEnd) {
    echo json_encode(['success' => false, 'message' => 'Missing start_date or end_date']);
    exit;
}

$companyId = sync_company_id($syncUser, $_GET['company_id'] ?? null);

try {
    // 1. Connect to both DBs
    $pdoLocal = db_connect();
    $pdoErp = erp_connect();

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
    // OneCall stores data in UTC, so we must subtract 7 hours from the local time bounds
    $startDateTime = DateTime::createFromFormat('Ymd_His', $dateStart);
    if ($startDateTime) {
        $startDateTime->modify('-7 hours');
        $dateStart = $startDateTime->format('Ymd_His');
    }
    
    $endDateTime = DateTime::createFromFormat('Ymd_His', $dateEnd);
    if ($endDateTime) {
        $endDateTime->modify('-7 hours');
        $dateEnd = $endDateTime->format('Ymd_His');
    }

    $recordingsData = $oneCall->getRecordings($dateStart, $dateEnd);
    
    // Auto-detect the recordings array structure
    $recordings = [];
    if (isset($recordingsData['objects']) && is_array($recordingsData['objects'])) {
        $recordings = $recordingsData['objects']; // DTAC returns the array in 'objects'
    } elseif (isset($recordingsData['recordings']) && is_array($recordingsData['recordings'])) {
        $recordings = $recordingsData['recordings'];
    } elseif (isset($recordingsData['data']) && is_array($recordingsData['data'])) {
        $recordings = $recordingsData['data'];
    } elseif (isset($recordingsData['recording']) && is_array($recordingsData['recording'])) {
        $recordings = $recordingsData['recording'];
    } elseif (is_array($recordingsData) && !isset($recordingsData['success']) && !isset($recordingsData['recordings']) && !isset($recordingsData['objects'])) {
        // Fallback for direct array, but exclude associative objects
        if (array_keys($recordingsData) === range(0, count($recordingsData) - 1)) {
            $recordings = $recordingsData;
        }
    }
    
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
                'start' => $rec['timestamp'] ?? $rec['start'] ?? '',
                'caller' => $rec['localParty'] ?? $rec['caller'] ?? $rec['from'] ?? '',
                'receiver' => $rec['remoteParty'] ?? $rec['receiver'] ?? $rec['to'] ?? '',
                'direction' => $rec['direction'] ?? 'OUT'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'count' => count($pendingList),
        'total_fetched' => count($recordings),
        'data' => $pendingList,
        'debug_raw' => $recordingsData // added for debugging
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
