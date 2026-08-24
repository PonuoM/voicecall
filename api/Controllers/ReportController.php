<?php

/**
 * Processing report — replaces the old cost dashboard. That page tracked live OpenRouter spend,
 * which stopped meaning anything once transcription moved to a self-hosted service and analysis
 * moved to a flat MiniMax subscription: there is no more per-call bill to watch. What's worth
 * watching now is coverage - how much of the recorded backlog has actually been read - so this
 * reports that instead: per-company file counts, a day-by-day heatmap of what's done, and how
 * long processing has actually taken.
 *
 *   GET report/summary?company_id=
 *   GET report/heatmap?company_id=&year=
 *   GET report/throughput?company_id=&month=YYYY-MM
 */
function handle_report(PDO $pdo, PDO $erp, array $currentUser, ?string $id): void
{
    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    if ($id === 'summary') {
        report_summary($pdo, $erp, $currentUser);
        return;
    }
    if ($id === 'heatmap') {
        report_heatmap($pdo, $currentUser);
        return;
    }
    if ($id === 'throughput') {
        report_throughput($pdo, $currentUser);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

/**
 * Daily throughput — how many recordings the pipeline actually got through on each day.
 *
 * Deliberately a different question from the heatmap above, which groups by call_date and answers
 * "how much of the backlog is covered". This groups by updated_at and answers "how fast are we
 * moving", and the two can look nothing alike: a single day of processing chews through calls
 * recorded across eight different months. Coverage says where the gaps are; this says whether the
 * gaps are closing, which is the only view that shows an outage as it happens. On 19 Aug 2026 the
 * Drive IP block was invisible on the heatmap and unmistakable here - four short bursts of work
 * separated by four-hour flat stretches.
 *
 * 'failed' rides along because a day is not a good day just because the count is high; a spike of
 * failures next to a spike of completions is the shape of something going wrong.
 */
function report_throughput(PDO $pdo, array $currentUser): void
{
    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $months = fetch_all($pdo, "
        SELECT DISTINCT DATE_FORMAT(updated_at, '%Y-%m') AS m
        FROM conversations
        WHERE company_id = ? AND status IN ('completed','failed') AND updated_at IS NOT NULL
        ORDER BY m DESC
    ", [$companyId]);
    $availableMonths = array_map(function ($r) {
        return $r['m'];
    }, $months);

    $month = (string) ($_GET['month'] ?? ($availableMonths[0] ?? date('Y-m')));
    // Anything else would interpolate straight into DATE_FORMAT comparisons below.
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'month must look like YYYY-MM'], 422);
    }

    $days = fetch_all($pdo, "
        SELECT
            DATE(updated_at) AS day,
            SUM(status = 'completed') AS completed,
            SUM(status = 'failed') AS failed
        FROM conversations
        WHERE company_id = ?
          AND status IN ('completed','failed')
          AND DATE_FORMAT(updated_at, '%Y-%m') = ?
        GROUP BY DATE(updated_at)
        ORDER BY day
    ", [$companyId, $month]);

    json_response([
        'ok' => true,
        'month' => $month,
        'available_months' => $availableMonths,
        'days' => array_map(function ($r) {
            return [
                'date' => $r['day'],
                'completed' => (int) $r['completed'],
                'failed' => (int) $r['failed'],
            ];
        }, $days),
    ]);
}

/**
 * A normal user sees only their own company's row (same rule every other report in this app
 * follows); a super admin sees every company that actually has files indexed, not just 1 and 2 -
 * the shared Drive account holds other businesses' folders too, and a coverage report should show
 * what is really there.
 */
function report_summary(PDO $pdo, PDO $erp, array $currentUser): void
{
    $requestedCompany = $_GET['company_id'] ?? null;
    $isSuperAdmin = !empty($currentUser['erp_is_super_admin']);
    $companyFilter = $isSuperAdmin ? ($requestedCompany ?: null) : resolve_company_id($currentUser, null);

    $where = '';
    $params = [];
    if ($companyFilter) {
        $where = 'WHERE g.company_id = ?';
        $params[] = (int) $companyFilter;
    }

    $rows = fetch_all($pdo, "
        SELECT
            g.company_id,
            COUNT(*) AS total_files,
            SUM(c.status = 'completed') AS completed,
            SUM(c.status = 'skipped') AS skipped,
            SUM(c.status = 'failed') AS failed,
            SUM(c.id IS NOT NULL AND c.status NOT IN ('completed','skipped','failed')) AS in_progress,
            SUM(c.id IS NULL) AS remaining
        FROM gdrive_file_index g
        LEFT JOIN conversations c ON c.source = 'gdrive' AND c.audio_ref = g.gdrive_file_id
        {$where}
        GROUP BY g.company_id
        ORDER BY total_files DESC
    ", $params);

    // Company names live in the ERP, not this DB - same read-only cross-DB pattern as
    // ErpLookupService, just for a table small enough not to need its own service.
    $names = [];
    if ($rows) {
        $ids = array_map(function ($r) {
            return (int) $r['company_id'];
        }, $rows);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $erp->prepare("SELECT id, name FROM companies WHERE id IN ({$ph})");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $names[(int) $c['id']] = $c['name'];
        }
    }

    $companies = array_map(function ($r) use ($names) {
        $companyId = (int) $r['company_id'];
        return [
            'company_id' => $companyId,
            'company_name' => $names[$companyId] ?? "บริษัท #{$companyId}",
            'total_files' => (int) $r['total_files'],
            'completed' => (int) $r['completed'],
            'skipped' => (int) $r['skipped'],
            'failed' => (int) $r['failed'],
            'in_progress' => (int) $r['in_progress'],
            'remaining' => (int) $r['remaining'],
        ];
    }, $rows);

    // Timing: created_at-to-updated_at is only a fair proxy for processing time when a row was
    // claimed right after it was registered - true for anything backlog_drain/process_pending
    // picks up, false for rows that sat in 'pending' for days before finally being claimed (their
    // gap is queue wait, not work). Ordering by updated_at to find "recent" ones does not avoid
    // this: a later unrelated UPDATE to the same row (e.g. an ERP-name backfill touching an old
    // row) bumps updated_at without the row having been reprocessed, which is exactly what
    // happened here and inflated the average to hours. created_at is not touched by anything but
    // registration, so filtering on recent created_at is the reliable way to isolate rows that
    // actually went through today's fixed pipeline.
    $timingWhere = $companyFilter
        ? 'WHERE status = \'completed\' AND company_id = ? AND created_at >= NOW() - INTERVAL 6 HOUR'
        : "WHERE status = 'completed' AND created_at >= NOW() - INTERVAL 6 HOUR";
    $timingParams = $companyFilter ? [(int) $companyFilter] : [];
    $recentTiming = fetch_one($pdo, "
        SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, updated_at)) AS avg_seconds
        FROM conversations
        {$timingWhere}
    ", $timingParams);

    // True total, not capped at the recent-500 sample used for the average above.
    $totalCompleted = array_sum(array_map(function ($c) {
        return $c['completed'];
    }, $companies));
    $avgSeconds = $recentTiming['avg_seconds'] !== null ? (float) $recentTiming['avg_seconds'] : null;

    json_response([
        'ok' => true,
        'companies' => $companies,
        'timing' => [
            'completed_count' => $totalCompleted,
            'avg_seconds_per_file' => $avgSeconds !== null ? round($avgSeconds) : null,
            // Extrapolated from the recent average, not a raw historical sum - summing every
            // completed row's created_at-to-updated_at gap would double-count queue wait for
            // anything that sat pending for days before being claimed.
            'total_seconds_spent_est' => $avgSeconds !== null ? round($avgSeconds * $totalCompleted) : null,
        ],
    ]);
}

