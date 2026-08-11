<?php
// api/sync/get_folder_id.php
header('Content-Type: application/json');

require_once __DIR__ . '/_auth.php';
$syncUser = sync_auth();

$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}
$env = sync_env(); // not parse_ini_file(): PHP's ini parser chokes on this .env — see sync_env()

$companyId = sync_company_id($syncUser, $_GET['company_id'] ?? null);
if (!$companyId) {
    echo json_encode(['success' => false, 'message' => 'Missing company_id']);
    exit;
}

$envKey = "GDRIVE_FOLDER_ID_{$companyId}";
$folderId = $env[$envKey] ?? '';

if ($folderId) {
    echo json_encode(['success' => true, 'folder_id' => trim($folderId)]);
} else {
    // Not found in .env, return a helpful error
    echo json_encode([
        'success' => false, 
        'message' => "ไม่พบรหัสโฟลเดอร์สำหรับบริษัทนี้ กรุณาเพิ่ม $envKey ลงในไฟล์ .env ของโปรเจกต์"
    ]);
}
