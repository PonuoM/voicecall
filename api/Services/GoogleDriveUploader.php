<?php
class GoogleDriveUploader {
    private $serviceAccountJsonPath;
    private $folderId;
    private $accessToken;

    public function __construct($serviceAccountJsonPath, $folderId) {
        $this->serviceAccountJsonPath = $serviceAccountJsonPath;
        $this->folderId = $folderId;
    }

    private function getAccessToken() {
        if ($this->accessToken) return $this->accessToken;

        $json = json_decode(file_get_contents($this->serviceAccountJsonPath), true);
        if (!$json || !isset($json['private_key'])) throw new Exception("Invalid service account JSON");

        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claim = json_encode([
            'iss' => $json['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file', // Need this scope for uploading
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]);

        $b64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $b64Claim = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($claim));

        $signature = '';
        openssl_sign($b64Header . '.' . $b64Claim, $signature, $json['private_key'], 'sha256');
        $b64Sig = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

        $jwt = $b64Header . '.' . $b64Claim . '.' . $b64Sig;

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
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
        throw new Exception('Failed to get token: ' . $response);
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
        
        throw new Exception("Google Drive Upload failed: " . $response);
    }
}
