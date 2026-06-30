<?php

function handle_compliance(PDO $pdo, array $currentUser, ?string $id, ?string $action): void
{
    $companyId = (int) ($currentUser['erp_company_id'] ?? 0);

    if ($id === 'rules') {
        handle_compliance_rules($pdo, $companyId, $action);
        return;
    }

    if ($id === 'violations' && method() === 'GET') {
        list_violations($pdo, $companyId);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

function handle_compliance_rules(PDO $pdo, int $companyId, ?string $ruleId): void
{
    if ($ruleId === null && method() === 'GET') {
        $rows = fetch_all($pdo, 'SELECT * FROM compliance_rules WHERE company_id = ? ORDER BY created_at DESC', [$companyId]);
        foreach ($rows as &$r) {
            $r['prohibited_words'] = json_decode($r['prohibited_words'] ?? '[]', true);
            $r['required_phrases'] = json_decode($r['required_phrases'] ?? '[]', true);
        }
        json_response(['ok' => true, 'data' => $rows]);
    }

    if ($ruleId === null && method() === 'POST') {
        $input = json_input();
        if (empty($input['rule_name']) || empty($input['category'])) {
            json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'rule_name and category are required'], 422);
        }
        $stmt = $pdo->prepare('
            INSERT INTO compliance_rules (company_id, rule_name, category, description, rule_type, severity_default, prohibited_words, required_phrases, active)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $companyId,
            $input['rule_name'],
            $input['category'],
            $input['description'] ?? null,
            $input['rule_type'] ?? 'custom',
            $input['severity_default'] ?? 'medium',
            json_encode($input['prohibited_words'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['required_phrases'] ?? [], JSON_UNESCAPED_UNICODE),
            isset($input['active']) ? (int) (bool) $input['active'] : 1,
        ]);
        json_response(['ok' => true, 'id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($ruleId !== null && method() === 'PUT') {
        $existing = fetch_one($pdo, 'SELECT id FROM compliance_rules WHERE id = ? AND company_id = ?', [(int) $ruleId, $companyId]);
        if (!$existing) {
            json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
        }
        $input = json_input();
        $stmt = $pdo->prepare('
            UPDATE compliance_rules SET rule_name=?, category=?, description=?, rule_type=?, severity_default=?, prohibited_words=?, required_phrases=?, active=?
            WHERE id = ? AND company_id = ?
        ');
        $stmt->execute([
            $input['rule_name'] ?? '',
            $input['category'] ?? '',
            $input['description'] ?? null,
            $input['rule_type'] ?? 'custom',
            $input['severity_default'] ?? 'medium',
            json_encode($input['prohibited_words'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($input['required_phrases'] ?? [], JSON_UNESCAPED_UNICODE),
            isset($input['active']) ? (int) (bool) $input['active'] : 1,
            (int) $ruleId,
            $companyId,
        ]);
        json_response(['ok' => true]);
    }

    if ($ruleId !== null && method() === 'DELETE') {
        $pdo->prepare('DELETE FROM compliance_rules WHERE id = ? AND company_id = ?')->execute([(int) $ruleId, $companyId]);
        json_response(['ok' => true]);
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

function list_violations(PDO $pdo, int $companyId): void
{
    $where = ['c.company_id = ?'];
    $params = [$companyId];

    if (!empty($_GET['severity'])) {
        $where[] = 'v.severity = ?';
        $params[] = $_GET['severity'];
    }
    if (!empty($_GET['employee_id'])) {
        $where[] = 'c.erp_employee_id = ?';
        $params[] = (int) $_GET['employee_id'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'v.created_at >= ?';
        $params[] = $_GET['from'] . ' 00:00:00';
    }
    if (!empty($_GET['to'])) {
        $where[] = 'v.created_at <= ?';
        $params[] = $_GET['to'] . ' 23:59:59';
    }

    $whereSql = implode(' AND ', $where);
    $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

    $rows = fetch_all($pdo, "
        SELECT v.*, c.call_code, c.call_date, c.call_time, c.erp_employee_name, c.erp_customer_name
        FROM violations v
        JOIN conversations c ON c.id = v.conversation_id
        WHERE {$whereSql}
        ORDER BY v.created_at DESC
        LIMIT {$limit}
    ", $params);

    json_response(['ok' => true, 'data' => $rows]);
}
