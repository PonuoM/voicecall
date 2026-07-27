<?php
// api/sync/process_single.php
header('Content-Type: application/json');

require_once __DIR__ . '/../Services/OneCallClient.php';
require_once __DIR__ . '/../Services/GoogleDriveUploader.php';
require_once __DIR__ . '/../Services/WavInfo.php';
require_once __DIR__ . '/../Services/GdriveIndexer.php';

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

    // $driveConfig removed, moved lower

// Support both JSON and FormData
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$isJson = !empty($input);

$callId = $isJson ? ($input['record']['id'] ?? '') : ($_POST['id'] ?? '');
if (!$callId) {
    echo json_encode(['success' => false, 'message' => 'Missing call ID']);
    exit;
}

$startTime = $isJson ? (!empty($input['record']['start']) ? $input['record']['start'] : date('Y-m-d H:i:s')) : (!empty($_POST['start']) ? $_POST['start'] : date('Y-m-d H:i:s'));
$caller = $isJson ? (!empty($input['record']['caller']) ? $input['record']['caller'] : '000000000') : (!empty($_POST['caller']) ? $_POST['caller'] : '000000000');
$receiver = $isJson ? (!empty($input['record']['receiver']) ? $input['record']['receiver'] : '000000000') : (!empty($_POST['receiver']) ? $_POST['receiver'] : '000000000');
$direction = $isJson ? (!empty($input['record']['direction']) ? $input['record']['direction'] : 'OUT') : (!empty($_POST['direction']) ? $_POST['direction'] : 'OUT');
$companyId = $isJson ? ($input['company_id'] ?? 1) : ($_POST['company_id'] ?? 1); 
$folderId = $isJson ? ($input['folder_id'] ?? '1DcjFeLhr4Uq2mBA4WcPLRxbqZgiwHKet') : ($_POST['folder_id'] ?? '1DcjFeLhr4Uq2mBA4WcPLRxbqZgiwHKet');
$allowDuplicates = $isJson ? ($input['allow_duplicates'] ?? false) : ($_POST['allow_duplicates'] ?? false);

