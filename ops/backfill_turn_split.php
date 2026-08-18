<?php
/**
 * One-off repair: re-splits transcript_segments for calls that landed as a single collapsed
 * block before SttAgent chunked the turn-split call (see the SttAgent.php commit that fixed
 * this). Does not touch audio or transcripts.full_text - both were always correct, only the
 * turn split degenerated. Safe to re-run: it recomputes the affected-id list fresh each time
 * and only touches conversations still matching the "1 segment despite a long transcript" shape.
 *
 * Run: php ops/backfill_turn_split.php [limit]
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/Agents/SttAgent.php';

// config.php's 300s default is well under what a single multi-chunk turn split can take -
// this script needs to run unattended through many conversations in a row.
set_time_limit(0);

const PROD = ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_voicelog', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'];

$pdo = new PDO(
    "mysql:host=" . PROD['host'] . ";port=" . PROD['port'] . ";dbname=" . PROD['db'] . ";charset=utf8mb4",
    PROD['user'],
    PROD['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 500;

$rows = $pdo->query("
    SELECT c.id conversation_id, c.direction, c.erp_employee_name, c.erp_customer_name,
           t.id transcript_id, t.full_text
    FROM transcript_segments ts
    JOIN transcripts t ON t.conversation_id = ts.conversation_id
    JOIN conversations c ON c.id = ts.conversation_id
    WHERE CHAR_LENGTH(t.full_text) > 300
    GROUP BY ts.conversation_id
    HAVING COUNT(*) = 1
    LIMIT {$limit}
")->fetchAll();

echo "Found " . count($rows) . " conversation(s) with a collapsed transcript split.\n";

$ok = 0;
$fail = 0;
foreach ($rows as $row) {
    $id = (int) $row['conversation_id'];
    $conversation = [
        'id' => $id,
        'direction' => $row['direction'],
        'erp_employee_name' => $row['erp_employee_name'],
        'erp_customer_name' => $row['erp_customer_name'],
    ];
    echo "conversation {$id} (len=" . mb_strlen($row['full_text']) . "): ";
    try {
        $result = SttAgent::resplitExistingTranscript($pdo, $conversation, (int) $row['transcript_id'], $row['full_text']);
        echo "OK, {$result['turn_count']} turns\n";
        $ok++;
    } catch (Throwable $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
        file_put_contents(LOG_DIR . '/backfill_turn_split.log', date('Y-m-d H:i:s') . " conversation={$id} " . $e->getMessage() . "\n", FILE_APPEND);
        $fail++;
    }
}

echo "\nDone. ok={$ok} fail={$fail}\n";
