<?php
/**
 * Returns conversations stranded mid-pipeline to 'pending'.
 *
 * A run claims a recording by moving it out of 'pending', and the pipeline walks it through
 * transcribing → analyzing → indexing → completed. Every one of those intermediate states assumes
 * the process that set it is still alive. When it is not — a PHP fatal, a request cut off by the web
 * server, the container being restarted mid-transcription, all of which have happened here — the row
 * keeps the working status forever. It is not 'pending', so nothing retries it; it is not terminal,
 * so it never shows up as a problem. It simply stops existing as far as the system is concerned.
 *
 * That is the same class of hole as a recording with no row at all, and the same answer applies:
 * every recording must end somewhere. This puts the stranded ones back in the queue.
 *
 * The age threshold has to clear the slowest legitimate run. A long call is minutes of transcription
 * plus a reasoning model that can take ten, so anything under about half an hour risks reaping work
 * that is still going and processing it twice.
 *
 *   php api/cron/reap_stale.php
 */

require_once __DIR__ . '/../config.php';

set_time_limit(0);

if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$staleMinutes = max(30, (int) (getenv('STALE_CLAIM_MINUTES') ?: 45));
$pdo = db_connect();

$working = ['transcribing', 'analyzing', 'extracting', 'checking_compliance', 'indexing'];
$placeholders = implode(',', array_fill(0, count($working), '?'));

$stale = fetch_all($pdo, "
    SELECT id, status, updated_at, created_at
    FROM conversations
    WHERE status IN ({$placeholders})
      AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL ? MINUTE)
", array_merge($working, [$staleMinutes]));

if (!$stale) {
    echo date('Y-m-d H:i:s') . " no stale claims (threshold {$staleMinutes} min)\n";
    exit(0);
}

// Reset rather than fail: the recording itself was never the problem, and whatever killed the run
// was almost certainly transient. If it is not, the row comes back here and the count going up is
// the signal worth watching.
$reset = $pdo->prepare("UPDATE conversations SET status = 'pending', error_message = ? WHERE id = ? AND status = ?");
$freed = 0;
foreach ($stale as $row) {
    $note = sprintf('คืนคิวอัตโนมัติ: ค้างที่สถานะ %s เกิน %d นาที (รอบก่อนหน้าน่าจะตายกลางทาง)',
        $row['status'], $staleMinutes);
    $reset->execute([$note, (int) $row['id'], $row['status']]);
    if ($reset->rowCount() > 0) {
        $freed++;
        echo date('Y-m-d H:i:s') . " freed conv {$row['id']} (was {$row['status']})\n";
    }
}

echo date('Y-m-d H:i:s') . " reaped {$freed} stale claim(s)\n";
