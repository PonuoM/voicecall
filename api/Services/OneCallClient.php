<?php
class OneCallClient {
    private $baseUrl;
    private $username;
    private $password;
    private $accessToken;

    public function __construct($baseUrl, $username, $password) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = trim($username, '"\'');
        $this->password = trim($password, '"\'');
    }

    public function login() {
        $url = $this->baseUrl . '/orktrack/rest/user/login?version=orktrack&accesspolicy=all&licenseinfo=true';
        $auth = base64_encode($this->username . ':' . $this->password);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ''); 
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Basic ' . $auth,
            'Content-Type:', 
            'Content-Length: 0'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Best Practice: Connection Timeout
        curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Best Practice: Execution Timeout

        $response = curl_exec($ch);
        
        if ($response === false) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL Error during login: $errorMsg");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $this->accessToken = $data['accesstoken'] ?? '';
            return true;
        }
        
        throw new Exception("Failed to login to OneCall: HTTP $httpCode - $response");
    }

    public function getRecordings($dateStart, $dateEnd) {
        if (!$this->accessToken) {
            $this->login();
        }

        $url = $this->baseUrl . '/orktrack/rest/recordings?range=custom&startdate=' . $dateStart . '&enddate=' . $dateEnd . '&page=1&pagesize=5000&maxresults=-1&includetags=true&includemetadata=true&includeprograms=true';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: ' . $this->accessToken
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 1 minute max for getting the list

        $response = curl_exec($ch);
        
        if ($response === false) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL Error during getRecordings: $errorMsg");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        throw new Exception("Failed to get recordings: HTTP $httpCode - $response");
    }

    public function downloadAudio($recordingUrl, $savePath) {
        if (!$this->accessToken) {
            $this->login();
        }

        $ch = curl_init($recordingUrl);
        $fp = fopen($savePath, 'wb');
        if (!$fp) {
            throw new Exception("Failed to open file for writing: $savePath");
        }

        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->accessToken
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes max for downloading large audio file

        $success = curl_exec($ch);
        
        if ($success === false) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            fclose($fp);
            if (file_exists($savePath)) {
                unlink($savePath);
            }
            throw new Exception("cURL Error during downloadAudio: $errorMsg");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($httpCode === 200) {
            return true;
        }
        
        if (file_exists($savePath)) {
            unlink($savePath);
        }
        throw new Exception("Failed to download audio from $recordingUrl: HTTP $httpCode");
    }
}
