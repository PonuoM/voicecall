<?php
/**
 * Smart sampling worker: picks risk-first + random unprocessed recordings
 * (SmartSamplingService) and runs them through the paid AI pipeline — under a hard budget cap.
 *
 * Budget guard: before EVERY paid call it re-reads live usage_daily from the OpenRouter /key
 * endpoint and stops the moment SAMPLING_BUDGET_USD_PER_DAY is reached. The cap therefore holds
 * across multiple runs per day and even if another process spends from the same key
 * concurrently. (usage_daily resets at UTC midnight ≈ 07:00 Thai time.)
 *
 * Run via CLI:
 *   php api/cron/smart_sampling.php            # pick + process under budget
 *   php api/cron/smart_sampling.php --dry-run  # show what WOULD be picked, spend nothing
 * Suggested schedule: hourly during work hours — cheap no-ops once the daily budget is spent.
 *
 * Tunables (see config.php): SAMPLING_BUDGET_USD_PER_DAY, SAMPLING_MAX_CALLS_PER_RUN,
 * SAMPLING_MIN_DURATION_SECONDS, SAMPLING_RANDOM_SHARE.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/SmartSamplingService.php';
require_once __DIR__ . '/../Services/OpenRouterClient.php';
require_once __DIR__ . '/../Pipeline/ConversationPipeline.php';

// Same guard as the other cron scripts: CLI always allowed; HTTP needs CRON_HTTP_KEY. This one
// spends real OpenRouter credits, so the guard actually matters.
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

$dryRun = in_array('--dry-run', $argv ?? [], true) || !empty($_GET['dry_run']);

// Optional per-invocation cap (--max=N / ?max=N), never above SAMPLING_MAX_CALLS_PER_RUN —
// lets a manual/HTTP trigger do a small verification batch without editing config.
$maxCalls = SAMPLING_MAX_CALLS_PER_RUN;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--max=(\d+)$/', $arg, $m)) {
        $maxCalls = (int) $m[1];
    }
}
if (!empty($_GET['max'])) {
    $maxCalls = (int) $_GET['max'];
}
$maxCalls = max(1, min(SAMPLING_MAX_CALLS_PER_RUN, $maxCalls));

$pdo = db_connect();
$erp = erp_connect();

function log_line(string $msg): void
{
    $line = date('Y-m-d H:i:s') . " {$msg}\n";
    echo $line;
    file_put_contents(LOG_DIR . '/smart_sampling.log', $line, FILE_APPEND);
}

/**
 * What limits a run.
 *
 * The dollar cap was written when every transcription was billed per call and OpenRouter's
 * usage_daily was the only number that mattered. Both halves of that have moved: transcription runs
 * on hardware we already pay for, and analysis is a flat monthly subscription. Reading OpenRouter's
 * daily spend now measures embeddings and nothing else — it would sit near zero forever and cap
 * nothing, while making every run depend on an account whose balance is almost gone.
 *
 * When transcription is self-hosted the binding limits are volume, not money: MiniMax's rolling
 * quota, the VPS sharing two cores with a chat and video stack, and Google Drive's abuse
 * interstitial, which trips on bursts of downloads from one IP. A daily call ceiling covers all
 * three, and pacing the runs covers the third on its own.
 *
 * @return array{blocked:bool,reason:string,used:float,cap:float,unit:string}
 */
function sampling_budget_state(PDO $pdo): array
{
    if (OpenRouterClient::sttIsSelfHosted()) {
        $cap = (float) (getenv('SAMPLING_MAX_CALLS_PER_DAY') ?: 800);
        $row = fetch_one($pdo, "
            SELECT COUNT(*) AS n FROM audit_log
            WHERE action = 'smart_sample' AND created_at >= CURDATE()
        ", []);
        $used = (float) ($row['n'] ?? 0);
        return [
            'blocked' => $used >= $cap,
            'reason' => sprintf('%d / %d calls today', (int) $used, (int) $cap),
            'used' => $used,
            'cap' => $cap,
            'unit' => 'calls',
        ];
    }

    $usage = OpenRouterClient::keyUsage();
    return [
        'blocked' => $usage['usage_daily'] >= SAMPLING_BUDGET_USD_PER_DAY,
        'reason' => sprintf('$%.4f / $%.2f today', $usage['usage_daily'], SAMPLING_BUDGET_USD_PER_DAY),
        'used' => $usage['usage_daily'],
        'cap' => SAMPLING_BUDGET_USD_PER_DAY,
        'unit' => 'usd',
    ];
}

// Checked up front so a run that is already at its ceiling does no selection work.
$budget = sampling_budget_state($pdo);
if ($budget['blocked']) {
    log_line('limit reached: ' . $budget['reason'] . ' — nothing to do');
    exit(0);
}

$picked = SmartSamplingService::pickCandidates($pdo, $erp, $maxCalls);
log_line(sprintf('picked %d candidates (%s)%s',
    count($picked), $budget['reason'], $dryRun ? ' [DRY RUN]' : ''));

$processed = 0;
$failed = 0;

foreach ($picked as $cand) {
    $label = sprintf('%s %s %s %ds [%s]',
        $cand['call_code'] ?: $cand['gdrive_file_id'], $cand['call_date'], $cand['caller_phone'] ?: '-',
        (int) $cand['duration_seconds'], $cand['sample_reason']);

    if ($dryRun) {
        log_line("DRY: would process {$label}");
        continue;
    }

    // Re-checked before every call, not just at the top: several runs can overlap, and the point of
    // the ceiling is that it holds across all of them rather than per-run.
    $budget = sampling_budget_state($pdo);
    if ($budget['blocked']) {
        log_line(sprintf('limit reached mid-run (%s) — stopping after %d calls', $budget['reason'], $processed));
        break;
    }

    try {
        $conversationId = SmartSamplingService::registerCandidate($pdo, $erp, $cand);
        $status = fetch_one($pdo, 'SELECT status FROM conversations WHERE id = ?', [$conversationId]);
        if ($status && $status['status'] === 'completed') {
            log_line("skip conv {$conversationId} (already completed): {$label}");
            continue;
        }
        $result = ConversationPipeline::run($pdo, $erp, $conversationId);
        if ($result['ok']) {
            $processed++;
            log_line("processed conv {$conversationId}: {$label}");
        } else {
            $failed++;
            log_line("FAILED conv {$conversationId}: {$label} — {$result['error']}");
        }
    } catch (Throwable $e) {
        $failed++;
        log_line("ERROR {$label} — " . $e->getMessage());
    }
}

$usage = $dryRun ? $usage : OpenRouterClient::keyUsage();
log_line(sprintf('done: processed=%d failed=%d spent_today=$%.4f', $processed, $failed, $usage['usage_daily']));