/**
 * One cell per calendar day: white (nothing recorded), red (recorded, nothing done yet), yellow
 * (partially done), green (fully done) - the frontend does the coloring, this just supplies
 * total/handled per day so the classification is exact rather than eyeballed from a percentage.
 */
function report_heatmap(PDO $pdo, array $currentUser): void
{
    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $years = fetch_all($pdo, "
        SELECT DISTINCT YEAR(call_date) AS y FROM gdrive_file_index
        WHERE company_id = ? AND call_date IS NOT NULL ORDER BY y DESC
    ", [$companyId]);
    $availableYears = array_map(function ($r) {
        return (int) $r['y'];
    }, $years);

    $year = (int) ($_GET['year'] ?? ($availableYears[0] ?? date('Y')));

    $days = fetch_all($pdo, "
        SELECT
            g.call_date AS day,
            COUNT(*) AS total,
            SUM(c.id IS NOT NULL AND c.status IN ('completed','skipped','failed')) AS handled
        FROM gdrive_file_index g
        LEFT JOIN conversations c ON c.source = 'gdrive' AND c.audio_ref = g.gdrive_file_id
        WHERE g.company_id = ? AND YEAR(g.call_date) = ? AND g.call_date IS NOT NULL
        GROUP BY g.call_date
    ", [$companyId, $year]);

    json_response([
        'ok' => true,
        'year' => $year,
        'available_years' => $availableYears,
        'days' => array_map(function ($r) {
            return [
                'date' => $r['day'],
                'total' => (int) $r['total'],
                'handled' => (int) $r['handled'],
            ];
        }, $days),
    ]);
}
