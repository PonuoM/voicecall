<?php
/**
 * One-off repair: re-runs the analysis step (UnifiedPipelineAgent) for conversations whose stored
 * summary/violations/fraud_checks/extracted_entities contain U+FFFD - MiniMax substituting the
 * Unicode replacement character mid-word, now caught and retried by OpenRouterClient::chatJson()
 * (see the OpenRouterClient.php fix). Does not touch audio, transcripts, or transcript_segments -
 * only the analysis-derived tables, which is exactly what the corruption was in.
 *
 * fraud_checks rows a human has already reviewed are preserved (same rule ConversationPipeline's
 * reprocess path follows) - only 'pending' rows are cleared before re-running.
 *
 * Run: php ops/backfill_fffd_analysis.php [limit]
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/Agents/UnifiedPipelineAgent.php';

// config.php's 300s default killed conversation 128 mid-run the first time this was tried -
// this script makes one slow MiniMax call per conversation and needs to run unattended for many
// of them in a row.
set_time_limit(0);

const PROD = ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_voicelog', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'];
const ERP = ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_mini_erp', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'];

function connect($cfg)
{
    return new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$pdo = connect(PROD);
$erp = connect(ERP);

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 500;

$fffd = "LIKE CONCAT('%', UNHEX('EFBFBD'), '%')";
$ids = $pdo->query("
    SELECT conversation_id FROM summaries WHERE
        executive_summary {$fffd} OR key_topics {$fffd} OR action_items {$fffd} OR
        decisions_made {$fffd} OR follow_up_tasks {$fffd} OR customer_intent {$fffd} OR
        customer_sentiment {$fffd} OR important_keywords {$fffd}
    UNION
    SELECT conversation_id FROM violations WHERE evidence {$fffd} OR explanation {$fffd}
    UNION
    SELECT conversation_id FROM fraud_checks WHERE purpose {$fffd} OR evidence {$fffd} OR explanation {$fffd} OR detected_value {$fffd}
    UNION
    SELECT conversation_id FROM extracted_entities WHERE raw_json {$fffd}
    LIMIT {$limit}
")->fetchAll(PDO::FETCH_COLUMN);

echo "Found " . count($ids) . " conversation(s) with U+FFFD in stored analysis output.\n";

$clearStmts = [];
foreach (['summaries', 'keywords', 'action_items', 'conversation_tags', 'extracted_entities', 'compliance_reports'] as $table) {
    $clearStmts[] = $pdo->prepare("DELETE FROM {$table} WHERE conversation_id = ?");
}
$clearFraudPending = $pdo->prepare("DELETE FROM fraud_checks WHERE conversation_id = ? AND review_status = 'pending'");

$ok = 0;
$fail = 0;
foreach ($ids as $id) {
    $id = (int) $id;
    // external_context does not exist as a column on production's conversations table (only
    // api/external/v1/analyze.php's INSERT references it - ConversationPipeline reads it with a
    // '?? null' fallback, which is why that mismatch never surfaced as an error there).
    $conv = $pdo->prepare('SELECT company_id, full_text FROM conversations c JOIN transcripts t ON t.conversation_id = c.id WHERE c.id = ?');
    $conv->execute([$id]);
    $row = $conv->fetch();
    if (!$row) {
        echo "conversation {$id}: no transcript found, skipping\n";
        $fail++;
        continue;
    }

    $contextString = null;

    echo "conversation {$id}: ";
    try {
        foreach ($clearStmts as $stmt) {
            $stmt->execute([$id]);
        }
        $clearFraudPending->execute([$id]);

        UnifiedPipelineAgent::run($pdo, $erp, $id, (int) $row['company_id'], $row['full_text'], $contextString);
        echo "OK\n";
        $ok++;
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        file_put_contents(LOG_DIR . '/backfill_fffd_analysis.log', date('Y-m-d H:i:s') . " conversation={$id} " . $e->getMessage() . "\n", FILE_APPEND);
        $fail++;
    }
}

echo "\nDone. ok={$ok} fail={$fail}\n";
