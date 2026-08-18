<?php
// api/Controllers/UnknownNumberController.php

/**
 * Review queue for phone numbers that repeatedly place outbound calls in a company's log while
 * matching nothing in the ERP — see UnknownNumberService for how a number gets here and why this
 * asks rather than auto-skipping.
 *
 *   GET  unknown-numbers                status=pending|skip|allow|all (default pending)
 *   POST unknown-numbers/{id}/decide    body {decision: "skip"|"allow", note?: string}
 */
function handle_unknown_numbers(PDO $pdo, array $currentUser, ?string $id, ?string $action): void
{
    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);

    if ($id === null && method() === 'GET') {
        list_unknown_numbers($pdo, $companyId);
        return;
    }

    if ($id !== null && ctype_digit($id) && $action === 'decide' && method() === 'POST') {
        decide_unknown_number($pdo, $currentUser, $companyId, (int) $id);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

function list_unknown_numbers(PDO $pdo, int $companyId): void
{
    $status = $_GET['status'] ?? 'pending';
    if (!in_array($status, ['pending', 'skip', 'allow', 'all'], true)) {
        $status = 'pending';
    }

    $where = ['company_id = ?'];
    $params = [$companyId];
    if ($status !== 'all') {
        $where[] = 'decision = ?';
        $params[] = $status;
    }

    $rows = fetch_all($pdo, '
        SELECT id, phone_number, call_count, distinct_destinations, first_seen, last_seen,
               total_seconds, sample_json, decision, decided_by_name, decided_at, note
        FROM unknown_number_reviews
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY call_count DESC, last_seen DESC
    ', $params);

    foreach ($rows as &$row) {
        $row['sample'] = json_decode($row['sample_json'] ?? '[]', true) ?: [];
        unset($row['sample_json']);
    }
    unset($row);

    $counts = fetch_all($pdo, '
        SELECT decision, COUNT(*) AS n FROM unknown_number_reviews WHERE company_id = ? GROUP BY decision
    ', [$companyId]);
    $countsByStatus = ['pending' => 0, 'skip' => 0, 'allow' => 0];
    foreach ($counts as $c) {
        $countsByStatus[$c['decision']] = (int) $c['n'];
    }

    json_response(['ok' => true, 'status' => $status, 'counts' => $countsByStatus, 'rows' => $rows]);
}

function decide_unknown_number(PDO $pdo, array $currentUser, int $companyId, int $id): void
{
    $row = fetch_one($pdo, 'SELECT id FROM unknown_number_reviews WHERE id = ? AND company_id = ?', [$id, $companyId]);
    if (!$row) {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $input = json_input();
    $decision = $input['decision'] ?? '';
    if (!in_array($decision, ['skip', 'allow'], true)) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => "decision must be 'skip' or 'allow'"], 422);
    }
    $note = isset($input['note']) && is_string($input['note']) && trim($input['note']) !== '' ? trim($input['note']) : null;

    $stmt = $pdo->prepare('
        UPDATE unknown_number_reviews
        SET decision = ?, decided_by = ?, decided_by_name = ?, note = ?, decided_at = NOW()
        WHERE id = ?
    ');
    $stmt->execute([
        $decision,
        (int) ($currentUser['erp_user_id'] ?? 0) ?: null,
        $currentUser['erp_full_name'] ?? null,
        $note,
        $id,
    ]);

    audit_log($pdo, $companyId, (int) ($currentUser['erp_user_id'] ?? 0), 'unknown_number_' . $decision, 'unknown_number_review', $id, ['note' => $note]);

    json_response(['ok' => true]);
}
