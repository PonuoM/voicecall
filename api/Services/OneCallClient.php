<?php
class OneCallClient {
    private $baseUrl;
    private $username;
    private $password;
    private $accessToken;

    public function __construct($baseUrl, $username, $password) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
    }

    public function login() {
        $url = $this->baseUrl . '/onecall/orktrack/rest/user/login?version=orktrack&accesspolicy=all&licenseinfo=true';
        $auth = base64_encode($this->username . ':' . $this->password);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Basic ' . $auth
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
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

        // dateStart/dateEnd should be YYYYMMDD_HHMMSS format based on the guide
        $url = $this->baseUrl . '/onecall/orktrack/rest/recordings?range=custom&startdate=' . $dateStart . '&enddate=' . $dateEnd . '&page=1&maxresults=-1&includetags=true&includemetadata=true&includeprograms=true';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: ' . $this->accessToken
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        throw new Exception("Failed to get recordings: HTTP $httpCode");
    }

    public function downloadAudio($recordingUrl, $savePath) {
        if (!$this->accessToken) {
            $this->login();
        }

        $ch = curl_init($recordingUrl);
        $fp = fopen($savePath, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $this->accessToken
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        curl_exec($ch);
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
