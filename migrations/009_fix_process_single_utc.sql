-- Backfill for the timezone hole in api/sync/process_single.php.
--
-- That endpoint pulls recordings straight from OneCall's API and, unlike GdriveIndexer::parseFilename
-- and index.html's parseWavFilename, it stored OneCall's raw UTC `start` into call_date/call_time
-- without the +7h Asia/Bangkok shift. Result: every batch it synced landed 7h early, so the rows
-- displayed the wrong time AND never matched primacom_mini_erp.call_history (which is local),
-- always showing "ไม่ถูกบันทึกใน CRM (มี log อื่น ...)". Fixed at source in process_single.php
-- (it now reuses parseFilename); this normalizes the rows that already slipped through.
--
-- Safe / idempotent: an unshifted row is identified by its call_time still equalling the raw HHMMSS
-- in the filename (positions 10-15 of "YYYYMMDD_HHMMSS_..."). A row that was already shifted differs
-- from its filename time by exactly 7h, so it can never re-match — running this twice is a no-op.
-- The filename itself is intentionally left as UTC so GdriveIndexer stays consistent (see 008).
--
-- Requires 008 to have run first: the second UPDATE writes tz_normalized, a column 008 adds.
--
-- STATUS 2026-07-27 — checked against production (primacom_voicelog) and NOT run, because it has
-- nothing to do there: all 44,673 OneCall-shaped rows already sit exactly +420 min from their
-- filename time, and 0 rows match the unshifted predicate below. 008 had already normalized the
-- backlog, and process_single.php evidently hasn't inserted a row since (it's a manual one-call
-- endpoint). The local dev DB has no OneCall-shaped rows at all. Kept as insurance in case an
-- older snapshot is ever restored - re-check the count before running it.

ALTER TABLE gdrive_file_index ADD COLUMN tz_tmp DATETIME NULL;

-- Only OneCall rows (numeric call codes), filename in the YYYYMMDD_HHMMSS_ shape, whose stored
-- time still matches the raw filename time = never shifted.
UPDATE gdrive_file_index
SET tz_tmp = TIMESTAMP(call_date, call_time) + INTERVAL 7 HOUR
WHERE call_code REGEXP '^[0-9]{7,12}$'
  AND filename REGEXP '^[0-9]{8}_[0-9]{6}_'
  AND call_time IS NOT NULL
  AND TIME_FORMAT(call_time, '%H%i%s') = SUBSTRING(filename, 10, 6);

-- +7h can roll into the next day, so recompute both date and time from the combined timestamp.
UPDATE gdrive_file_index
SET call_date = DATE(tz_tmp), call_time = TIME(tz_tmp), tz_normalized = 1
WHERE tz_tmp IS NOT NULL;

ALTER TABLE gdrive_file_index DROP COLUMN tz_tmp;
