<?php

/**
 * Fraud review API — reads fraud_checks produced by FraudCheckService and records human
 * verdicts. Company-scoped through the bearer token like the compliance endpoints.
 *
 *   GET  fraud                 list checks (filters: review_status, risk_level, status,
 *                              channel_type, employee_id, from, to, limit)
 *   GET  fraud/summary         per-employee aggregates + overall counters
 *   POST fraud/{id}/review     body {decision: "confirmed"|"dismissed", note?: string}
 */
function handle_fraud(PDO $pdo, array $currentUser, ?string $id, ?string $action): void
{
    $companyId = (int) ($currentUser['erp_company_id'] ?? 0);

    if ($id === null && method() === 'GET') {
        list_fraud_checks($pdo, $companyId);
        return;
    }

    if ($id === 'summary' && method() === 'GET') {
        fraud_summary($pdo, $companyId);
        return;
    }

    if ($id !== null && ctype_digit($id) && $action === 'review' && method() === 'POST') {
        review_fraud_check($pdo, $currentUser, $companyId, (int) $id);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

function list_fraud_checks(PDO $pdo, int $companyId): void
{
    $where = ['f.company_id = ?'];
    $params = [$companyId];

    // Default view is the actionable queue: everything still flagged. Pass status=all to see
    // 'clear' rows (mentions that matched official company accounts) too.
    $status = $_GET['status'] ?? 'flagged';
    if ($status !== 'all' && in_array($status, ['flagged', 'clear'], true)) {
        $where[] = 'f.status = ?';
        $params[] = $status;
    }
    if (!empty($_GET['review_status']) && in_array($_GET['review_status'], ['pending', 'confirmed', 'dismissed'], true)) {
        $where[] = 'f.review_status = ?';
        $params[] = $_GET['review_status'];
    }
    if (!empty($_GET['risk_level']) && in_array($_GET['risk_level'], ['low', 'medium', 'high', 'critical'], true)) {
        $where[] = 'f.risk_level = ?';
        $params[] = $_GET['risk_level'];
    }
    if (!empty($_GET['channel_type'])) {
        $where[] = 'f.channel_type = ?';
        $params[] = $_GET['channel_type'];
    }
    if (!empty($_GET['check_type'])) {
        $where[] = 'f.check_type = ?';
        $params[] = $_GET['check_type'];
    }
    if (!empty($_GET['employee_id'])) {
        $where[] = 'c.erp_employee_id = ?';
        $params[] = (int) $_GET['employee_id'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'c.call_date >= ?';
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $where[] = 'c.call_date <= ?';
        $params[] = $_GET['to'];
    }

    $whereSql = implode(' AND ', $where);
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));

    $rows = fetch_all($pdo, "
        SELECT f.*, c.call_code, c.call_date, c.call_time, c.direction,
               c.caller_phone, c.receiver_phone, c.erp_employee_id, c.erp_employee_name,
               c.erp_customer_id, c.erp_customer_name, c.audio_ref, c.source AS audio_source
        FROM fraud_checks f
        JOIN conversations c ON c.id = f.conversation_id
        WHERE {$whereSql}
        ORDER BY FIELD(f.risk_level, 'critical', 'high', 'medium', 'low'), f.created_at DESC
        LIMIT {$limit}
    ", $params);

    json_response(['ok' => true, 'data' => $rows]);
}

function fraud_summary(PDO $pdo, int $companyId): void
{
    $overall = fetch_one($pdo, "
        SELECT
            COUNT(*)                                                                  AS total,
            SUM(f.status = 'flagged')                                                 AS flagged,
            SUM(f.status = 'flagged' AND f.review_status = 'pending')                 AS pending,
            SUM(f.review_status = 'confirmed')                                        AS confirmed,
            SUM(f.review_status = 'dismissed')                                        AS dismissed,
            SUM(f.status = 'flagged' AND f.risk_level = 'critical')                   AS critical
        FROM fraud_checks f
        WHERE f.company_id = ?
    ", [$companyId]);

    $byEmployee = fetch_all($pdo, "
        SELECT
            c.erp_employee_id,
            COALESCE(c.erp_employee_name, CONCAT('ไม่ระบุ (', COALESCE(c.call_code, 'N/A'), ')')) AS employee_name,
            COUNT(*)                                                                  AS total,
            SUM(f.status = 'flagged')                                                 AS flagged,
            SUM(f.status = 'flagged' AND f.risk_level = 'critical')                   AS critical,
            SUM(f.status = 'flagged' AND f.risk_level = 'high')                       AS high,
            SUM(f.review_status = 'confirmed')                                        AS confirmed,
            SUM(f.status = 'flagged' AND f.review_status = 'pending')                 AS pending,
            COUNT(DISTINCT f.conversation_id)                                         AS conversations
        FROM fraud_checks f
        JOIN conversations c ON c.id = f.conversation_id
        WHERE f.company_id = ?
        GROUP BY c.erp_employee_id, employee_name
        ORDER BY critical DESC, high DESC, flagged DESC
    ", [$companyId]);

    json_response(['ok' => true, 'overall' => $overall, 'by_employee' => $byEmployee]);
}

function review_fraud_check(PDO $pdo, array $currentUser, int $companyId, int $checkId): void
{
    $check = fetch_one($pdo, 'SELECT id FROM fraud_checks WHERE id = ? AND company_id = ?', [$checkId, $companyId]);
    if (!$check) {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $input = json_input();
    $decision = $input['decision'] ?? '';
    if (!in_array($decision, ['confirmed', 'dismissed'], true)) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => "decision must be 'confirmed' or 'dismissed'"], 422);
    }

    $stmt = $pdo->prepare('
        UPDATE fraud_checks
        SET review_status = ?, reviewed_by = ?, reviewed_by_name = ?, review_note = ?, reviewed_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute([
        $decision,
        (int) ($currentUser['erp_user_id'] ?? 0) ?: null,
        $currentUser['erp_full_name'] ?? null,
        isset($input['note']) && is_string($input['note']) && trim($input['note']) !== '' ? trim($input['note']) : null,
        $checkId,
    ]);

    audit_log($pdo, $companyId, (int) ($currentUser['erp_user_id'] ?? 0), 'fraud_review_' . $decision, 'fraud_check', $checkId, ['note' => $input['note'] ?? null]);

    json_response(['ok' => true]);
}
