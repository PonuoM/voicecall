<?php
// cron/sync_onecall_to_drive.php
require_once __DIR__ . '/../Services/OneCallClient.php';
require_once __DIR__ . '/../Services/GoogleDriveUploader.php';

$configPath = __DIR__ . '/../../config.php';
if (!file_exists($configPath)) {
    die("Config file not found.");
}
$config = require $configPath;

$dbConfig = $config['db'] ?? [];
$driveConfig = $config['google_drive'] ?? [];
$onecallConfig = $config['onecall'] ?? [
    'base_url' => 'https://onecallvoicerecord.dtac.co.th/',
    'username' => 'ใส่_ONECALL_USERNAME',
    'password' => 'ใส่_ONECALL_PASSWORD'
];

try {
    $pdo = new PDO(
        "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset={$dbConfig['charset']}",
        $dbConfig['username'],
        $dbConfig['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}

$oneCall = new OneCallClient($onecallConfig['base_url'], $onecallConfig['username'], $onecallConfig['password']);
$uploader = new GoogleDriveUploader($driveConfig['service_account_json'], $driveConfig['folder_id']);

// Define time range (e.g. yesterday and today)
$dateStart = date('Ymd_000000', strtotime('-1 day'));
$dateEnd = date('Ymd_235959');

echo "Fetching recordings from $dateStart to $dateEnd...\n";
try {
    $recordingsData = $oneCall->getRecordings($dateStart, $dateEnd);
    $recordings = $recordingsData['recordings'] ?? [];
    echo "Found " . count($recordings) . " recordings in OneCall.\n";
    
    $successCount = 0;
    $failCount = 0;
    $processedCount = 0;
    $maxPerRun = 50; // กำหนด Limit ให้รันแค่รอบละ 50 ไฟล์ป้องกัน Timeout
    
    foreach ($recordings as $rec) {
        if ($processedCount >= $maxPerRun) {
            echo "Reached max limit of $maxPerRun files for this run. Stopping early to prevent timeout.\n";
            break;
        }

        $callId = $rec['id'] ?? null;
        if (!$callId) continue;
        
        // Check if already synced
        $stmt = $pdo->prepare("SELECT status FROM sync_logs WHERE call_id = ?");
        $stmt->execute([$callId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row && $row['status'] === 'synced') {
            continue; // Skip already synced
        }
        
        $processedCount++;
        echo "Processing call ID: $callId...\n";
        
        // Extract info to generate PBX filename for Google Drive Search compatibility
        $startTime = $rec['start'] ?? date('Y-m-d H:i:s');
        $caller = $rec['caller'] ?? $rec['from'] ?? '000000000'; // Map exact keys based on real OneCall API response
        $receiver = $rec['receiver'] ?? $rec['to'] ?? '000000000';
        $direction = isset($rec['direction']) ? strtoupper($rec['direction']) : 'OUT';
        
        $dateObj = new DateTime($startTime);
        $dateStr = $dateObj->format('Ymd_His');
        
        // Format: YYYYMMDD_HHMMSS_CALLID-%2B66XXX-%2B66YYY-OUT.wav
        $encodedCaller = '%2B' . preg_replace('/[^0-9]/', '', $caller);
        $encodedReceiver = '%2B' . preg_replace('/[^0-9]/', '', $receiver);
        
        // Note: For Thailand, you might want to force 66 prefix if it starts with 0. 
        if (strpos($encodedCaller, '%2B0') === 0) $encodedCaller = str_replace('%2B0', '%2B66', $encodedCaller);
        if (strpos($encodedReceiver, '%2B0') === 0) $encodedReceiver = str_replace('%2B0', '%2B66', $encodedReceiver);
        
        $fileName = "{$dateStr}_{$callId}-{$encodedCaller}-{$encodedReceiver}-{$direction}.wav";
        
        $tempFile = sys_get_temp_dir() . '/' . $fileName;
        
        // The audio download URL path based on general REST APIs for OneCall
        $audioUrl = rtrim($onecallConfig['base_url'], '/') . "/onecall/orktrack/rest/recordings/{$callId}/audio";
        
        try {
            $oneCall->downloadAudio($audioUrl, $tempFile);
            
            // Upload to Google Drive
            $driveFileId = $uploader->uploadFile($tempFile, $fileName);
            
            // Cleanup
            unlink($tempFile);
            
            // Update DB
            $stmt = $pdo->prepare("INSERT INTO sync_logs (call_id, drive_file_id, status) VALUES (?, ?, 'synced') ON DUPLICATE KEY UPDATE drive_file_id=?, status='synced', error_message=NULL");
            $stmt->execute([$callId, $driveFileId, $driveFileId]);
            
            echo "Successfully synced to Drive! File ID: $driveFileId\n";
            $successCount++;
            
        } catch (Exception $e) {
            echo "Failed to sync $callId: " . $e->getMessage() . "\n";
            $failCount++;
            
            if (file_exists($tempFile)) unlink($tempFile);
            
            $stmt = $pdo->prepare("INSERT INTO sync_logs (call_id, status, error_message) VALUES (?, 'failed', ?) ON DUPLICATE KEY UPDATE status='failed', error_message=?");
            $stmt->execute([$callId, $e->getMessage(), $e->getMessage()]);
        }
    }
    
    echo "Sync Complete. Success: $successCount, Failed: $failCount.\n";
    
} catch (Exception $e) {
    die("Error fetching recordings: " . $e->getMessage());
}
