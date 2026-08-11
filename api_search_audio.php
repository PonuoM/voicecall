<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// Load config
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration file missing']);
    exit;
}
$config = require $configPath;

$SERVICE_ACCOUNT_JSON = $config['google_drive']['service_account_json'] ?? __DIR__ . '/service-account.json';
$FOLDER_ID = $config['google_drive']['folder_id'] ?? '';
$AUTH_TOKEN = $config['api']['auth_token'] ?? '';

if (!file_exists($SERVICE_ACCOUNT_JSON)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Service account JSON file missing. Please create service-account.json']);
    exit;
}

// Authentication check
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? '';
if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
}

// Fail closed when no token is configured. config.php has no 'api' => ['auth_token'] key on every
// deployment (the local dev box being one), which made $AUTH_TOKEN an empty string — and an empty
// string compares equal to `?token=` with nothing after it, so the endpoint authenticated anyone
// who left the parameter blank.
if ($AUTH_TOKEN === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server misconfigured: api.auth_token is not set']);
    exit;
}

// Support both Bearer Token and simple ?token=... for testing flexibility
$tokenValid = false;
if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $tokenValid = hash_equals($AUTH_TOKEN, $matches[1]);
} else if (isset($_GET['token'])) {
    $tokenValid = hash_equals($AUTH_TOKEN, (string) $_GET['token']);
}

if (!$tokenValid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Invalid or missing token']);
    exit;
}

// Input Validation
$phone = isset($_GET['phone']) ? trim($_GET['phone']) : '';
$dateStart = isset($_GET['date_start']) ? trim($_GET['date_start']) : '';
$dateEnd = isset($_GET['date_end']) ? trim($_GET['date_end']) : '';

