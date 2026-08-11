-- 011_drop_plaintext_token.sql
-- Second half of 010_auth_hardening.sql. Run this ONLY once the code that reads token_hash is
-- deployed: between 010 and this migration both columns exist, so the old code (which selects by
-- `token`) and the new code (which selects by `token_hash`) both keep working. Dropping the column
-- first would have logged every active user out mid-deploy.

ALTER TABLE api_tokens
  DROP INDEX uq_api_tokens_token,
  DROP COLUMN token,
  MODIFY COLUMN token_hash CHAR(64) NOT NULL COMMENT 'SHA2(raw token, 256) — the raw token is never stored';
