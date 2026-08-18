-- Every recording must end somewhere.
--
-- The status enum could say a call finished or that it broke, and nothing else. A recording the
-- pipeline declined on purpose — too short to hold a conversation, too quiet to contain speech —
-- came out as 'failed' next to genuine breakage, or never got a row at all because the duration
-- filter dropped it before registration. Both are indistinguishable from a bug: a file sitting
-- untouched looks exactly like a file the system forgot.
--
-- 'skipped' is the third outcome. A row carrying it means the pipeline looked at the recording and
-- decided, and error_message says which rule applied. After this, "no conversation row" means
-- exactly one thing: not reached yet.

ALTER TABLE conversations
    MODIFY COLUMN status ENUM(
        'pending',
        'transcribing',
        'analyzing',
        'extracting',
        'checking_compliance',
        'indexing',
        'completed',
        'failed',
        'skipped'
    ) NOT NULL DEFAULT 'pending';

-- The backlog is drained oldest-first across 211,116 indexed files, and the query that finds the
-- next batch is an anti-join from gdrive_file_index ordered by call date. Without this it is a full
-- scan of the index on every run, several times an hour, forever.
ALTER TABLE gdrive_file_index
    ADD INDEX idx_backlog_scan (call_date, call_time);

-- Reclassify what already ran under the old two-outcome scheme. These are the guards' own wording,
-- so the match is exact rather than a guess at what "failed" meant.
UPDATE conversations
SET status = 'skipped'
WHERE status = 'failed'
  AND (error_message LIKE '%เสียงเงียบเกินกว่า%'
    OR error_message LIKE '%ไฟล์เสียงว่างเปล่า%'
    OR error_message LIKE '%Transcription produced no text%');
