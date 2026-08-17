<?php
/**
 * Batch worker: processes conversations sitting in 'pending' status through the full AI
 * pipeline (STT -> Understanding -> Extraction -> Compliance -> Indexing). Run via CLI:
 *   php api/cron/process_pending.php [limit]
 * or schedule with Windows Task Scheduler. Intended for the backlog of calls that were
 * bulk-registered (free, no AI cost) but never individually clicked "Analyze" in the dashboard.
 *
 * Cost control: skips calls estimated under MIN_DURATION_SECONDS (GSM 6.10 byte-rate heuristic,
 * same one the frontend uses for the duration column) — short/unanswered calls rarely have
 * enough content to be worth transcribing. They stay 'pending' so a human can still process them
 * individually from the dashboard if they want to.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Pipeline/ConversationPipeline.php';

// This lives under a public web root (api/cron/) and spends real OpenRouter credits per call it
// processes - anyone who guesses the URL could otherwise trigger paid AI runs on demand. CLI (the
// real cron path) is always allowed; HTTP access requires CRON_HTTP_KEY (set in .env).
if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die("Forbidden\n");
    }
}

const MIN_DURATION_SECONDS = 10;
const GSM_BYTES_PER_SECOND = 1625;
const MIN_SIZE_BYTES = 60 + MIN_DURATION_SECONDS * GSM_BYTES_PER_SECOND;

$limit = isset($argv[1]) ? max(1, (int) $argv[1]) : 20;

$pdo = db_connect();
$erp = erp_connect();

$stmt = $pdo->prepare("SELECT id, call_code, size_bytes FROM conversations WHERE status = 'pending' ORDER BY created_at ASC LIMIT {$limit}");
$stmt->execute();
$rows = $stmt->fetchAll();

if (empty($rows)) {
    echo "No pending conversations.\n";
    exit(0);
}

$processed = 0;
$skipped = 0;
foreach ($rows as $row) {
    $id = (int) $row['id'];
    $size = $row['size_bytes'] !== null ? (int) $row['size_bytes'] : null;

    if ($size !== null && $size < MIN_SIZE_BYTES) {
        echo "Conversation {$id} ({$row['call_code']}): skipped, estimated under " . MIN_DURATION_SECONDS . "s ({$size} bytes)\n";
        $skipped++;
        continue;
    }

    // Claim the row before touching it. Selecting pending rows and processing them is safe only
    // while exactly one run exists; the moment a scheduled cron overlaps a manual trigger, both see
    // the same ids. That happened: conversation 95 was picked up twice, one run wrote its summary
    // and the other hit "Duplicate entry '95' for key 'uq_summary_conv'" and marked the row failed —
    // leaving a conversation with a complete transcript and analysis sitting in the failure list.
    //
    // The UPDATE is the lock: only one connection can move a row out of 'pending', and whoever
    // loses sees zero affected rows and moves on. ConversationPipeline sets the same status itself,
    // so nothing downstream changes.
    $claim = $pdo->prepare("UPDATE conversations SET status = 'transcribing' WHERE id = ? AND status = 'pending'");
    $claim->execute([$id]);
    if ($claim->rowCount() === 0) {
        echo "Conversation {$id} ({$row['call_code']}): already claimed by another run, skipping\n";
        $skipped++;
        continue;
    }

    echo "Conversation {$id} ({$row['call_code']}): processing... ";
    $result = ConversationPipeline::run($pdo, $erp, $id);
    echo $result['ok'] ? "OK\n" : "FAILED: {$result['error']}\n";
    $processed++;
}

echo "Done. Processed {$processed}, skipped {$skipped} (too short).\n";
