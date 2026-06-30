-- Adds traceability columns so entity extraction can ground itself against real
-- primacom_mini_erp data (products catalog + nearby orders) instead of guessing product
-- names/prices purely from a noisy STT transcript. See api/Services/ErpLookupService.php.
USE voicecall_ai;

ALTER TABLE extracted_entities
  ADD COLUMN matched_product_id INT NULL COMMENT 'primacom_mini_erp.products.id, if AI matched the spoken product to the real catalog',
  ADD COLUMN matched_product_name VARCHAR(255) NULL COMMENT 'cached real product name at match time',
  ADD COLUMN matched_product_price DECIMAL(12,2) NULL COMMENT 'cached real product price at match time',
  ADD COLUMN linked_order_id VARCHAR(32) NULL COMMENT 'primacom_mini_erp.orders.id, if this call discusses/confirms a real nearby order',
  ADD COLUMN linked_order_date DATETIME NULL COMMENT 'cached order_date at match time',
  ADD COLUMN linked_order_total DECIMAL(12,2) NULL COMMENT 'cached total_amount at match time',
  ADD KEY idx_entities_matched_product (matched_product_id),
  ADD KEY idx_entities_linked_order (linked_order_id);
