<?php
// api/sync/setup_oauth.php
session_start();

$envPath = __DIR__ . '/../../.env';

function updateEnv($keys) {
    global $envPath;
    $envContent = file_exists($envPath) ? file_get_contents($envPath) : "";
    $lines = explode("\n", $envContent);
    $newLines = [];
    $updatedKeys = [];
    
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            $newLines[] = $line;
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            if (array_key_exists($key, $keys)) {
                $newLines[] = $key . '="' . addslashes($keys[$key]) . '"';
                $updatedKeys[] = $key;
            } else {
                $newLines[] = $line;
            }
        } else {
            $newLines[] = $line;
        }
    }
    
    foreach ($keys as $k => $v) {
        if (!in_array($k, $updatedKeys)) {
            $newLines[] = $k . '="' . addslashes($v) . '"';
        }
    }
    
    file_put_contents($envPath, implode("\n", $newLines));
}

$redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]";

// Step 1: Handle form submission to start OAuth flow
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['client_id']) && isset($_POST['client_secret'])) {
    $_SESSION['client_id'] = trim($_POST['client_id']);
    $_SESSION['client_secret'] = trim($_POST['client_secret']);
    
    $authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $_SESSION['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent' // Force consent to ensure we get a refresh token
    ]);
    
    header("Location: $authUrl");
    exit;
}

// Step 2: Handle Google's redirect with auth code
if (isset($_GET['code'])) {
    if (!isset($_SESSION['client_id']) || !isset($_SESSION['client_secret'])) {
        die("Session lost. Please try again.");
    }
    
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'code' => $_GET['code'],
        'client_id' => $_SESSION['client_id'],
        'client_secret' => $_SESSION['client_secret'],
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $data = json_decode($response, true);
    curl_close($ch);
    
    if (isset($data['refresh_token'])) {
        updateEnv([
            'DRIVE_CLIENT_ID' => $_SESSION['client_id'],
            'DRIVE_CLIENT_SECRET' => $_SESSION['client_secret'],
            'DRIVE_REFRESH_TOKEN' => $data['refresh_token']
        ]);
        
        $message = "✅ Setup Successful! Refresh Token has been saved to .env file.<br><br>You can close this page and start syncing your audio files.";
        $success = true;
    } else {
        $message = "❌ Error getting refresh token: " . htmlspecialchars($response);
        $success = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Drive OAuth Setup</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f0f2f5; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; max-width: 500px; }
        h2 { margin-top: 0; color: #1a73e8; }
        .form-group { margin-bottom: 20px; }
        label { display: block; font-weight: 500; margin-bottom: 8px; color: #3c4043; }
        input[type="text"] { width: 100%; padding: 12px; border: 1px solid #dadce0; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
        button { background: #1a73e8; color: white; border: none; padding: 12px 24px; border-radius: 6px; cursor: pointer; font-size: 16px; font-weight: 500; width: 100%; }
        button:hover { background: #1557b0; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #e6f4ea; color: #137333; border: 1px solid #ceead6; }
        .alert-error { background: #fce8e6; color: #c5221f; border: 1px solid #fad2cf; }
        .code-block { background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; word-break: break-all; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Google Drive OAuth Setup</h2>
        
        <?php if (isset($message)): ?>
            <div class="alert <?php echo $success ? 'alert-success' : 'alert-error'; ?>">
                <?php echo $message; ?>
            </div>
            <?php if ($success): ?>
                <a href="/ui/sync_dashboard.html" style="display: block; text-align: center; margin-top: 20px; text-decoration: none; color: #1a73e8; font-weight: bold;">Go to Dashboard &rarr;</a>
            <?php endif; ?>
        <?php else: ?>
            <p style="color: #5f6368; line-height: 1.5; margin-bottom: 20px;">
                Enter your Google Cloud OAuth 2.0 Client ID and Secret to generate a Refresh Token. This allows the system to upload files directly to your personal Google Drive.
            </p>
            
            <div style="margin-bottom: 20px; font-size: 13px; color: #5f6368;">
                <strong>Redirect URI:</strong> Ensure this exact URL is added to your OAuth client's "Authorized redirect URIs" in Google Cloud Console:<br>
                <div class="code-block"><?php echo htmlspecialchars($redirectUri); ?></div>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label>Client ID</label>
                    <input type="text" name="client_id" required placeholder="e.g. 1080159864746-xxxx.apps.googleusercontent.com">
                </div>
                <div class="form-group">
                    <label>Client Secret</label>
                    <input type="text" name="client_secret" required placeholder="e.g. GOCSPX-xxxx">
                </div>
                <button type="submit">Connect to Google Drive</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
