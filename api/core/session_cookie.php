<?php
/**
 * HttpOnly session cookie carrying the same bearer token api/* validates.
 *
 * Why a cookie exists at all when the API already uses `Authorization: Bearer`: some requests are
 * plain browser navigations that cannot carry a custom header — `<audio src="audio_proxy.php…">`,
 * the "download recording" link, and api/sync/setup_oauth.php's HTML form. The alternative was
 * `?token=…` in the URL, which leaks the credential into Referer headers, browser history, and
 * every access log along the way.
 *
 * SameSite=Lax is load-bearing here, not boilerplate: without it any other site could embed
 * <audio src="https://…/voicecall/audio_proxy.php?id=…"> and the browser would attach this cookie.
 */

const SESSION_COOKIE_NAME = 'vc_session';

/**
 * Path the cookie is scoped to — the app root as the browser sees it (e.g. "/voicecall"), so it
 * comes out the same whether the caller is /voicecall/login.php or /voicecall/api/index.php.
 *
 * Derived by measuring how deep the running script sits below the app root on disk and stripping
 * that same number of segments off its URL.
 *
 * Both paths go through realpath() first, and that is the whole trick: on production DirectAdmin
 * serves the vhost out of `private_html`, which is a symlink to `public_html`. SCRIPT_FILENAME and
 * DOCUMENT_ROOT come back with the `private_html` spelling while __DIR__ has the resolved
 * `public_html` one, so a raw string comparison never matches and the cookie fell back to path "/"
 * — scoping it to the whole of prima49.com rather than just /voicecall.
 */
function session_cookie_path(): string
{
    $appRoot = dirname(dirname(__DIR__));
    $appRoot = rtrim(str_replace('\\', '/', realpath($appRoot) ?: $appRoot), '/');

    $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptFile = str_replace('\\', '/', realpath($scriptFile) ?: $scriptFile);
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    if ($scriptFile !== '' && $scriptName !== '' && strpos($scriptFile, $appRoot . '/') === 0) {
        // e.g. "api/index.php" — the part of the script's path that lies below the app root.
        $belowRoot = substr($scriptFile, strlen($appRoot) + 1);
        $depth = substr_count($belowRoot, '/');
        $path = $scriptName;
        for ($i = 0; $i <= $depth; $i++) {
            $path = substr($path, 0, (int) strrpos($path, '/'));
        }
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }
        if ($path === '') {
            return '/';
        }
    }

    // Fallback for setups where SCRIPT_FILENAME is missing or unrelated (CLI, odd rewrites).
    $docRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
    $docRoot = rtrim(str_replace('\\', '/', realpath($docRoot) ?: $docRoot), '/');
    if ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
        $path = substr($appRoot, strlen($docRoot));
        return $path === '' ? '/' : $path;
    }
    return '/';
}

function session_cookie_is_secure(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/**
 * @param int $expiresEpoch UNIX timestamp the underlying api_tokens row expires at; 0 falls back
 *                          to a session cookie, which is the safe direction to fail.
 */
function set_session_cookie(string $token, int $expiresEpoch = 0): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(SESSION_COOKIE_NAME, $token, [
        'expires'  => $expiresEpoch > time() ? $expiresEpoch : 0,
        'path'     => session_cookie_path(),
        'secure'   => session_cookie_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_session_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    // Both paths: a cookie is only removable at the exact path it was set on, and the first
    // deployment of this file briefly issued the cookie at "/" before session_cookie_path() was
    // corrected. Clearing just the app path would have left those stragglers alive until expiry.
    $paths = array_unique([session_cookie_path(), '/']);
    foreach ($paths as $path) {
        setcookie(SESSION_COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => $path,
            'secure'   => session_cookie_is_secure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function session_cookie_token(): ?string
{
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    return is_string($token) && $token !== '' ? $token : null;
}
