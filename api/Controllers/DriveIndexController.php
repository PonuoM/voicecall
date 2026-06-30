<?php

require_once __DIR__ . '/../Services/GdriveIndexer.php';

/**
 * Serves the server-side Drive file index (see migrations/004_gdrive_file_index.sql and
 * api/cron/sync_gdrive_index.php) so the dashboard can load instantly instead of doing a live
 * recursive Drive scan in the browser.
 */
function handle_drive_index(PDO $pdo, array $currentUser, ?string $id): void
{
    if ($id === 'sync' && method() === 'POST') {
        sync_drive_index($pdo, $currentUser);
        return;
    }

    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $companyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($currentUser['erp_company_id'] ?? 0);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $rows = fetch_all($pdo, '
        SELECT gdrive_file_id, filename, call_code, call_date, call_time,
               caller_phone, receiver_phone, direction, size_bytes
        FROM gdrive_file_index
        WHERE company_id = ?
        ORDER BY call_date DESC, call_time DESC
    ', [$companyId]);

    // Shape matches what index.html's parseWavFilename() already produces, so the frontend can
    // drop this straight into `allData` with no further transformation.
    $data = array_map(function ($r) {
        return [
            'id' => $r['call_code'],
            'date' => $r['call_date'],
            'time' => $r['call_time'],
            'caller' => $r['caller_phone'],
            'receiver' => $r['receiver_phone'],
            'direction' => $r['direction'],
            'fileId' => $r['gdrive_file_id'],
            'filename' => $r['filename'],
            'size' => (int) $r['size_bytes'],
        ];
    }, $rows);

    $lastSync = fetch_one($pdo, "SELECT finished_at, files_found FROM gdrive_sync_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1", []);

    json_response(['ok' => true, 'data' => $data, 'last_synced_at' => $lastSync['finished_at'] ?? null]);
}

/**
 * Manual "sync now" trigger for the dashboard - runs the same GdriveIndexer::runFullSync() the
 * cron uses, scoped to just the caller's company (much faster than a full all-companies sweep,
 * and the dashboard only ever needs its own company's data refreshed). Counts gdrive_file_index
 * rows before and after so the response directly proves the new files actually landed in the DB,
 * not just that the Drive scan succeeded.
 */
function sync_drive_index(PDO $pdo, array $currentUser): void
{
    $companyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($currentUser['erp_company_id'] ?? 0);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    set_time_limit(0); // full sync of one company can still take minutes

    $before = (int) ($pdo->query('SELECT COUNT(*) FROM gdrive_file_index WHERE company_id = ' . $companyId)->fetchColumn());
    $forceFull = !empty($_GET['full']);

    try {
        $result = GdriveIndexer::runFullSync($pdo, $companyId, $forceFull);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'SYNC_FAILED', 'message' => $e->getMessage()], 500);
    }

    $after = (int) ($pdo->query('SELECT COUNT(*) FROM gdrive_file_index WHERE company_id = ' . $companyId)->fetchColumn());

    json_response([
        'ok' => true,
        'files_found' => $result['files_found'],
        'files_upserted' => $result['files_upserted'],
        'before_count' => $before,
        'after_count' => $after,
        'new_files' => max(0, $after - $before),
    ]);
}
