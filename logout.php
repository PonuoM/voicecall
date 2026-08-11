<?php
/**
 * Logout — revokes the session server-side.
 *
 * Clearing localStorage/sessionStorage in the browser (all logout used to do) left the token valid
 * in api_tokens for its full 7 days, so anything that had copied it stayed authenticated after the
 * user thought they had signed out. This deletes the row and expires the HttpOnly cookie.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/core/session_cookie.php';

$token = bearer_token();
clear_session_cookie();

if ($token) {
    try {
        $pdo = db_connect();
        $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $token)]);
    } catch (Throwable $e) {
        // Cookie is already cleared; a DB hiccup must not leave the user stuck on the dashboard.
    }
}

echo json_encode(['ok' => true]);
