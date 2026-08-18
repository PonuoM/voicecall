-- The new Timeline page (ui/timeline.html, timeline.php) filters conversations by company_id and
-- orders by updated_at. Neither existing index covers that: idx_conv_company alone forces a
-- filesort over every row for the company, and status barely narrows anything — 52,329 of 52,330
-- rows on production sit in a terminal status (completed/failed/skipped), so a composite index
-- that leads with status buys almost nothing. company_id+updated_at lets MySQL walk the index
-- already in the right order and stop at LIMIT instead of sorting the whole result set.
--
-- Applied directly to production already via ops/db.php (DDL, not Thai text - no mysql.exe
-- corruption risk) since this needed EXPLAIN against real data volume to size correctly. This
-- file documents it for any environment rebuilt from these migrations.

ALTER TABLE conversations ADD INDEX idx_conv_company_updated (company_id, updated_at);
