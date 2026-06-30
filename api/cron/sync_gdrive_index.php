<?php
/**
 * Walks every "Company = N" folder under GDRIVE_ROOT_FOLDER_ID and upserts every WAV file found
 * into gdrive_file_index, so the dashboard can read a fast local table instead of doing this same
 * walk live in the browser (see migrations/004_gdrive_file_index.sql for why that was slow -
 * ~96% of files sit in one flat folder needing 100+ sequential paginated Drive API calls).
 *
 * Core walk-and-upsert logic lives in GdriveIndexer::runFullSync() - shared with the dashboard's
 * "sync now" button (Controllers/DriveIndexController.php), so both paths stay in lockstep.
 * Incremental by default (only asks Drive for files changed since the last completed run, via
 * modifiedTime filtering) - pass --full to force a complete re-walk regardless.
 *
 * Run via CLI:
 *   php api/cron/sync_gdrive_index.php [--full]
 * Intended to be scheduled (e.g. cron) every 10-15 minutes so the index never drifts far behind
 * what's actually in Drive. Safe to run concurrently with itself being slow - upserts are
 * idempotent per gdrive_file_id.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/GdriveIndexer.php';

// This lives under a public web root (api/cron/), so anyone who guesses the URL could otherwise
// trigger a full Drive resync on demand. CLI (the real cron path) is always allowed; HTTP access
// requires CRON_HTTP_KEY (set in .env) as a ?key= query param, same pattern as the one-shot
// bootstrap_*.php scripts used during initial deploy.
if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die("Forbidden\n");
    }
}

// config.php sets a 300s execution limit for web requests - this is a long-running batch job by
// design (100k+ rows across multiple companies), so override that back to unlimited here.
set_time_limit(0);

$pdo = db_connect();
$forceFull = in_array('--full', $argv ?? [], true) || !empty($_GET['full']);

try {
    $result = GdriveIndexer::runFullSync($pdo, null, $forceFull);
    echo "Done. {$result['files_found']} files found, {$result['files_upserted']} upserted.\n";
    foreach ($result['companies'] as $companyId => $count) {
        echo "  Company {$companyId}: {$count} files\n";
    }
} catch (Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    exit(1);
}