if (empty($phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing phone parameter']);
    exit;
}

// Validate phone characters (digits and + - whitespace only)
if (!preg_match('/^[0-9\+\-\s]+$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
    exit;
}

// Validate dates if provided
function isValidDate($date) {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

if (!empty($dateStart) && !isValidDate($dateStart)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date_start format. Use YYYY-MM-DD']);
    exit;
}
if (!empty($dateEnd) && !isValidDate($dateEnd)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date_end format. Use YYYY-MM-DD']);
    exit;
}

// Prepare search phone
$searchPhone = preg_replace('/[\s\-]+/', '', $phone);
if (strpos($searchPhone, '0') === 0 && strlen($searchPhone) >= 9) {
    $searchPhone = substr($searchPhone, 1);
} else if (strpos($searchPhone, '+66') === 0) {
    $searchPhone = substr($searchPhone, 3);
}

// Helper: Get JWT Access Token manually
function getGoogleAccessToken($jsonFilePath) {
    $json = json_decode(file_get_contents($jsonFilePath), true);
    if (!$json || !isset($json['private_key']) || !isset($json['client_email'])) {
        throw new Exception("Invalid service account JSON");
    }

    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $now = time();
    $claim = json_encode([
        'iss' => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/drive.readonly',
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
    // This exchange trades a signed JWT for a Drive access token — verifying the certificate is
    // what stops that token being handed to an interceptor.
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/api/certs/cacert.pem');

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception('cURL auth error: ' . curl_error($ch));
    }
    curl_close($ch);

    $data = json_decode($response, true);
    if (isset($data['access_token'])) return $data['access_token'];
    throw new Exception('Failed to get token: ' . $response);
}

// Fetch from Google Drive API using Service Account (Pure PHP)
$allGoogleFiles = [];

try {
    $accessToken = getGoogleAccessToken($SERVICE_ACCOUNT_JSON);
    
    $q = "(name contains '2B66{$searchPhone}' or name contains '66{$searchPhone}' or name contains '{$searchPhone}' or name contains '0{$searchPhone}') and mimeType='audio/wav' and trashed=false";
    if (!empty($FOLDER_ID) && $FOLDER_ID !== 'ใส่รหัสโฟลเดอร์_GOOGLE_DRIVE_ที่นี่') {
        $q .= " and '{$FOLDER_ID}' in parents";
    }

    $pageToken = '';
    $baseUrl = "https://www.googleapis.com/drive/v3/files";
    
    do {
        $url = $baseUrl . "?q=" . urlencode($q) . "&fields=" . urlencode("files(id,name,size,mimeType),nextPageToken") . "&pageSize=1000&orderBy=name";
        if (!empty($pageToken)) {
            $url .= "&pageToken=" . urlencode($pageToken);
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__ . '/api/certs/cacert.pem');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$accessToken}"]);
        
        $response = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new Exception('cURL search error: ' . curl_error($ch));
        }
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception($data['error']['message']);
        }
        
        if (isset($data['files']) && is_array($data['files'])) {
            $allGoogleFiles = array_merge($allGoogleFiles, $data['files']);
        }
        
        $pageToken = $data['nextPageToken'] ?? '';
        
    } while (!empty($pageToken));
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Google API error: ' . $e->getMessage()]);
    exit;
}

$results = [];

// Parse and filter the results
foreach ($allGoogleFiles as $file) {
    $name = $file['name'];
    $parsed = null;
    
    // Parsing logic based on frontend JS parseWavFilename
    $decoded = urldecode($name);
    
    // Format 1: YYYYMMDD_HHMMSS_calldata-IN/OUT.wav (original PBX)
    $parts = explode('_', str_replace('.wav', '', $name));
    if (count($parts) >= 3 && preg_match('/^\d{8}$/', $parts[0]) && preg_match('/^\d{6}$/', $parts[1])) {
        $dateStr = $parts[0];
        $timeStr = $parts[1];
        
        $rest = implode('_', array_slice($parts, 2));
        $restDecoded = urldecode($rest);
        
        $direction = (substr($restDecoded, -4) === '-OUT') ? 'OUT' : 'IN';
        $noDir = ($direction === 'OUT') ? substr($restDecoded, 0, -4) : substr($restDecoded, 0, -3);
        
        preg_match_all('/\+\d+/', $noDir, $matches);
        $phones = $matches[0] ?? [];
        $callId = explode('-', $noDir)[0] ?? '';
        
        $parsed = [
            'id' => $callId,
            'date' => substr($dateStr, 0, 4) . '-' . substr($dateStr, 4, 2) . '-' . substr($dateStr, 6, 2),
            'time' => substr($timeStr, 0, 2) . ':' . substr($timeStr, 2, 2) . ':' . substr($timeStr, 4, 2),
            'caller' => $phones[0] ?? '',
            'receiver' => $phones[1] ?? '',
            'direction' => $direction
        ];
    } else {
        // Format 2: myrecordings_YYYYMMDD_HHMMSS_dir_phone_phone.wav
        if (preg_match('/(\d{8})/', $decoded, $dateMatch)) {
            $d = $dateMatch[1];
            $dateFormatted = substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
            
            $time = '00:00:00';
            $callId = $d;
            if (preg_match('/\d{8}[_]?(\d{6})/', $decoded, $timeMatch)) {
                $t = $timeMatch[1];
                $time = substr($t, 0, 2) . ':' . substr($t, 2, 2) . ':' . substr($t, 4, 2);
                $callId = $d . $t;
            }
            
            $direction = '';
            if (preg_match('/[_](out|in)[_]/i', $decoded, $dirMatch)) {
                $direction = strtoupper($dirMatch[1]);
            }
            
            preg_match_all('/\+\d{8,}/', $decoded, $phoneMatches);
            $phones = $phoneMatches[0] ?? [];
            
            $caller = isset($phones[0]) ? '+' . str_replace('+', '', $phones[0]) : '';
            $receiver = isset($phones[1]) ? '+' . str_replace('+', '', $phones[1]) : '';
            
            $parsed = [
                'id' => $callId,
                'date' => $dateFormatted,
                'time' => $time,
                'caller' => $caller,
                'receiver' => $receiver,
                'direction' => $direction
            ];
        }
    }
    
    if ($parsed) {
        // Check date range filter
        if (!empty($dateStart) && $parsed['date'] < $dateStart) continue;
        if (!empty($dateEnd) && $parsed['date'] > $dateEnd) continue;
        
        // Check if phone actually matches caller or receiver to prevent false positives from id/date
        $cleanCaller = str_replace(['+', '-'], '', $parsed['caller']);
        $cleanReceiver = str_replace(['+', '-'], '', $parsed['receiver']);
        $cleanPhoneSearch = preg_replace('/[\s\-]+/', '', $phone);
        
        if (
            strpos($cleanCaller, $cleanPhoneSearch) === false && 
            strpos($cleanReceiver, $cleanPhoneSearch) === false
        ) {
            // strict match on phone number only
            continue;
        }
        
        $parsed['filename'] = $name;
        $parsed['size'] = (int)($file['size'] ?? 0);
        $parsed['fileId'] = $file['id'];
        $parsed['link'] = "https://drive.google.com/file/d/{$file['id']}/view";
        
        $results[] = $parsed;
    }
}

// Sort by date and time descending
usort($results, function($a, $b) {
    return strcmp($b['date'] . $b['time'], $a['date'] . $a['time']);
});

http_response_code(200);
echo json_encode([
    'success' => true,
    'count' => count($results),
    'data' => $results
]);
