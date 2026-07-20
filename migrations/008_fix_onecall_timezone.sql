-- Normalize recording timestamps to Asia/Bangkok.
--
-- Two phone systems feed the Drive folder, and only one of them logs local time:
--   * old PBX      — call_code is letters (e.g. "OYIP"), 2026-01-20..2026-07-01 — Thai local ✔
--   * myrecordings — call_code is a 14-digit YYYYMMDDHHMMSS stamp, 24 files      — Thai local ✔
--   * OneCall      — call_code is a 9-digit id (e.g. "107988980"), from 2026-06-12 — **UTC** ✘
--
-- Evidence: per-hour call volume for OneCall rows peaks 02:00-10:00 with a dip at 05:00, i.e.
-- Thai office hours (09:00-17:00, lunch at 12:00) shifted -7h; the other two peak at the real
-- office hours. Confirmed against primacom_mini_erp.call_history by resolving each recording's
-- customer and comparing timestamps: PBX rows sit +4 min from the CRM entry (the telesale logging
-- the call just after hanging up), OneCall rows sit +423 min — exactly 7 hours.
--
-- Guarded by tz_normalized so re-running can never double-shift.

ALTER TABLE gdrive_file_index ADD COLUMN tz_normalized TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = call_date/call_time are Asia/Bangkok';
ALTER TABLE conversations ADD COLUMN tz_normalized TINYINT(1) NOT NULL DEFAULT 0
  COMMENT '1 = call_date/call_time are Asia/Bangkok';

-- Everything that was already local: mark, do not touch the values.
UPDATE gdrive_file_index SET tz_normalized = 1
  WHERE tz_normalized = 0 AND NOT (call_code REGEXP '^[0-9]{7,12}$');
UPDATE conversations SET tz_normalized = 1
  WHERE tz_normalized = 0 AND NOT (call_code REGEXP '^[0-9]{7,12}$');

-- OneCall rows: +7h. Staged through a temp column because MySQL evaluates SET assignments
-- left-to-right on already-updated values, so deriving call_date and call_time from each other
-- in one statement would corrupt the second one.
ALTER TABLE gdrive_file_index ADD COLUMN tz_tmp DATETIME NULL;
ALTER TABLE conversations ADD COLUMN tz_tmp DATETIME NULL;

UPDATE gdrive_file_index
SET tz_tmp = TIMESTAMP(call_date, COALESCE(call_time, '00:00:00')) + INTERVAL 7 HOUR
WHERE tz_normalized = 0 AND call_date IS NOT NULL;

UPDATE conversations
SET tz_tmp = TIMESTAMP(call_date, COALESCE(call_time, '00:00:00')) + INTERVAL 7 HOUR
WHERE tz_normalized = 0 AND call_date IS NOT NULL;

UPDATE gdrive_file_index
SET call_date = DATE(tz_tmp), call_time = TIME(tz_tmp), tz_normalized = 1
WHERE tz_tmp IS NOT NULL;

UPDATE conversations
SET call_date = DATE(tz_tmp), call_time = TIME(tz_tmp), tz_normalized = 1
WHERE tz_tmp IS NOT NULL;

-- Rows with no date at all can't be shifted; mark them so they aren't retried forever.
UPDATE gdrive_file_index SET tz_normalized = 1 WHERE tz_normalized = 0;
UPDATE conversations SET tz_normalized = 1 WHERE tz_normalized = 0;

ALTER TABLE gdrive_file_index DROP COLUMN tz_tmp;
ALTER TABLE conversations DROP COLUMN tz_tmp;
