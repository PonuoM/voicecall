<?php

require_once __DIR__ . '/../Services/ErpLookupService.php';
require_once __DIR__ . '/../Services/ErpCallOutcomeService.php';

/**
 * Long-call report — abnormally long recordings, read from gdrive_file_index (free, no AI) and
 * enriched with what the ERP says came of them: the telesale's own CRM entry
 * (call_history.result, where 'ขายได้' = closed a sale) and any order placed around the call with
 * its status, so returns (ตีกลับ) show up next to the recording that produced them.
 *
 * Two things make a long call worth a look: the call itself, and the pattern across calls — an
 * employee racking up hours of long calls with nothing sold, especially repeatedly to the same
 * number, is the signature of talk-time padding (ปั่น talk time) rather than genuine selling.
 *
 *   GET long-calls?company_id=&min_minutes=30&from=&to=&limit=200
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
    // Ceiling on how many recordings get ERP-enriched in one request. The per-employee rollup is
    // computed from this same set, so it must cover the whole filtered range, not just the page —
    // at the default 30-minute threshold that is only a few hundred rows company-wide.
    $enrichCap = 2000;

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
    $whereSqlG = 'g.' . implode(' AND g.', $where);

    // Fixed buckets so the page can show how many calls sit at each threshold without the user
    // re-querying one threshold at a time.
    $buckets = fetch_one($pdo, "
        SELECT COUNT(*)                          AS total,
               SUM(duration_seconds >= 600)      AS m10,
               SUM(duration_seconds >= 1200)     AS m20,
               SUM(duration_seconds >= 1800)     AS m30,
               SUM(duration_seconds >= 2700)     AS m45,
               SUM(duration_seconds >= 3600)     AS m60
        FROM gdrive_file_index WHERE {$whereSql}
    ", $params);

    $rows = fetch_all($pdo, "
        SELECT g.gdrive_file_id, g.company_id, g.call_code, g.call_date, g.call_time, g.caller_phone,
               g.receiver_phone, g.direction, g.duration_seconds, g.size_bytes,
               c.id AS conversation_id, c.status AS conversation_status
        FROM gdrive_file_index g
        LEFT JOIN conversations c ON c.source = 'gdrive' AND c.audio_ref = g.gdrive_file_id
        WHERE {$whereSqlG} AND g.duration_seconds >= ?
        ORDER BY g.duration_seconds DESC
        LIMIT {$enrichCap}
    ", array_merge($params, [$minSeconds]));

    $outcomes = [];
    $outcomeError = null;
    try {
        $outcomes = ErpCallOutcomeService::forRecordings($erp, $rows);
    } catch (Throwable $e) {
        // The report is still useful without ERP enrichment — degrade instead of failing.
        $outcomeError = $e->getMessage();
    }

    $employees = ErpLookupService::findEmployeesByPhones($erp, array_values(array_unique(array_filter(array_column($rows, 'caller_phone')))));

    // Roll up per caller and per caller->receiver pair over the whole filtered set.
    $byCaller = [];
    $pairs = [];
    foreach ($rows as $r) {
        $o = $outcomes[$r['gdrive_file_id']] ?? null;
        $phone = $r['caller_phone'];

        if (!isset($byCaller[$phone])) {
            $byCaller[$phone] = [
                'caller_phone' => $phone,
                'long_calls' => 0, 'total_seconds' => 0, 'max_seconds' => 0,
                'sold' => 0, 'with_order' => 0, 'returned' => 0, 'logged' => 0,
                'receivers' => [], 'first_seen' => $r['call_date'], 'last_seen' => $r['call_date'],
            ];
        }
        $c = &$byCaller[$phone];
        $c['long_calls']++;
        $c['total_seconds'] += (int) $r['duration_seconds'];
        $c['max_seconds'] = max($c['max_seconds'], (int) $r['duration_seconds']);
        $c['receivers'][$r['receiver_phone']] = true;
        $c['first_seen'] = min($c['first_seen'], $r['call_date']);
        $c['last_seen'] = max($c['last_seen'], $r['call_date']);
        if ($o) {
            if ($o['call_log']) $c['logged']++;
            if ($o['sold']) $c['sold']++;
            if (!empty($o['orders'])) $c['with_order']++;
            if ($o['returned']) $c['returned']++;
        }
        unset($c);

        $pairKey = $phone . '|' . $r['receiver_phone'];
        if (!isset($pairs[$pairKey])) {
            $pairs[$pairKey] = ['caller_phone' => $phone, 'receiver_phone' => $r['receiver_phone'],
                                'times' => 0, 'total_seconds' => 0, 'max_seconds' => 0, 'sold' => 0];
        }
        $pairs[$pairKey]['times']++;
        $pairs[$pairKey]['total_seconds'] += (int) $r['duration_seconds'];
        $pairs[$pairKey]['max_seconds'] = max($pairs[$pairKey]['max_seconds'], (int) $r['duration_seconds']);
        if ($o && $o['sold']) {
            $pairs[$pairKey]['sold']++;
        }
    }

    $byCallerOut = [];
    foreach ($byCaller as $c) {
        $emp = $employees[$c['caller_phone']] ?? null;
        $byCallerOut[] = [
            'caller_phone' => $c['caller_phone'],
            'employee_id' => $emp ? $emp['id'] : null,
            'employee_name' => $emp ? $emp['name'] : null,
            'long_calls' => $c['long_calls'],
            'distinct_receivers' => count($c['receivers']),
            'total_hours' => round($c['total_seconds'] / 3600, 1),
            'max_minutes' => (int) round($c['max_seconds'] / 60),
            'sold' => $c['sold'],
            'with_order' => $c['with_order'],
            'returned' => $c['returned'],
            'logged' => $c['logged'],
            'first_seen' => $c['first_seen'],
            'last_seen' => $c['last_seen'],
        ];
    }
    usort($byCallerOut, function ($a, $b) {
        return [$b['long_calls'], $b['total_hours']] <=> [$a['long_calls'], $a['total_hours']];
    });
    $byCallerOut = array_slice($byCallerOut, 0, 50);

    $pairsOut = [];
    foreach ($pairs as $p) {
        if ($p['times'] < 2) {
            continue; // one long call to a customer is normal; repeats are the signal
        }
        $emp = $employees[$p['caller_phone']] ?? null;
        $pairsOut[] = [
            'caller_phone' => $p['caller_phone'],
            'receiver_phone' => $p['receiver_phone'],
            'employee_name' => $emp ? $emp['name'] : null,
            'times' => $p['times'],
            'sold' => $p['sold'],
            'total_hours' => round($p['total_seconds'] / 3600, 1),
            'max_minutes' => (int) round($p['max_seconds'] / 60),
        ];
    }
    usort($pairsOut, function ($a, $b) {
        return [$b['times'], $b['total_hours']] <=> [$a['times'], $a['total_hours']];
    });
    $pairsOut = array_slice($pairsOut, 0, 30);

    $calls = [];
    foreach (array_slice($rows, 0, $limit) as $r) {
        $o = $outcomes[$r['gdrive_file_id']] ?? null;
        $emp = $employees[$r['caller_phone']] ?? null;
        $r['employee_id'] = $emp ? $emp['id'] : null;
        $r['employee_name'] = $emp ? $emp['name'] : null;
        $r['customer_name'] = $o && $o['customer'] ? $o['customer']['name'] : null;
        $r['customer_id'] = $o && $o['customer'] ? $o['customer']['id'] : null;
        $r['call_result'] = $o && $o['call_log'] ? $o['call_log']['result'] : null;
        $r['call_status'] = $o && $o['call_log'] ? $o['call_log']['status'] : null;
        $r['call_notes'] = $o && $o['call_log'] ? $o['call_log']['notes'] : null;
        $r['sold'] = $o ? $o['sold'] : false;
        $r['returned'] = $o ? $o['returned'] : false;
        $r['orders'] = $o ? $o['orders'] : [];
        $r['crm_coverage'] = $o ? $o['crm_coverage'] : 'no_data';
        $calls[] = $r;
    }

    // Headline numbers for the filtered set — the "hours spent vs sales made" question.
    $totals = ['calls' => count($rows), 'hours' => 0.0, 'sold' => 0, 'with_order' => 0, 'returned' => 0, 'logged' => 0];
    foreach ($rows as $r) {
        $totals['hours'] += (int) $r['duration_seconds'] / 3600;
        $o = $outcomes[$r['gdrive_file_id']] ?? null;
        if ($o) {
            if ($o['call_log']) $totals['logged']++;
            if ($o['sold']) $totals['sold']++;
            if (!empty($o['orders'])) $totals['with_order']++;
            if ($o['returned']) $totals['returned']++;
        }
    }
    $totals['hours'] = round($totals['hours'], 1);
    $totals['truncated'] = count($rows) >= $enrichCap;

    json_response([
        'ok' => true,
        'min_minutes' => $minMinutes,
        'buckets' => $buckets,
        'totals' => $totals,
        'erp_error' => $outcomeError,
        'calls' => $calls,
        'by_caller' => $byCallerOut,
        'repeated_pairs' => $pairsOut,
    ]);
}