try {
    // 1. DB connections
    $pdoLocal = new PDO("mysql:host={$localDb['host']};dbname={$localDb['database']};charset=utf8mb4", $localDb['username'], $localDb['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdoErp = new PDO("mysql:host={$erpDb['host']};dbname={$erpDb['database']};charset=utf8mb4", $erpDb['username'], $erpDb['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Check if already indexed
    $stmt = $pdoLocal->prepare("SELECT id FROM gdrive_file_index WHERE company_id = ? AND call_code = ?");
    $stmt->execute([$companyId, $callId]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => true, 'message' => 'Already synced', 'skipped' => true]);
        exit;
    }

    // 2. ERP OneCall Credentials
    $stmt = $pdoErp->prepare("SELECT `key`, `value` FROM env WHERE `key` IN (?, ?)");
    $stmt->execute(["ONECALL_USERNAME_{$companyId}", "ONECALL_PASSWORD_{$companyId}"]);
    $erpEnv = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $oneCallUser = $erpEnv["ONECALL_USERNAME_{$companyId}"] ?? '';
    $oneCallPass = $erpEnv["ONECALL_PASSWORD_{$companyId}"] ?? '';
    if (!$oneCallUser || !$oneCallPass) {
        throw new Exception("OneCall credentials not found in ERP DB for company $companyId");
    }

    $driveConfig = [
        'client_id' => $env['DRIVE_CLIENT_ID'] ?? '',
        'client_secret' => $env['DRIVE_CLIENT_SECRET'] ?? '',
        'refresh_token' => $env['DRIVE_REFRESH_TOKEN'] ?? '',
        'folder_id' => $folderId,
    ];

    $onecallConfig = [
        'base_url' => 'https://onecallvoicerecord.dtac.co.th/',
        'username' => $oneCallUser,
        'password' => $oneCallPass
    ];

    $oneCall = new OneCallClient($onecallConfig['base_url'], $onecallConfig['username'], $onecallConfig['password']);
    $uploader = new GoogleDriveUploader($driveConfig['client_id'], $driveConfig['client_secret'], $driveConfig['refresh_token'], $driveConfig['folder_id']);

    // 3. Prepare metadata
    $dateObj = new DateTime($startTime);
    $dateStr = $dateObj->format('Ymd_His');
    $callDate = $dateObj->format('Y-m-d');
    $callTime = $dateObj->format('H:i:s');
    
    $cleanCaller = preg_replace('/[^0-9]/', '', $caller);
    $cleanReceiver = preg_replace('/[^0-9]/', '', $receiver);
    
    // Thailand prefix enforcement
    if (strpos($cleanCaller, '0') === 0) $cleanCaller = '66' . substr($cleanCaller, 1);
    if (strpos($cleanReceiver, '0') === 0) $cleanReceiver = '66' . substr($cleanReceiver, 1);
    
    $encodedCaller = '%2B' . $cleanCaller;
    $encodedReceiver = '%2B' . $cleanReceiver;
    
    $directionUpper = strtoupper($direction);
    $fileName = "{$dateStr}_{$callId}-{$encodedCaller}-{$encodedReceiver}-{$directionUpper}.wav";
    $tempFile = sys_get_temp_dir() . '/' . $fileName;

    // OneCall stamps its `start` in UTC. The filename deliberately keeps that UTC time so the
    // Drive-walk indexer (GdriveIndexer::parseFilename) reparses it identically and the two paths
    // never disagree — but the stored call_date/call_time columns must be Asia/Bangkok like every
    // other path, or these rows show 7h early in the dashboard and never line up with
    // primacom_mini_erp.call_history (which is local), so CRM matching silently fails. Reuse
    // parseFilename so the +7h shift rule lives in exactly one place.
    // See migrations/008_fix_onecall_timezone.sql and migrations/009_fix_process_single_utc.sql.
    $parsed = GdriveIndexer::parseFilename($fileName);
    if ($parsed && !empty($parsed['call_date']) && !empty($parsed['call_time'])) {
        $callDate = $parsed['call_date'];
        $callTime = $parsed['call_time'];
    }

    // Deduplication Best Practice: Check if file exists in Google Drive by timestamp
    $existingFileId = null;
    if (!$allowDuplicates) {
        $existingFileId = $uploader->checkFileExistsByTimestamp($dateStr);
    }

    $isSkipped = false;

    if ($existingFileId) {
        $driveFileId = $existingFileId;
        $sizeBytes = 0;
        $durationSeconds = null; // no local file to read; COALESCE below keeps any existing value
        $isSkipped = true;
    } else {
        // 4. Download and Upload
        $audioUrl = rtrim($onecallConfig['base_url'], '/') . "/orktrack/rest/mediastream/{$callId}";
        $oneCall->downloadAudio($audioUrl, $tempFile);
        $sizeBytes = filesize($tempFile);
        $durationSeconds = WavInfo::durationSeconds($tempFile); // real duration from the WAV header (OneCall serves PCM, not GSM)

        $driveFileId = $uploader->uploadFile($tempFile, $fileName);
        unlink($tempFile);
    }
    
    // 5. Update Local DB (voicecall_ai.gdrive_file_index)
    $insertStmt = $pdoLocal->prepare("
        INSERT INTO gdrive_file_index 
        (company_folder_id, company_id, gdrive_file_id, filename, call_code, call_date, call_time, caller_phone, receiver_phone, direction, size_bytes, duration_seconds)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        filename=VALUES(filename), call_code=VALUES(call_code), size_bytes=VALUES(size_bytes), duration_seconds=COALESCE(VALUES(duration_seconds), duration_seconds), last_seen_at=CURRENT_TIMESTAMP
    ");
    $insertStmt->execute([
        $driveConfig['folder_id'],
        $companyId,
        $driveFileId,
        $fileName,
        $callId,
        $callDate,
        $callTime,
        '+' . $cleanCaller,
        '+' . $cleanReceiver,
        $directionUpper,
        $sizeBytes,
        $durationSeconds
    ]);
    
    echo json_encode(['success' => true, 'driveFileId' => $driveFileId, 'skipped' => $isSkipped]);

} catch (Exception $e) {
    if (isset($tempFile) && file_exists($tempFile)) {
        unlink($tempFile);
    }
    
    // Optionally log errors in a gdrive_sync_runs or a separate table if needed
    // For now, returning the error to the UI is sufficient for the dashboard tracking
    
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
