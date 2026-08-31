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

    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    // The old shape of this handler (fetch_all -> array_map -> json_encode) held three copies of
    // the company's whole index in memory at once. At 180k rows that demanded ~450MB per request
    // and was dying at 268MB when the shared host ran dry (fatal_error.log, 31 Aug 2026), taking
    // MySQL - and every project on the box - down with it. It now streams row by row: memory
    // stays flat no matter how big the index grows.
    //
    // `days` limits the window (0 or absent = full history, so existing callers see no change;
    // index.html asks for a window explicitly). An empty window silently widens to full history
    // because the frontend treats an empty list as "index missing" and falls back to a live
    // recursive Drive scan in the browser - the one path that has gotten this IP blocked before.
    $days = isset($_GET['days']) ? max(0, (int) $_GET['days']) : 0;

    $totalRows = (int) (fetch_one($pdo, 'SELECT COUNT(*) c FROM gdrive_file_index WHERE company_id = ?', [$companyId])['c'] ?? 0);
    if ($days > 0) {
        $inWindow = (int) (fetch_one($pdo, 'SELECT COUNT(*) c FROM gdrive_file_index WHERE company_id = ? AND call_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)', [$companyId, $days])['c'] ?? 0);
        if ($inWindow === 0) {
            $days = 0;
        }
    }

    // Fetched before the stream starts: an unbuffered connection cannot run a second query
    // until the streaming statement is fully consumed.
    $lastSync = fetch_one($pdo, "SELECT finished_at FROM gdrive_sync_runs WHERE status = 'completed' ORDER BY id DESC LIMIT 1", []);

    header('Content-Type: application/json; charset=utf-8');

    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        $sql = 'SELECT gdrive_file_id, filename, call_code, call_date, call_time,
                       caller_phone, receiver_phone, direction, size_bytes, duration_seconds
                FROM gdrive_file_index
                WHERE company_id = ?';
        $params = [$companyId];
        if ($days > 0) {
            $sql .= ' AND call_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)';
            $params[] = $days;
        }
        $sql .= ' ORDER BY call_date DESC, call_time DESC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo '{"ok":true,"last_synced_at":' . json_encode($lastSync['finished_at'] ?? null)
            . ',"window_days":' . $days
            . ',"total_rows":' . $totalRows
            . ',"data":[';

        $sent = 0;
        while ($r = $stmt->fetch()) {
            // Shape matches what index.html's parseWavFilename() already produces, so the
            // frontend can drop this straight into `allData` with no further transformation.
            $row = [
                'id' => $r['call_code'],
                'date' => $r['call_date'],
                'time' => $r['call_time'],
                'caller' => $r['caller_phone'],
                'receiver' => $r['receiver_phone'],
                'direction' => $r['direction'],
                'fileId' => $r['gdrive_file_id'],
                'filename' => $r['filename'],
                'size' => (int) $r['size_bytes'],
                'duration' => $r['duration_seconds'] === null ? null : (int) $r['duration_seconds'],
            ];
            echo ($sent === 0 ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ((++$sent % 2000) === 0) {
                flush();
            }
        }
        echo ']}';
        $stmt->closeCursor();
    } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    exit();
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
    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
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
