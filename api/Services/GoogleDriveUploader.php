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
        
        $metadata = [
            'name' => $fileName,
            'parents' => [$this->folderId]
        ];
        
        $boundary = '-------' . uniqid();
        $content = "--" . $boundary . "\r\n";
        $content .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $content .= json_encode($metadata) . "\r\n";
        $content .= "--" . $boundary . "\r\n";
        $content .= "Content-Type: " . $mimeType . "\r\n\r\n";
        $content .= file_get_contents($filePath) . "\r\n";
        $content .= "--" . $boundary . "--";

        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$token}",
            "Content-Type: multipart/related; boundary={$boundary}",
            "Content-Length: " . strlen($content)
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($httpCode === 200 && isset($data['id'])) {
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
