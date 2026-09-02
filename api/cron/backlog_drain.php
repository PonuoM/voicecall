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
require_once __DIR__ . '/../Services/UnknownNumberService.php';

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

// The scheduler that fires this over HTTP (ops/voicecall-worker.sh) gives up waiting after 1500s.
// A $limit of 10 does not bound wall-clock time - MiniMax's turn-split call alone can run past ten
// minutes on a long transcript, so a batch of a few such calls blew straight through that ceiling
// every single cycle, every candidate it had already claimed left stuck mid-pipeline for
// reap_stale to eventually notice. Stopping well inside the caller's timeout, instead of trying to
// guess a $limit small enough to always fit, means a run always finishes cleanly and reports what
// it did - the next cycle picks up exactly where this one left off.
const RUN_TIME_BUDGET_SECONDS = 1200;
$runStartedAt = microtime(true);
function backlog_time_left(): float
{
    global $runStartedAt;
    return RUN_TIME_BUDGET_SECONDS - (microtime(true) - $runStartedAt);
}

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

/**
 * MemAvailable of the whole host, in MB ('-' where /proc is not a thing, e.g. the Windows dev
 * box). Stamped onto every cycle's summary lines because this host was OOM-killing processes
 * for a week (27 Aug - 2 Sep 2026) and nobody could say when pressure actually built or how
 * fast - the drain runs around the clock anyway, so its log doubles as a free 24/7 memory
 * time series without adding any endpoint or cron.
 */
function host_mem_available(): string
{
    // Direct read first; open_basedir fences PHP out of /proc here, so fall back to a shell,
    // which open_basedir does not reach. Both failed on the first live readings (10:25/10:50,
    // 2 Sep) as a bare '-', which said nothing about WHY - so each dead end now names itself:
    //   -noshell    open_basedir blocked the read and shell_exec is disabled/absent
    //   -shellempty the shell ran but produced nothing (CageFS-restricted /proc, most likely)
    //   -noline     meminfo was readable but carries no MemAvailable line (ancient kernel)
    $mi = @file_get_contents('/proc/meminfo');
    if ($mi === false) {
        if (!function_exists('shell_exec')) {
            return '-noshell';
        }
        $mi = @shell_exec('cat /proc/meminfo 2>/dev/null');
        if (!is_string($mi) || trim($mi) === '') {
            return '-shellempty';
        }
    }
    if (!preg_match('/^MemAvailable:\s+(\d+)/m', $mi, $m)) {
        return '-noline';
    }
    return round((int) $m[1] / 1024) . 'MB';
}

/**
 * 1-minute load average via syscall - no file access, no shell, so none of the fences above
 * apply. Not a memory number, but a thrashing host shows up as a load spike, so it still
 * timestamps "when the box went bad" if meminfo stays unreachable.
 */
function host_load(): string
{
    $la = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
    return is_array($la) ? sprintf('%.2f', $la[0]) : '-';
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
drain_log(sprintf('progress: %s/%s done (%.2f%%) — completed=%s skipped=%s failed=%s, remaining=%s | mem_avail=%s',
    number_format($done), number_format((int) $p['indexed']),
    $p['indexed'] ? $done / (int) $p['indexed'] * 100 : 0,
    number_format((int) $p['completed']), number_format((int) $p['skipped']),
    number_format((int) $p['failed']), number_format($remaining), host_mem_available() . ' load=' . host_load()));

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

// A human decision, not a heuristic run fresh every batch — see UnknownNumberController and
// ui/unknown_numbers.html. Loaded once per run, one query per company actually present in this
// batch, not per candidate.
$unknownSkipSets = [];
foreach ($candidates as $cand) {
    $companyId = (int) $cand['company_id'];
    if (!isset($unknownSkipSets[$companyId])) {
        $unknownSkipSets[$companyId] = UnknownNumberService::skipSet($pdo, $companyId);
    }
}

foreach ($candidates as $cand) {
    if (backlog_time_left() <= 0) {
        drain_log('time budget reached — stopping this run early, next cycle continues from here');
        break;
    }

    // abuse_interstitial blocks the whole IP, not one file - the first candidate to hit it already
    // proved that, so claiming and failing every remaining candidate the same way just multiplies
    // one outage into dozens of 'failed' rows. Leave them 'pending' and let a later run, once the
    // cooldown AudioFetcher recorded has passed, pick up exactly where this one stopped.
    $driveBlockedUntil = AudioFetcher::driveCircuitOpen();
    if ($driveBlockedUntil !== null) {
        drain_log('Drive is in an abuse_interstitial cooldown until ' . date('H:i:s', $driveBlockedUntil) . ' — stopping this run early');
        break;
    }

    // The analysis provider is checked here rather than at the analysis step for the same reason,
    // only more so: by the time that step runs, this recording has already been downloaded from
    // Drive and fully transcribed. Claiming one while the quota is out spends all of that to reach
    // a refusal that was already known — 1,463 times over on the evening of 19 Aug 2026.
    $analysisBlockedUntil = OpenRouterClient::analysisCircuitOpen();
    if ($analysisBlockedUntil !== null) {
        drain_log('Analysis provider unavailable (' . OpenRouterClient::analysisCircuitReason() . ') until '
            . date('H:i:s', $analysisBlockedUntil) . ' — stopping this run early');
        break;
    }

    $companyId = (int) $cand['company_id'];
    $skipSet = $unknownSkipSets[$companyId];
    // Must go through UnknownNumberService::normalize(), not a hand-rolled digit-strip: Drive
    // stores every number as +66XXXXXXXXXX, and a first attempt here that only stripped
    // non-digits produced "66945554066" against a skipSet keyed "0945554066" -- never matching,
    // for every single call, silently. This is the same normalizer that built the set.
    $callerNormalized = UnknownNumberService::normalize((string) $cand['caller_phone']);
    $callerKnownBad = $callerNormalized !== null && isset($skipSet[$callerNormalized]);
    if ($callerKnownBad) {
        // Same shape as the short-recording skip in Phase 1: registered directly as 'skipped', no
        // Drive download, no Typhoon, no MiniMax — the queue was told this line is not our
        // business and the point was to stop spending on it.
        $conversationId = SmartSamplingService::registerCandidate($pdo, $erp, $cand + ['sample_reason' => 'backlog']);
        $pdo->prepare("UPDATE conversations SET status = 'skipped', error_message = ? WHERE id = ? AND status = 'pending'")
            ->execute(['เบอร์นี้ถูกทำเครื่องหมายว่าไม่ใช่ธุรกิจของบริษัท (ดูหน้า เบอร์นอกระบบ) — ข้ามตามการตัดสินใจของผู้ดูแล', $conversationId]);
        $counts['skipped']++;
        drain_log("skipped conv {$conversationId} (marked unknown-number skip): {$cand['caller_phone']}");
        continue;
    }

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
        } elseif ($status === 'deferred') {
            // Back in the queue, not burnt. Logged distinctly because "FAILED" here would say the
            // recording is a problem when the only problem was Drive being unreachable this minute.
            drain_log("deferred conv {$conversationId}: {$label} — " . ($result['error'] ?? ''));
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
drain_log(sprintf('run done: completed=%d skipped=%d failed=%d deferred=%d | overall %s/%s (%.2f%%) | mem_avail=%s',
    $counts['completed'], $counts['skipped'], $counts['failed'], $counts['deferred'] ?? 0,
    number_format($done), number_format((int) $p['indexed']),
    $p['indexed'] ? $done / (int) $p['indexed'] * 100 : 0, host_mem_available() . ' load=' . host_load()));
