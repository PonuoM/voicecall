<?php
// api/sync/process_single.php
header('Content-Type: application/json');

require_once __DIR__ . '/../Services/OneCallClient.php';
require_once __DIR__ . '/../Services/GoogleDriveUploader.php';

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) {
    echo json_encode(['success' => false, 'message' => 'Config file not found.']);
    exit;
}

$config = require $configPath;
$dbConfig = $config['db'] ?? [];
$driveConfig = $config['google_drive'] ?? [];
$onecallConfig = $config['onecall'] ?? [];

$callId = $_POST['id'] ?? '';
if (!$callId) {
    echo json_encode(['success' => false, 'message' => 'Missing call ID']);
    exit;
}

$startTime = $_POST['start'] ?? date('Y-m-d H:i:s');
$caller = $_POST['caller'] ?? '000000000';
$receiver = $_POST['receiver'] ?? '000000000';
$direction = $_POST['direction'] ?? 'OUT';

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Double check if already synced to avoid race conditions
    $stmt = $pdo->prepare("SELECT status FROM sync_logs WHERE call_id = ?");
    $stmt->execute([$callId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && $row['status'] === 'synced') {
        echo json_encode(['success' => true, 'message' => 'Already synced', 'skipped' => true]);
        exit;
    }

    $oneCall = new OneCallClient($onecallConfig['base_url'], $onecallConfig['username'], $onecallConfig['password']);
    $uploader = new GoogleDriveUploader($driveConfig['service_account_json'], $driveConfig['folder_id']);

    // Build the filename using the PBX standard rules
    $dateObj = new DateTime($startTime);
    $dateStr = $dateObj->format('Ymd_His');
    
    $encodedCaller = '%2B' . preg_replace('/[^0-9]/', '', $caller);
    $encodedReceiver = '%2B' . preg_replace('/[^0-9]/', '', $receiver);
    
    // Thailand prefix enforcement
    if (strpos($encodedCaller, '%2B0') === 0) $encodedCaller = str_replace('%2B0', '%2B66', $encodedCaller);
    if (strpos($encodedReceiver, '%2B0') === 0) $encodedReceiver = str_replace('%2B0', '%2B66', $encodedReceiver);
    
    $fileName = "{$dateStr}_{$callId}-{$encodedCaller}-{$encodedReceiver}-" . strtoupper($direction) . ".wav";
    
    $tempFile = sys_get_temp_dir() . '/' . $fileName;
    
    // Direct audio rest endpoint
    $audioUrl = rtrim($onecallConfig['base_url'], '/') . "/onecall/orktrack/rest/recordings/{$callId}/audio";
    
    // 1. Download
    $oneCall->downloadAudio($audioUrl, $tempFile);
    
    // 2. Upload
    $driveFileId = $uploader->uploadFile($tempFile, $fileName);
    
    // 3. Cleanup temp file
    unlink($tempFile);
    
    // 4. Update Database
    $stmt = $pdo->prepare("INSERT INTO sync_logs (call_id, drive_file_id, status) VALUES (?, ?, 'synced') ON DUPLICATE KEY UPDATE drive_file_id=?, status='synced', error_message=NULL");
    $stmt->execute([$callId, $driveFileId, $driveFileId]);
    
    echo json_encode(['success' => true, 'driveFileId' => $driveFileId]);

} catch (Exception $e) {
    if (isset($tempFile) && file_exists($tempFile)) {
        unlink($tempFile);
    }

    if (isset($pdo) && $callId) {
        $stmt = $pdo->prepare("INSERT INTO sync_logs (call_id, status, error_message) VALUES (?, 'failed', ?) ON DUPLICATE KEY UPDATE status='failed', error_message=?");
        $stmt->execute([$callId, $e->getMessage(), $e->getMessage()]);
    }
    
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
