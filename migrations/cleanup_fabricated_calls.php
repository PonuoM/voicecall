<?php
/**
 * One-shot cleanup: wipe AI outputs that were fabricated by the old pipeline (pre-AudioQuality
 * guard). Re-runs the exact same loop-detection logic as AudioQuality::loopReason() against every
 * stored transcript, lists everything that would be deleted, and ONLY deletes when --apply is
 * passed AND the user confirms at the prompt (or --yes is passed).
 *
 * The guard is forward-looking (it stops new bad data from being written), but the 11 fabricated
 * conversations plus the 2 escalated fraud_checks on conv 73/76 are already in the DB and have
 * to be removed by hand. This script is the hand.
 *
 * Run from the project root:
 *   php migrations/cleanup_fabricated_calls.php                # dry-run, list only
 *   php migrations/cleanup_fabricated_calls.php --apply       # prompt then delete
 *   php migrations/cleanup_fabricated_calls.php --apply --yes # no prompt
 *   php migrations/cleanup_fabricated_calls.php --conv=73,76  # only specific ids
 *
 * Exit codes:
 *   0  success (or dry-run with nothing to do)
 *   1  user cancelled
 *   2  error
 *
 * Wrapped in a transaction: any failure rolls back, never partial-deletes.
 */

require_once __DIR__ . '/../api/config.php';

// CLI only — refuse to run if invoked through a web request. The script deletes rows, that should
// never happen because someone hit the URL.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

// ---- Args --------------------------------------------------------------------------------------
$args = parseArgs($argv);
$apply = $args['apply'] ?? false;
$yes = $args['yes'] ?? false;
$onlyConv = isset($args['conv']) ? array_filter(array_map('intval', explode(',', (string) $args['conv']))) : [];

// Thresholds — MUST match AudioQuality::loopReason() so this script flags the same set the guard
// would have flagged. Drift between these two is the most likely future failure mode of this
// cleanup, so the constants are duplicated here with a comment rather than imported (the script
// is meant to be runnable standalone, and a guard rewrite that loosens the threshold should be
// revisited here too — but the right thing to do at that point is re-baseline, not retune).
$MAX_CHARS_PER_SECOND = 25.0;
$MIN_LINES_FOR_UNIQUENESS_CHECK = 20;
$MIN_UNIQUE_LINE_SHARE = 0.10;

// ---- Connect -----------------------------------------------------------------------------------
try {
    $pdo = db_connect();
} catch (Throwable $e) {
    fwrite(STDERR, "DB connect failed: " . $e->getMessage() . "\n");
    exit(2);
}

if ($onlyConv) {
    echo "Targeted mode: only conversations " . implode(', ', $onlyConv) . "\n\n";
} else {
    echo "Full sweep mode: every transcript re-tested against the loop-detection logic.\n\n";
}

