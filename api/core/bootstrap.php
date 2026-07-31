<?php

require_once __DIR__ . '/../config.php';

define('API_VERSION', '2026-06-27-0001');

cors();

// AppServ runs PHP 7.3 - str_starts_with()/str_contains() are PHP 8.0+ only.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}

function route_path(): array
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    // REQUEST_URI stays percent-encoded (e.g. "%20") while SCRIPT_NAME/PHP_SELF
    // come back decoded - without rawurldecode() here, prefix-stripping below
    // silently fails whenever the deployed path has spaces or other encoded chars.
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $path = rawurldecode($path);
    if ($scriptDir && str_starts_with($path, $scriptDir)) {
        $path = substr($path, strlen($scriptDir));
    }
    $path = trim($path, '/');
    if (str_starts_with($path, 'api/')) {
        $path = substr($path, 4);
    } elseif ($path === 'api') {
        $path = '';
    }
    if (str_starts_with($path, 'index.php/')) {
        $path = substr($path, strlen('index.php/'));
    } elseif ($path === 'index.php') {
        $path = '';
    }
    return $path === '' ? [''] : explode('/', $path);
}

function method(): string
{
    return $_SERVER['REQUEST_METHOD'] ?? 'GET';
}

try {
    $pdo = db_connect();
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => 'DB_CONNECT_FAILED', 'message' => $e->getMessage()], 500);
}

function erp(): PDO
{
    static $erp = null;
    if ($erp === null) {
        $erp = erp_connect();
    }
    return $erp;
}

$parts = route_path();
$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;
$action = $parts[2] ?? null;

if ($resource === '' || $resource === 'health') {
    // Only exit with healthy status if we are actually handling an HTTP request to the root, not CLI
    if (PHP_SAPI !== 'cli') {
        json_response(['ok' => true, 'status' => 'healthy', 'version' => API_VERSION]);
    }
}

// Auth itself happens in ../login.php (existing dashboard login against primacom_mini_erp),
// which mints the bearer token this API validates. Every resource here requires that token.
$isCronScript = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/cron/') !== false;

if ($resource === 'erp' || $isCronScript || PHP_SAPI === 'cli') {
    // The ERP resource handles its own auth via ERP_API_KEY in ErpController
    // Cron endpoints handle their own auth via CRON_HTTP_KEY
    $currentUser = null;
} else {
    $currentUser = validate_auth($pdo);
}
