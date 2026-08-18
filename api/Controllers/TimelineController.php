<?php

/**
 * Processing timeline — what the pipeline actually did and when, distinct from call_date/call_time
 * (when the phone call happened). updated_at is when a conversation last changed status, which for
 * a terminal row is the moment the pipeline finished it - ordering by that turns the table into an
 * activity log: what ran, in what order, and how much idle time sat between one finishing and the
 * next starting.
 *
 *   GET timeline?company_id=&status=&from=&to=&page=&per_page=
 */
function handle_timeline(PDO $pdo, array $currentUser): void
{
    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;

    // Only terminal states have a meaningful "finished at" - a row still mid-pipeline would show up
    // with whatever timestamp it last ticked through and no summary to show for it.
    $statusFilter = $_GET['status'] ?? '';
    $allowedStatuses = ['completed', 'failed', 'skipped'];
    $where = ['company_id = ?', 'status IN (' . implode(',', array_fill(0, count($allowedStatuses), '?')) . ')'];
    $params = array_merge([$companyId], $allowedStatuses);
    if ($statusFilter !== '' && in_array($statusFilter, $allowedStatuses, true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }
    if (!empty($_GET['from'])) {
        $where[] = 'updated_at >= ?';
        $params[] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[] = 'updated_at <= ?';
        $params[] = $_GET['to'] . ' 23:59:59';
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM conversations {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Today's tally, independent of the page/filter above - "how much got done today" is a
    // different question from "show me this filtered page", and answering it from the already
    // date-filtered/paginated query above would give a wrong number the moment either filter is set.
    $todayStmt = $pdo->prepare("
        SELECT status, COUNT(*) cnt FROM conversations
        WHERE company_id = ? AND DATE(updated_at) = CURDATE() AND status IN ('completed','failed','skipped')
        GROUP BY status
    ");
    $todayStmt->execute([$companyId]);
    $todayCounts = ['completed' => 0, 'failed' => 0, 'skipped' => 0];
    foreach ($todayStmt->fetchAll() as $row) {
        $todayCounts[$row['status']] = (int) $row['cnt'];
    }
    $todayCounts['total'] = array_sum($todayCounts);

    $stmt = $pdo->prepare("
        SELECT c.id, c.call_code, c.status, c.error_message, c.updated_at, c.call_date, c.call_time,
               c.direction, c.duration_seconds, c.erp_employee_name, c.erp_customer_name,
               s.executive_summary, s.customer_sentiment, s.key_topics
        FROM conversations c
        LEFT JOIN summaries s ON s.conversation_id = c.id
        {$whereSql}
        ORDER BY c.updated_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_response(['ok' => true, 'data' => $rows, 'today' => $todayCounts, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
}
