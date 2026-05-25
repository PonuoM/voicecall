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
    'api' => [
        'auth_token' => 'voicecall_secret_token_2026', // Example secure token
    ],
    'google_drive' => [
        'api_key' => 'AIzaSyCCIywRsoHuBzVTm-B-FA8N7VzAcECIEBE',
    ],
];