// ---- Find fabricated conversations -------------------------------------------------------------
// Pull (conv, transcript, duration) triples. duration comes from the conversation row (set by
// SttAgent from the WAV header once STT runs) and is the same denominator AudioQuality uses — if
// it's 0 or NULL we skip, because AudioQuality skips too (any rate check on duration=0 would
// divide by zero).
$rows = fetch_all($pdo, '
    SELECT c.id              AS conv_id,
           c.company_id      AS company_id,
           c.call_date       AS call_date,
           c.call_time       AS call_time,
           c.caller_phone    AS caller_phone,
           c.receiver_phone  AS receiver_phone,
           c.erp_employee_id AS erp_employee_id,
           c.erp_employee_name AS erp_employee_name,
           c.erp_customer_name AS erp_customer_name,
           c.direction       AS direction,
           c.duration_seconds AS duration_seconds,
           c.status          AS status,
           t.id              AS transcript_id,
           t.full_text       AS full_text,
           t.word_count      AS word_count
    FROM conversations c
    JOIN transcripts t ON t.conversation_id = c.id
    ' . ($onlyConv ? 'WHERE c.id IN (' . implode(', ', array_fill(0, count($onlyConv), '?')) . ')' : '') . '
    ORDER BY c.id
', $onlyConv);

if (!$rows) {
    echo "No conversations with transcripts found" . ($onlyConv ? ' matching --conv filter' : '') . ".\n";
    exit(0);
}

// Apply loop detection — same arithmetic as AudioQuality::loopReason()
$fabricated = [];
foreach ($rows as $r) {
    $text = (string) $r['full_text'];
    $duration = (float) $r['duration_seconds'];

    $stripped = preg_replace('/\s+/u', '', $text);
    $chars = $stripped === null ? 0 : mb_strlen($stripped, 'UTF-8');
    $rate = ($duration > 0) ? ($chars / $duration) : 0.0;

    $reasons = [];
    if ($duration > 0 && $rate > $MAX_CHARS_PER_SECOND) {
        $reasons[] = sprintf('chars/sec=%.1f (>%.0f)', $rate, $MAX_CHARS_PER_SECOND);
    }

    $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
    if (count($lines) >= $MIN_LINES_FOR_UNIQUENESS_CHECK) {
        $uniqueShare = count(array_unique($lines)) / count($lines);
        if ($uniqueShare < $MIN_UNIQUE_LINE_SHARE) {
            $reasons[] = sprintf('unique=%.0f%% over %d lines (<%.0f%%)',
                $uniqueShare * 100, count($lines), $MIN_UNIQUE_LINE_SHARE * 100);
        }
    }

    if ($reasons) {
        $r['reasons'] = $reasons;
        $r['chars'] = $chars;
        $r['rate'] = $rate;
        $r['line_count'] = count($lines);
        $r['unique_line_count'] = isset($uniqueShare) ? (int) count(array_unique($lines)) : 0;
        $r['preview'] = mb_substr($text, 0, 160, 'UTF-8');
        $fabricated[] = $r;
    }
}

if (!$fabricated) {
    echo "No fabricated transcripts detected. Nothing to clean.\n";
    exit(0);
}

// ---- Show the list ------------------------------------------------------------------------------
echo "════════════════════════════════════════════════════════════════════════════════════════════\n";
echo " FABRICATED TRANSCRIPTS DETECTED: " . count($fabricated) . " conversation(s)\n";
echo "════════════════════════════════════════════════════════════════════════════════════════════\n\n";

foreach ($fabricated as $r) {
    $empTag = $r['erp_employee_name'] ? "พนักงาน: {$r['erp_employee_name']}" : 'พนักงาน: (ไม่ทราบ)';
    $custTag = $r['erp_customer_name'] ? "ลูกค้า: {$r['erp_customer_name']}" : '';
    $fraudFlag = in_array((int) $r['conv_id'], [73, 76], true) ? '  ⚠️  มี fraud_checks ที่กล่าวหาพนักงาน' : '';
    echo "  conv #{$r['conv_id']}  status={$r['status']}  duration={$r['duration_seconds']}s  {$empTag}  {$custTag}{$fraudFlag}\n";
    echo "    reasons: " . implode('; ', $r['reasons']) . "\n";
    echo "    chars=" . number_format($r['chars']) . "  rate=" . sprintf('%.1f', $r['rate']) . "/s  lines={$r['line_count']}  unique={$r['unique_line_count']}\n";
    echo "    preview: " . preg_replace('/\s+/u', ' ', $r['preview']) . (mb_strlen($r['full_text']) > 160 ? '…' : '') . "\n";
    echo "\n";
}

// ---- Show downstream impact per table ----------------------------------------------------------
echo "════════════════════════════════════════════════════════════════════════════════════════════\n";
echo " DOWNSTREAM RECORDS THAT WOULD BE DELETED\n";
echo "════════════════════════════════════════════════════════════════════════════════════════════\n\n";

$convIds = array_column($fabricated, 'conv_id');
$ph = implode(', ', array_fill(0, count($convIds), '?'));

// Per-conversation child-table counts. Most tables have ON DELETE CASCADE on conversation_id, but
// we delete explicitly anyway so (a) the script works even if cascades are dropped, (b) we get
// exact per-table counts to show here, and (c) the FK order is under our control.
$tables = [
    'transcripts'         => 'transcripts (full_text)',
    'transcript_segments' => 'transcript_segments',
    'speakers'            => 'speakers',
    'summaries'           => 'summaries',
    'keywords'            => 'keywords',
    'action_items'        => 'action_items',
    'conversation_tags'   => 'conversation_tags',
    'extracted_entities'  => 'extracted_entities',
    'compliance_reports'  => 'compliance_reports',
    'violations'          => 'violations (via compliance_reports + conversation_id)',
    'knowledge_chunks'    => 'knowledge_chunks',
    'fraud_checks'        => 'fraud_checks ⚠️',
];

$totals = [];
foreach (array_keys($tables) as $table) {
    if ($table === 'violations') {
        // violations has two FK paths to conversations: via report_id and direct. Either delete
        // path covers them, but we DELETE via conversation_id for clarity.
        $totals[$table] = (int) fetch_one($pdo,
            "SELECT COUNT(*) AS n FROM violations WHERE conversation_id IN ({$ph})",
            $convIds)['n'];
    } else {
        $totals[$table] = (int) fetch_one($pdo,
            "SELECT COUNT(*) AS n FROM {$table} WHERE conversation_id IN ({$ph})",
            $convIds)['n'];
    }
}

$totalRows = 0;
foreach ($tables as $table => $label) {
    $n = $totals[$table];
    $totalRows += $n;
    $marker = $n > 0 ? ' ✗ delete' : ' (none)';
    printf("  %-40s %5d row(s)%s\n", $label, $n, $marker);
}
echo "\n  TOTAL ROWS TO DELETE: {$totalRows}\n";

// Highlight fraud_checks specifically — these are the ones actively accusing employees of fraud
// based on a transcript nobody said.
$fraudCount = $totals['fraud_checks'];
if ($fraudCount > 0) {
    echo "\n  ⚠️  fraud_checks: {$fraudCount} accusation(s) currently sit against employees based\n";
    echo "      on text that was never spoken. These would be removed.\n";

    $fraudRows = fetch_all($pdo, "
        SELECT conversation_id, channel_type, detected_value, risk_level, status, review_status
        FROM fraud_checks
        WHERE conversation_id IN ({$ph})
        ORDER BY conversation_id, id
    ", $convIds);

    foreach ($fraudRows as $f) {
        echo "      • conv {$f['conversation_id']}  {$f['channel_type']}={$f['detected_value']}  ";
        echo "risk={$f['risk_level']}  status={$f['status']}  review={$f['review_status']}\n";
    }
}

// Employee impact summary — who would be cleared of what
$impactedEmployees = [];
foreach ($fabricated as $r) {
    if (!empty($r['erp_employee_id']) && !empty($r['erp_employee_name'])) {
        $eid = (int) $r['erp_employee_id'];
        if (!isset($impactedEmployees[$eid])) {
            $impactedEmployees[$eid] = ['name' => $r['erp_employee_name'], 'convs' => []];
        }
        $impactedEmployees[$eid]['convs'][] = (int) $r['conv_id'];
    }
}
if ($impactedEmployees) {
    echo "\n  พนักงานที่ได้รับผลกระทบ:\n";
    foreach ($impactedEmployees as $eid => $info) {
        $fraudTag = '';
        foreach ($info['convs'] as $cid) {
            if (in_array($cid, [73, 76], true)) { $fraudTag = ' ⚠️ fraud_check'; break; }
        }
        echo "      • #{$eid} {$info['name']} — affected conversations: " . implode(', ', $info['convs']) . $fraudTag . "\n";
    }
}

// Conversations themselves — we are NOT deleting these (they are real recordings), only flipping
// status to 'failed' with an error_message so the UI knows they were intentionally never analyzed.
echo "\n  conversations rows themselves: NOT deleted — only status → 'failed', error_message set\n";
echo "  (the audio is real; only the AI output was bogus)\n\n";

if (!$apply) {
    echo "════════════════════════════════════════════════════════════════════════════════════════════\n";
    echo " DRY-RUN — nothing was modified. Pass --apply to actually delete (will prompt first).\n";
    echo "════════════════════════════════════════════════════════════════════════════════════════════\n";
    exit(0);
}

// ---- Confirm -------------------------------------------------------------------------------------------------------------
if (!$yes) {
    echo "About to DELETE {$totalRows} rows across " . count(array_filter($totals)) . " table(s)\n";
    echo "and mark " . count($convIds) . " conversation(s) as status=failed.\n";
    echo "Type 'yes' to continue, anything else to abort: ";
    $answer = trim(fgets(STDIN));
    if ($answer !== 'yes') {
        echo "Aborted.\n";
        exit(1);
    }
}

// ---- Apply ---------------------------------------------------------------------------------------------------------------
// Order: children first (the FK target is conversations; we keep that row). Each DELETE uses
// conversation_id IN (...) — explicit, not relying on cascades.
$pdo->beginTransaction();
try {
    $deletedPerTable = [];

    // violations has TWO FK paths from conversations — report_id AND direct conversation_id.
    // Either delete covers all rows, but doing it via conversation_id first makes the
    // compliance_reports cascade below unambiguous.
    $deletedPerTable['violations'] = $pdo->prepare("DELETE FROM violations WHERE conversation_id IN ({$ph})")
        ->execute($convIds) ? $pdo->query("SELECT ROW_COUNT()")->fetchColumn() : 0;

    foreach (['transcripts', 'transcript_segments', 'speakers', 'summaries', 'keywords',
              'action_items', 'conversation_tags', 'extracted_entities', 'compliance_reports',
              'knowledge_chunks', 'fraud_checks'] as $table) {
        $stmt = $pdo->prepare("DELETE FROM {$table} WHERE conversation_id IN ({$ph})");
        $stmt->execute($convIds);
        $deletedPerTable[$table] = $stmt->rowCount();
    }

    // Mark conversations themselves as failed — the audio still exists, we just won't try to
    // re-analyze it without human review.
    $errorMsg = sprintf(
        'fabricated transcript detected and cleaned up on %s (loop guard); re-analysis blocked pending human review',
        date('Y-m-d')
    );
    $upd = $pdo->prepare("
        UPDATE conversations
        SET status = 'failed', error_message = ?
        WHERE id IN ({$ph})
    ");
    $upd->execute(array_merge([$errorMsg], $convIds));
    $updatedConvs = $upd->rowCount();

    // Audit — one row per conversation so there is a paper trail.
    foreach ($convIds as $cid) {
        audit_log($pdo, null, null, 'fabricated_cleanup', 'conversation', (int) $cid, [
            'rows_deleted' => $deletedPerTable,
            'note' => 'pre-AudioQuality-guard transcript cleaned up',
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR during cleanup, rolled back: " . $e->getMessage() . "\n");
    exit(2);
}

echo "\n════════════════════════════════════════════════════════════════════════════════════════════\n";
echo " DONE\n";
echo "════════════════════════════════════════════════════════════════════════════════════════════\n\n";
echo "Conversations marked failed: {$updatedConvs}\n";
echo "Rows deleted per table:\n";
foreach ($deletedPerTable as $table => $n) {
    printf("  %-25s %5d\n", $table, $n);
}
echo "\nAudit log written. Re-run this script any time to confirm the count is now zero.\n";

// ---- helpers -----------------------------------------------------------------------------------------------------------

function parseArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $a) {
        if ($a === '--apply') { $out['apply'] = true; continue; }
        if ($a === '--yes')   { $out['yes'] = true;   continue; }
        if (preg_match('/^--conv=(.+)$/', $a, $m)) { $out['conv'] = $m[1]; continue; }
        if ($a === '--help' || $a === '-h') {
            echo "Usage: php migrations/cleanup_fabricated_calls.php [--apply] [--yes] [--conv=ID,ID]\n";
            echo "  --apply   Actually delete (default is dry-run)\n";
            echo "  --yes     Skip the confirmation prompt\n";
            echo "  --conv=N  Only test these conversation ids\n";
            exit(0);
        }
        fwrite(STDERR, "Unknown argument: {$a}\n");
        exit(2);
    }
    return $out;
}