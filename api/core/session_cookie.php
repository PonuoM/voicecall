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
 * Path the cookie is scoped to — the app root as the browser sees it (e.g. "/voicecall"), derived
 * from where this file sits under DOCUMENT_ROOT so it comes out the same whether the caller is
 * /voicecall/login.php or /voicecall/api/index.php.
 */
function session_cookie_path(): string
{
    $appRoot = str_replace('\\', '/', dirname(dirname(__DIR__)));
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

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
    setcookie(SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => session_cookie_path(),
        'secure'   => session_cookie_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function session_cookie_token(): ?string
{
    $token = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    return is_string($token) && $token !== '' ? $token : null;
}
