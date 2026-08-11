-- 010_auth_hardening.sql
-- Two auth fixes on the voicecall_ai side. This half is additive only, so it is safe to apply
-- while the old code is still running — see 011_drop_plaintext_token.sql for the part that must
-- wait until the new code is deployed.
--
-- 1) api_tokens stored the bearer token verbatim, so anyone who could read the table (a SQL
--    injection, a leaked backup, a DBA) could immediately impersonate every logged-in user.
--    Only the SHA-256 of the token is kept from now on; login.php hands the raw value to the
--    browser once and never stores it. Existing rows are backfilled from their own plaintext, so
--    live sessions survive the change.
--
-- 2) login.php had no throttle at all — an unlimited-rate password guesser against plaintext
--    passwords. login_attempts backs the lockout window in login.php.

ALTER TABLE api_tokens
  ADD COLUMN token_hash CHAR(64) NULL COMMENT 'SHA2(raw token, 256) — the raw token is never stored' AFTER token,
  ADD UNIQUE KEY uq_api_tokens_hash (token_hash);

UPDATE api_tokens SET token_hash = SHA2(token, 256) WHERE token_hash IS NULL;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL,
  ip VARCHAR(45) NOT NULL COMMENT 'REMOTE_ADDR, sized for IPv6',
  success TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_attempts_ip (ip, attempted_at),
  KEY idx_login_attempts_user (username, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
