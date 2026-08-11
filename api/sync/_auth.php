<?php
/**
 * Auth guard for the api/sync/* endpoints.
 *
 * These predate api/index.php and never went through core/bootstrap.php, so none of them
 * authenticated anything: on production all eight were reachable anonymously. process_single.php
 * would sync from OneCall and upload to Google Drive for any company id the caller named,
 * setup_oauth.php would rewrite .env with attacker-supplied OAuth credentials, and
 * cleanup_duplicates.php deleted Drive files after a "Security check" that simply looked up the
 * role of whatever `user_id` the request supplied — a number, not a secret.
 *
 * They keep their own DB handles and {success, message} response shape; this file only adds the
 * identity check in front, using the same bearer token / session cookie api/index.php validates.
 */

require_once __DIR__ . '/../config.php';

/**
 * Identity behind this request, or a 401.
 *
 * @param bool $html setup_oauth.php renders a page rather than JSON, so it asks for its refusal
 *                   in the same medium.
 */
function sync_auth(bool $html = false): array
{
    try {
        $pdo = db_connect();
    } catch (Throwable $e) {
        sync_deny('Auth backend unavailable', 503, $html);
    }

    $user = get_authenticated_user($pdo);
    if (!$user) {
        sync_deny('กรุณาเข้าสู่ระบบใหม่ (session หมดอายุ)', 401, $html);
    }
    return $user;
}

function sync_require_super_admin(array $user, bool $html = false): void
{
    if (empty($user['erp_is_super_admin'])) {
        sync_deny('Unauthorized. Super Admin only.', 403, $html);
    }
}

function sync_deny(string $message, int $status, bool $html = false): void
{
    http_response_code($status);
    if ($html) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Access denied</title>'
            . '<p style="font-family:system-ui;padding:32px">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    } else {
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/**
 * The company this request may act on. A super admin may name any company; everyone else is
 * pinned to the one snapshotted in their token, whatever the request said.
 */
/**
 * The .env contents these endpoints expect as `$env`. They all used parse_ini_file(), which cannot
 * read this .env at all — see parse_env_file() in api/core/env.php.
 */
function sync_env(): array
{
    static $env = null;
    if ($env === null) {
        require_once __DIR__ . '/../core/env.php';
        $env = parse_env_file(__DIR__ . '/../../.env');
    }
    return $env;
}

function sync_company_id(array $user, $requested): int
{
    if (!empty($user['erp_is_super_admin']) && !empty($requested)) {
        return (int) $requested;
    }
    return (int) ($user['erp_company_id'] ?? 0);
}
