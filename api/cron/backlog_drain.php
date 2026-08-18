<?php
/**
 * Drains the whole recording backlog, oldest first, until every indexed file has an outcome.
 *
 * smart_sampling answers a different question. It was built when transcription was billed per call,
 * so it audits a slice: risk buckets, a reserved random share, and a seven-day window past which a
 * recording is never eligible again. That made sense at 15-39k THB/month. It does not now —
 * transcription runs on hardware we already own — and it leaves 128,176 recordings older than a
 * week permanently invisible, arriving faster than they are sampled.
 *
 * This drains instead of samples. Newest first, no shuffle, no window: work backwards through the
 * index until it is done. That takes a while — 211,116 files — but it finishes, and while it runs
 * you can tell how far along it is by counting.
 *
 * Newest first because recent calls are the ones anyone will act on. A complaint from yesterday is
 * worth surfacing today; one from June 2025 is history either way. Draining from the old end would
 * spend the first weeks on recordings nobody is going to do anything about while today's kept
 * piling up behind them.
 *
 * The rule that makes it legible: every file ends in a terminal state. Recordings under the
 * duration threshold get a row saying so instead of being filtered out before registration, because
 * a filtered file and a forgotten file look identical from the outside. After this, a recording with
 * no conversation row means one thing only — not reached yet.
 *
 *   php api/cron/backlog_drain.php [limit]
 *   php api/cron/backlog_drain.php --status      # progress only, touches nothing
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/SmartSamplingService.php';
require_once __DIR__ . '/../Pipeline/ConversationPipeline.php';

set_time_limit(0);
ini_set('memory_limit', '512M');

if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$statusOnly = in_array('--status', $argv ?? [], true) || isset($_GET['status']);
$limit = (int) ($argv[1] ?? $_GET['limit'] ?? getenv('BACKLOG_CALLS_PER_RUN') ?: 10);
$limit = max(1, min(200, $limit));

// Short recordings are disposed of far faster than long ones — no download, no model — so a run can
// clear a lot of them without holding the pipeline up.
$shortBatch = (int) (getenv('BACKLOG_SHORT_BATCH') ?: 2000);

$pdo = db_connect();

function drain_log(string $msg): void
{
    $line = date('Y-m-d H:i:s') . " {$msg}\n";
    echo $line;
    @file_put_contents(LOG_DIR . '/backlog_drain.log', $line, FILE_APPEND);
}

function drain_progress(PDO $pdo): array
{
    return fetch_one($pdo, "
        SELECT
            (SELECT COUNT(*) FROM gdrive_file_index) AS indexed,
            (SELECT COUNT(*) FROM conversations WHERE source = 'gdrive') AS registered,
            (SELECT COUNT(*) FROM conversations WHERE source = 'gdrive' AND status = 'completed') AS completed,
            (SELECT COUNT(*) FROM conversations WHERE source = 'gdrive' AND status = 'skipped') AS skipped,
            (SELECT COUNT(*) FROM conversations WHERE source = 'gdrive' AND status = 'failed') AS failed
    ", []);
}

$p = drain_progress($pdo);
$done = (int) $p['completed'] + (int) $p['skipped'] + (int) $p['failed'];
$remaining = max(0, (int) $p['indexed'] - $done);
drain_log(sprintf('progress: %s/%s done (%.2f%%) — completed=%s skipped=%s failed=%s, remaining=%s',
    number_format($done), number_format((int) $p['indexed']),
    $p['indexed'] ? $done / (int) $p['indexed'] * 100 : 0,
    number_format((int) $p['completed']), number_format((int) $p['skipped']),
    number_format((int) $p['failed']), number_format($remaining)));

if ($statusOnly) {
    exit(0);
}

// ---- Phase 1: recordings too short to hold a conversation -------------------------------------
// Decided from the index alone. Downloading a 9-second file to confirm it is 9 seconds long would
// spend a Drive request to learn something already known, and Drive rate-limits by IP.
$short = fetch_all($pdo, '
    SELECT g.gdrive_file_id, g.company_id, g.call_code, g.call_date, g.call_time,
           g.caller_phone, g.receiver_phone, g.direction, g.size_bytes, g.duration_seconds
    FROM gdrive_file_index g
    LEFT JOIN conversations c ON c.source = \'gdrive\' AND c.audio_ref = g.gdrive_file_id
    WHERE c.id IS NULL
      AND g.duration_seconds < ?
      AND g.company_id IS NOT NULL
    ORDER BY g.call_date DESC, g.call_time DESC
    LIMIT ' . $shortBatch . '
', [SAMPLING_MIN_DURATION_SECONDS]);

if ($short) {
    // No ERP enrichment here on purpose: these are never analysed, and a customer lookup each for
    // tens of thousands of rows would cost hours against the remote ERP for data nothing reads.
    $insert = $pdo->prepare('
        INSERT INTO conversations
            (company_id, source, audio_ref, call_code, call_date, call_time, caller_phone,
             receiver_phone, direction, size_bytes, duration_seconds, status, error_message)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ');
    $reason = sprintf('สายสั้นกว่า %d วินาที — ข้ามตามกฎ ไม่ส่งเข้า AI', SAMPLING_MIN_DURATION_SECONDS);

    $pdo->beginTransaction();
    try {
        foreach ($short as $row) {
            $insert->execute([
                (int) $row['company_id'], 'gdrive', $row['gdrive_file_id'], $row['call_code'],
                $row['call_date'], $row['call_time'], $row['caller_phone'], $row['receiver_phone'],
                $row['direction'], $row['size_bytes'], $row['duration_seconds'], 'skipped', $reason,
            ]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        drain_log('short-batch failed, rolled back: ' . $e->getMessage());
    }
    drain_log(sprintf('marked %d short recordings skipped (newest %s)', count($short), $short[0]['call_date']));
}

// ---- Phase 2: everything long enough to be worth transcribing ----------------------------------
$candidates = fetch_all($pdo, '
    SELECT g.*
    FROM gdrive_file_index g
    LEFT JOIN conversations c ON c.source = \'gdrive\' AND c.audio_ref = g.gdrive_file_id
    WHERE c.id IS NULL
      AND g.duration_seconds >= ?
      AND g.company_id IS NOT NULL
    ORDER BY g.call_date DESC, g.call_time DESC
    LIMIT ' . $limit . '
', [SAMPLING_MIN_DURATION_SECONDS]);

if (!$candidates) {
    drain_log('no recordings left to transcribe — backlog drained');
    exit(0);
}

$erp = erp_connect();  // erp() lives in core/bootstrap.php, which cron scripts do not load
$counts = ['completed' => 0, 'skipped' => 0, 'failed' => 0];

foreach ($candidates as $cand) {
    $cand['sample_reason'] = 'backlog';
    $label = sprintf('%s %s %s %ds',
        $cand['call_code'] ?: $cand['gdrive_file_id'], $cand['call_date'],
        $cand['caller_phone'] ?: '-', (int) $cand['duration_seconds']);

    try {
        $conversationId = SmartSamplingService::registerCandidate($pdo, $erp, $cand);

        // Same claim the pending worker uses. Several runs can overlap — a timer firing while
        // someone triggers a run by hand — and without this both process the same recording, which
        // ends with one of them hitting a duplicate-key error and marking a finished call failed.
        $claim = $pdo->prepare("UPDATE conversations SET status = 'transcribing' WHERE id = ? AND status = 'pending'");
        $claim->execute([$conversationId]);
        if ($claim->rowCount() === 0) {
            drain_log("skip conv {$conversationId} (claimed elsewhere or already done): {$label}");
            continue;
        }

        $result = ConversationPipeline::run($pdo, $erp, $conversationId);
        $status = $result['status'] ?? 'failed';
        $counts[$status] = ($counts[$status] ?? 0) + 1;

        if ($status === 'skipped') {
            drain_log("skipped conv {$conversationId}: {$label} — " . ($result['reason'] ?? ''));
        } elseif ($status === 'completed') {
            drain_log("completed conv {$conversationId}: {$label}");
        } else {
            drain_log("FAILED conv {$conversationId}: {$label} — " . ($result['error'] ?? 'unknown'));
        }
    } catch (Throwable $e) {
        $counts['failed']++;
        drain_log("ERROR {$label} — " . $e->getMessage());
    }
}

$p = drain_progress($pdo);
$done = (int) $p['completed'] + (int) $p['skipped'] + (int) $p['failed'];
drain_log(sprintf('run done: completed=%d skipped=%d failed=%d | overall %s/%s (%.2f%%)',
    $counts['completed'], $counts['skipped'], $counts['failed'],
    number_format($done), number_format((int) $p['indexed']),
    $p['indexed'] ? $done / (int) $p['indexed'] * 100 : 0));
