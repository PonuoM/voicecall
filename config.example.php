<?php
/**
 * Template config file — safe to commit.
 * Copy to config.php and fill in real credentials on each environment.
 *   cp config.example.php config.php
 */

return [
    'db' => [
        'host'     => 'YOUR_DB_HOST',
        'username' => 'YOUR_DB_USER',
        'password' => 'YOUR_DB_PASSWORD',
        'database' => 'YOUR_DB_NAME',
        'charset'  => 'utf8mb4',
    ],
    // Used by api_search_audio.php (phone-search endpoint, deployed separately on production —
    // not part of this repo's tracked history, found live on prima49.com during deployment).
    'api' => [
        'auth_token' => 'YOUR_AUTH_TOKEN',
    ],
    'google_drive' => [
        'api_key' => 'YOUR_GDRIVE_API_KEY',
    ],
];
