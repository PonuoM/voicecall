<?php
/**
 * Order cross-check sweep (fraud: missing_order + price_mismatch).
 *
 * Picks completed conversations that are old enough for a fair verdict (an order can be keyed
 * days after the call — GRACE_DAYS), young enough to still matter (MAX_AGE_DAYS), matched to an
 * ERP customer, and not yet checked — then lets OrderCrossCheckService compare what was said in
 * the call against primacom_mini_erp.orders. Pure SQL, no LLM cost, safe to run often.
 *
 * Run via CLI:
 *   php api/cron/fraud_order_check.php
 * Suggested schedule: daily. Conversations with no purchase signal are re-scanned each run until
 * they age out of MAX_AGE_DAYS — that's intentional (cheap) and keeps the query simple.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/OrderCrossCheckService.php';

// Same guard as sync_gdrive_index.php: CLI always allowed, HTTP needs CRON_HTTP_KEY.
if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

set_time_limit(0);

$pdo = db_connect();
$erp = erp_connect();

$rows = fetch_all($pdo, "
    SELECT c.id, c.company_id, c.call_code, c.call_date, c.erp_customer_id,
           e.price, e.order_info, e.sale_outcome
    FROM conversations c
    JOIN extracted_entities e ON e.conversation_id = c.id
    WHERE c.status = 'completed'
      AND c.erp_customer_id IS NOT NULL
      AND c.call_date IS NOT NULL
      AND c.call_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND c.call_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
      AND NOT EXISTS (
          SELECT 1 FROM fraud_checks f
          WHERE f.conversation_id = c.id AND f.check_type IN ('missing_order','price_mismatch')
      )
    ORDER BY c.call_date
", [OrderCrossCheckService::GRACE_DAYS, OrderCrossCheckService::MAX_AGE_DAYS]);

$scanned = 0;
$checked = 0;
$flagged = 0;

foreach ($rows as $row) {
    $scanned++;
    try {
        $result = OrderCrossCheckService::runForConversation($pdo, $erp, $row, $row);
        if ($result['checked']) {
            $checked++;
            $flagged += $result['flagged'];
            echo "conv {$row['id']} ({$row['call_code']} {$row['call_date']}): checked, flagged={$result['flagged']}\n";
        }
    } catch (Throwable $e) {
        file_put_contents(LOG_DIR . '/fraud_error.log', date('Y-m-d H:i:s') . " order_check conversation={$row['id']} " . $e->getMessage() . "\n", FILE_APPEND);
        echo "conv {$row['id']}: ERROR {$e->getMessage()}\n";
    }
}

$summary = date('Y-m-d H:i:s') . " fraud_order_check: scanned={$scanned} checked={$checked} flagged={$flagged}\n";
echo $summary;
file_put_contents(LOG_DIR . '/fraud_order_check.log', $summary, FILE_APPEND);
