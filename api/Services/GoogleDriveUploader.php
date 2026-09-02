<?php
class GoogleDriveUploader {
    private $clientId;
    private $clientSecret;
    private $refreshToken;
    private $folderId;
    private $accessToken;

    public function __construct($clientId, $clientSecret, $refreshToken, $folderId) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->folderId = $folderId;
    }

    private function getAccessToken() {
        if ($this->accessToken) return $this->accessToken;

        if (!$this->refreshToken) {
            throw new Exception("Missing Google Drive Refresh Token. Please run setup_oauth.php first.");
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token'
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            return $this->accessToken;
        }
        
        throw new Exception('Failed to refresh Google Drive token: ' . $response);
    }

    public function uploadFile($filePath, $fileName, $mimeType = 'audio/wav') {
        $token = $this->getAccessToken();

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new Exception("Google Drive Upload failed: cannot stat {$filePath}");
        }

        // This used to build the whole multipart body as one PHP string (metadata + the entire
        // file + boundaries), which held the audio in memory two to three times over per upload.
        // Uploads run in bursts from the sync dashboard during business hours - exactly when the
        // shared host is already tightest (it OOM'd on this very line repeatedly, 31 Aug-1 Sep
        // 2026). A resumable session costs one extra tiny request, and then curl streams the
        // file straight from disk: memory stays flat regardless of file size.
        $metadata = json_encode([
            'name' => $fileName,
            'parents' => [$this->folderId]
        ]);

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $metadata);
        curl_setopt($ch, CURLOPT_HEADER, true); // the session URL comes back in the Location header
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            'Content-Type: application/json; charset=UTF-8',
            "X-Upload-Content-Type: {$mimeType}",
            "X-Upload-Content-Length: {$fileSize}"
        ]);
        // Verification on, with the CA bundle the rest of the pipeline already uses against
        // googleapis.com in production - unverified TLS here would hand the OAuth token to
        // whoever answered.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../certs/cacert.pem');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200 || !preg_match('/^location:\s*(\S+)/mi', (string) $response, $m)) {
            throw new Exception("Google Drive Upload failed: no resumable session (HTTP $httpCode) " . substr((string) $response, 0, 500));
        }
        $uploadUrl = $m[1];

        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            throw new Exception("Google Drive Upload failed: cannot open {$filePath}");
        }

        $ch = curl_init($uploadUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_UPLOAD, true); // PUT, streamed from the handle below
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: {$mimeType}"]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/../certs/cacert.pem');
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fh);

        $data = json_decode((string) $response, true);
        if (($httpCode === 200 || $httpCode === 201) && isset($data['id'])) {
            return $data['id']; // Return the Google Drive File ID
        }

        throw new Exception("Google Drive Upload failed: HTTP $httpCode " . $response);
    }

    public function findFolderByName($folderName, $parentFolderId = null) {
        $token = $this->getAccessToken();
        
        $query = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace("'", "\\'", $folderName) . "' and trashed=false";
        if ($parentFolderId) {
            $query .= " and '{$parentFolderId}' in parents";
        }

        $url = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id,name)&spaces=drive';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['files']) && count($data['files']) > 0) {
            return $data['files'][0]['id']; // Return the first matching folder ID
        }
        
        return null; // Folder not found
    }

    public function checkFileExistsByTimestamp($timestamp) {
        if (!$this->folderId) return null;
        
        $token = $this->getAccessToken();
        
        // Use 'contains' operator on name to find either DTAC bulk format or our format
        $query = "name contains '{$timestamp}' and '{$this->folderId}' in parents and trashed=false and mimeType!='application/vnd.google-apps.folder'";

        $url = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&fields=files(id,name)&spaces=drive';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['files']) && count($data['files']) > 0) {
            return $data['files'][0]['id']; // Return the first matching file ID
        }
        
        return null; // File not found
    }

    public function listFiles($query, $pageSize = 1000, $pageToken = null, $fields = 'nextPageToken, files(id, name, size, createdTime)') {
        $token = $this->getAccessToken();
        
        $url = 'https://www.googleapis.com/drive/v3/files?q=' . urlencode($query) . '&pageSize=' . $pageSize . '&fields=' . urlencode($fields) . '&spaces=drive';
        if ($pageToken) {
            $url .= '&pageToken=' . urlencode($pageToken);
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        throw new Exception('Failed to list files: ' . $response);
    }

    public function deleteFile($fileId) {
        $token = $this->getAccessToken();
        
        $url = 'https://www.googleapis.com/drive/v3/files/' . urlencode($fileId);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}"
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 204 || $httpCode === 200) {
            return true;
        }
        
        throw new Exception('Failed to delete file: ' . $response);
    }
}
