<?php
// api/sync/cleanup_duplicates.php
header('Content-Type: application/json');

require_once __DIR__ . '/_auth.php';
$syncUser = sync_auth();
sync_require_super_admin($syncUser);

require_once __DIR__ . '/../Services/GoogleDriveUploader.php';

$envPath = __DIR__ . '/../../.env';
if (!file_exists($envPath)) {
    echo json_encode(['success' => false, 'message' => '.env file not found.']);
    exit;
}
// _auth.php already loaded api/config.php → db_connect()/erp_connect() handle credentials.
$env = sync_env();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? ($_GET['action'] ?? '');
    $companyId = sync_company_id($syncUser, $input['company_id'] ?? ($_GET['company_id'] ?? 0));
    // The request's own `user_id` is ignored now — sync_require_super_admin() above authorises the
    // verified token instead. The check that used to live here ("SELECT role_id FROM users WHERE
    // id = $userId") authorised whoever the caller *claimed* to be, so posting user_id=1 was
    // enough to delete Drive files.

    if (!$action || !$companyId) {
        throw new Exception("Missing parameters");
    }

    $pdoErp = erp_connect();

    // Get Folder ID for the company from .env
    $folderId = $env["GDRIVE_FOLDER_ID_{$companyId}"] ?? null;
    
    if (!$folderId) {
        throw new Exception("No Google Drive Folder configured for this company (Missing GDRIVE_FOLDER_ID_{$companyId} in .env).");
    }

    // Get Google Drive Config (from .env)
    $uploader = new GoogleDriveUploader(
        $env['GDRIVE_CLIENT_ID'] ?? '',
        $env['GDRIVE_CLIENT_SECRET'] ?? '',
        $env['GDRIVE_REFRESH_TOKEN'] ?? '',
        $folderId
    );

    if ($action === 'preview') {
        $query = "'{$folderId}' in parents and trashed=false and mimeType!='application/vnd.google-apps.folder'";
        $allFiles = [];
        $pageToken = null;
        
        do {
            $res = $uploader->listFiles($query, 1000, $pageToken);
            if (isset($res['files'])) {
                $allFiles = array_merge($allFiles, $res['files']);
            }
            $pageToken = $res['nextPageToken'] ?? null;
        } while ($pageToken);

        // Group files
        $groups = [];
        foreach ($allFiles as $f) {
            $name = $f['name'];
            // Remove duplicate suffixes like " (1)", " (2)" before the extension
            $baseName = preg_replace('/ \(\d+\)\.wav$/i', '.wav', $name);
            if (!isset($groups[$baseName])) {
                $groups[$baseName] = [];
            }
            $groups[$baseName][] = $f;
        }

        $trashList = [];
        $totalSpaceSaved = 0;

        foreach ($groups as $baseName => $files) {
            if (count($files) > 1) {
                // Sort by createdTime ascending (oldest first)
                usort($files, function($a, $b) {
                    return strtotime($a['createdTime']) - strtotime($b['createdTime']);
                });

                $keeper = $files[0]; // The original
                
                // All subsequent files are duplicates
                for ($i = 1; $i < count($files); $i++) {
                    $trash = $files[$i];
                    $trashList[] = [
                        'baseName' => $baseName,
                        'trashId' => $trash['id'],
                        'trashName' => $trash['name'],
                        'trashSize' => (int)($trash['size'] ?? 0),
                        'keeperId' => $keeper['id'],
                        'keeperName' => $keeper['name']
                    ];
                    $totalSpaceSaved += (int)($trash['size'] ?? 0);
                }
            }
        }

        echo json_encode([
            'success' => true, 
            'trashCount' => count($trashList),
            'trashFiles' => $trashList,
            'totalSpaceSaved' => $totalSpaceSaved,
            'scannedCount' => count($allFiles)
        ]);
        exit;
    }

    if ($action === 'delete') {
        $trashFiles = $input['trashFiles'] ?? [];
        if (empty($trashFiles)) {
            throw new Exception("No files to delete");
        }

        $pdoLocal = db_connect();

        $deletedCount = 0;
        $healedCount = 0;

        foreach ($trashFiles as $t) {
            $trashId = $t['trashId'];
            $keeperId = $t['keeperId'];
            
            try {
                $uploader->deleteFile($trashId);
                $deletedCount++;

                // Auto-heal local DB
                $stmtCheck = $pdoLocal->prepare("SELECT id FROM gdrive_file_index WHERE gdrive_file_id = ?");
                $stmtCheck->execute([$trashId]);
                if ($stmtCheck->fetch()) {
                    $stmtUpdate = $pdoLocal->prepare("UPDATE gdrive_file_index SET gdrive_file_id = ?, filename = ? WHERE gdrive_file_id = ?");
                    $stmtUpdate->execute([$keeperId, $t['keeperName'], $trashId]);
                    $healedCount++;
                }

            } catch (Exception $e) {
                // Log or ignore individual delete failures
            }
        }

        echo json_encode([
            'success' => true, 
            'deletedCount' => $deletedCount,
            'healedCount' => $healedCount
        ]);
        exit;
    }

    throw new Exception("Unknown action");

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
