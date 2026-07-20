-- Order cross-check (ตรวจ "ปิดการขายแล้วแต่ไม่มี order" + "ราคาที่พูดไม่ตรงกับที่คีย์").
-- Reuses fraud_checks with two new check_type values written by OrderCrossCheckService via
-- api/cron/fraud_order_check.php (NOT inline in the pipeline — an order legitimately appears
-- hours/days after the call, so the verdict must wait GRACE_DAYS):
--   'missing_order'  — AI heard a closed sale but no ERP order appeared within the window
--   'price_mismatch' — spoken total vs primacom_mini_erp.orders.total_amount differ

-- New source value for rows whose verdict comes from ERP order comparison
ALTER TABLE fraud_checks
  MODIFY source ENUM('llm','regex','erp') NOT NULL DEFAULT 'llm'
  COMMENT 'llm = UnifiedPipelineAgent extraction, regex = transcript digit-run net, erp = order cross-check';

-- Sale outcome as heard by the LLM (closed_won|follow_up|declined|not_sales_call) — drives the
-- missing_order check. NULL on rows analyzed before this migration (heuristic fallback applies).
ALTER TABLE extracted_entities
  ADD COLUMN sale_outcome VARCHAR(20) NULL AFTER priority;
