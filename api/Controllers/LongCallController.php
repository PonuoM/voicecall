<?php

require_once __DIR__ . '/../Services/ErpLookupService.php';

/**
 * Long-call report — abnormally long recordings, read straight from gdrive_file_index (free,
 * no AI involved). Two things make a long call worth a look: the call itself (what was actually
 * discussed for 90 minutes?) and the PATTERN across calls — the same employee racking up many
 * long calls, especially repeatedly to the same number, is the signature of talk-time padding
 * (ปั่น talk-time เพื่อ KPI) rather than genuine selling.
 *
 *   GET long-calls?company_id=&min_minutes=30&limit=200
 */
function handle_long_calls(PDO $pdo, PDO $erp, array $currentUser, ?string $id): void
{
    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $companyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($currentUser['erp_company_id'] ?? 0);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $minMinutes = max(1, min(600, (int) ($_GET['min_minutes'] ?? 30)));
    $minSeconds = $minMinutes * 60;
    $limit = min(500, max(1, (int) ($_GET['limit'] ?? 200)));

    $where = ['company_id = ?'];
    $params = [$companyId];
    if (!empty($_GET['from'])) {
        $where[] = 'call_date >= ?';
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $where[] = 'call_date <= ?';
        $params[] = $_GET['to'];
    }
    $whereSql = implode(' AND ', $where);
    // The call-list query joins conversations, which also has company_id/call_date — the same
    // conditions need table-qualified column names there.
    $whereSqlG = 'g.' . implode(' AND g.', $where);

    // Fixed buckets so the page can show "how many calls are there at each threshold" without
    // the user having to re-query one threshold at a time.
    $buckets = fetch_one($pdo, "
        SELECT COUNT(*)                          AS total,
               SUM(duration_seconds >= 600)      AS m10,
               SUM(duration_seconds >= 1200)     AS m20,
               SUM(duration_seconds >= 1800)     AS m30,
               SUM(duration_seconds >= 2700)     AS m45,
               SUM(duration_seconds >= 3600)     AS m60
        FROM gdrive_file_index WHERE {$whereSql}
    ", $params);

    $calls = fetch_all($pdo, "
        SELECT g.gdrive_file_id, g.call_code, g.call_date, g.call_time, g.caller_phone,
               g.receiver_phone, g.direction, g.duration_seconds, g.size_bytes,
               c.id AS conversation_id, c.status AS conversation_status
        FROM gdrive_file_index g
        LEFT JOIN conversations c ON c.source = 'gdrive' AND c.audio_ref = g.gdrive_file_id
        WHERE {$whereSqlG} AND g.duration_seconds >= ?
        ORDER BY g.duration_seconds DESC
        LIMIT {$limit}
    ", array_merge($params, [$minSeconds]));

    // Ranking: who produces these long calls. distinct_receivers vs long_calls is the tell —
    // 20 long calls spread over 20 customers is a talker; 20 over 2 numbers is padding.
    $byCaller = fetch_all($pdo, "
        SELECT caller_phone,
               COUNT(*)                                  AS long_calls,
               COUNT(DISTINCT receiver_phone)            AS distinct_receivers,
               ROUND(MAX(duration_seconds)/60)           AS max_minutes,
               ROUND(SUM(duration_seconds)/3600, 1)      AS total_hours,
               MIN(call_date)                            AS first_seen,
               MAX(call_date)                            AS last_seen
        FROM gdrive_file_index
        WHERE {$whereSql} AND duration_seconds >= ?
        GROUP BY caller_phone
        ORDER BY long_calls DESC, total_hours DESC
        LIMIT 50
    ", array_merge($params, [$minSeconds]));

    // Same caller -> same receiver, more than once, all long: the strongest padding signal.
    $repeatedPairs = fetch_all($pdo, "
        SELECT caller_phone, receiver_phone,
               COUNT(*)                             AS times,
               ROUND(SUM(duration_seconds)/3600, 1) AS total_hours,
               ROUND(MAX(duration_seconds)/60)      AS max_minutes
        FROM gdrive_file_index
        WHERE {$whereSql} AND duration_seconds >= ?
        GROUP BY caller_phone, receiver_phone
        HAVING times >= 2
        ORDER BY times DESC, total_hours DESC
        LIMIT 30
    ", array_merge($params, [$minSeconds]));

    // Resolve caller numbers to ERP employees in one batch round-trip.
    $phones = array_unique(array_merge(
        array_column($byCaller, 'caller_phone'),
        array_column($repeatedPairs, 'caller_phone'),
        array_column($calls, 'caller_phone')
    ));
    $employees = ErpLookupService::findEmployeesByPhones($erp, array_filter($phones));

    $attachEmployee = function (array $row) use ($employees) {
        $emp = $employees[$row['caller_phone']] ?? null;
        $row['employee_id'] = $emp ? $emp['id'] : null;
        $row['employee_name'] = $emp ? $emp['name'] : null;
        return $row;
    };

    json_response([
        'ok' => true,
        'min_minutes' => $minMinutes,
        'buckets' => $buckets,
        'calls' => array_map($attachEmployee, $calls),
        'by_caller' => array_map($attachEmployee, $byCaller),
        'repeated_pairs' => array_map($attachEmployee, $repeatedPairs),
    ]);
}
