<?php

require_once __DIR__ . '/../Services/ErpLookupService.php';
require_once __DIR__ . '/../Pipeline/ConversationPipeline.php';

/**
 * Phase 1: registration + ERP enrichment only — no AI pipeline yet. The frontend already knows
 * everything about a recording (it scanned Google Drive / parsed recordings.json itself), so
 * registration just takes that row as-is instead of re-scanning Drive server-side.
 */
function handle_conversations(PDO $pdo, PDO $erp, array $currentUser, ?string $id, ?string $action): void
{
    if ($id === null && method() === 'GET') {
        list_conversations($pdo, $currentUser);
        return;
    }

    if ($id === null && method() === 'POST') {
        register_conversation($pdo, $erp, $currentUser);
        return;
    }

    if ($id === 'bulk-register' && method() === 'POST') {
        bulk_register_conversations($pdo, $erp, $currentUser);
        return;
    }

    if ($id === 'status-map' && method() === 'GET') {
        conversation_status_map($pdo, $currentUser);
        return;
    }

    if ($id !== null && $action === null && method() === 'GET') {
        get_conversation_detail($pdo, $currentUser, (int) $id);
        return;
    }

    if ($id !== null && $action === 'process' && method() === 'POST') {
        process_conversation($pdo, $erp, $currentUser, (int) $id);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}

/**
 * Lean status lookup for every already-registered conversation in a company, so the dashboard
 * can pre-fill aiStatusMap on table load and show the correct "ดูผล AI" icon immediately - not
 * just avoid re-running the pipeline if clicked, but show the right icon without a click at all.
 * No pagination: the registered-conversations table stays far smaller than the full Drive
 * listing it's compared against, this is fine to return in full.
 */
function conversation_status_map(PDO $pdo, array $currentUser): void
{
    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
    $rows = fetch_all($pdo, 'SELECT id, source, audio_ref, status FROM conversations WHERE company_id = ?', [$companyId]);
    json_response(['ok' => true, 'data' => $rows]);
}

function list_conversations(PDO $pdo, array $currentUser): void
{
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(200, max(1, (int) ($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    // This filter used to be applied only when the request happened to pass ?company_id=, so the
    // default was "every conversation in every company" — any logged-in user could page through
    // the whole table, and naming another company's id worked just as well. A normal user is now
    // pinned to their own company whatever they ask for; only a super admin still gets the
    // unfiltered view (and can narrow it with ?company_id=), which is the behaviour that surface
    // was presumably written for.
    $requestedCompany = $_GET['company_id'] ?? null;
    if (empty($currentUser['erp_is_super_admin']) || !empty($requestedCompany)) {
        $where[] = 'company_id = ?';
        $params[] = resolve_company_id($currentUser, $requestedCompany);
    }
    if (!empty($_GET['status'])) {
        $where[] = 'status = ?';
        $params[] = $_GET['status'];
    }
    if (!empty($_GET['from'])) {
        $where[] = 'call_date >= ?';
        $params[] = $_GET['from'];
    }
    if (!empty($_GET['to'])) {
        $where[] = 'call_date <= ?';
        $params[] = $_GET['to'];
    }
    if (!empty($_GET['q'])) {
        $where[] = '(caller_phone LIKE ? OR receiver_phone LIKE ? OR erp_customer_name LIKE ? OR erp_employee_name LIKE ?)';
        $like = '%' . $_GET['q'] . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM conversations {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT id, company_id, source, audio_ref, call_code, call_date, call_time,
               caller_phone, receiver_phone, direction, size_bytes, duration_seconds,
               erp_customer_id, erp_customer_name, erp_employee_id, erp_employee_name,
               status, error_message, created_at, updated_at
        FROM conversations
        {$whereSql}
        ORDER BY call_date DESC, call_time DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    json_response(['ok' => true, 'data' => $rows, 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total]]);
}

function register_conversation(PDO $pdo, PDO $erp, array $currentUser): void
{
    $input = json_input();
    $result = register_one_conversation_row($pdo, $erp, $currentUser, $input);

    if (isset($result['error'])) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => $result['error']], 422);
    }

    json_response(['ok' => true, 'conversation' => $result['conversation'], 'created' => $result['created']], $result['created'] ? 201 : 200);
}

/**
 * Bulk version of register_conversation — accepts the same row shape the frontend already has
 * after scanning Google Drive (fileId/id/date/time/caller/receiver/direction/size), one entry
 * per array item. Registration alone is free (no AI call, just ERP phone lookups); actual
 * transcription/analysis still has to be triggered per-conversation (POST .../process) or via
 * cron/process_pending.php, deliberately — bulk-registering hundreds of calls must never also
 * silently trigger hundreds of paid AI calls in the same request.
 */
function bulk_register_conversations(PDO $pdo, PDO $erp, array $currentUser): void
{
    $input = json_input();
    $rows = $input['rows'] ?? [];
    if (!is_array($rows) || empty($rows)) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'rows (non-empty array) is required'], 422);
    }
    if (count($rows) > 1000) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'Max 1000 rows per bulk-register call'], 422);
    }

    $registered = 0;
    $alreadyExisted = 0;
    $skipped = 0;
    $errors = [];

    foreach ($rows as $i => $row) {
        if (!is_array($row)) {
            $skipped++;
            continue;
        }
        $rowInput = $row;
        if (!empty($input['company_id']) && empty($rowInput['company_id'])) {
            $rowInput['company_id'] = $input['company_id'];
        }
        $result = register_one_conversation_row($pdo, $erp, $currentUser, $rowInput);
        if (isset($result['error'])) {
            $skipped++;
            if (count($errors) < 20) {
                $errors[] = "row {$i}: {$result['error']}";
            }
            continue;
        }
        if ($result['created']) {
            $registered++;
        } else {
            $alreadyExisted++;
        }
    }

    json_response([
        'ok' => true,
        'registered' => $registered,
        'already_existed' => $alreadyExisted,
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
}

/**
 * @return array{conversation:?array,created:bool}|array{error:string}
 */
function register_one_conversation_row(PDO $pdo, PDO $erp, array $currentUser, array $input): array
{
    $source = ($input['source'] ?? 'gdrive') === 'local' ? 'local' : 'gdrive';
    $audioRef = trim((string) ($input['fileId'] ?? $input['file'] ?? $input['audio_ref'] ?? ''));
    if ($audioRef === '') {
        return ['error' => 'fileId (gdrive) or file (local) is required'];
    }

    $companyId = resolve_company_id($currentUser, $input['company_id'] ?? null);
    $callerPhone = nullable_str($input['caller'] ?? null);
    $receiverPhone = nullable_str($input['receiver'] ?? null);
    $direction = ($input['direction'] ?? null) === 'OUT' ? 'OUT' : (($input['direction'] ?? null) === 'IN' ? 'IN' : null);

    $existing = fetch_one($pdo, 'SELECT * FROM conversations WHERE source = ? AND audio_ref = ?', [$source, $audioRef]);
    if ($existing) {
        return ['conversation' => $existing, 'created' => false];
    }

    // Best-effort ERP enrichment — a phone matching `customers` is a customer regardless of
    // whether it appeared as caller or receiver; same logic for `users` and employees.
    $customer = ErpLookupService::findCustomerByPhone($erp, $callerPhone)
        ?: ErpLookupService::findCustomerByPhone($erp, $receiverPhone);
    $employee = ErpLookupService::findEmployeeByPhone($erp, $callerPhone)
        ?: ErpLookupService::findEmployeeByPhone($erp, $receiverPhone);

    $stmt = $pdo->prepare('
        INSERT INTO conversations
            (company_id, source, audio_ref, call_code, call_date, call_time, caller_phone, receiver_phone,
             direction, size_bytes, erp_customer_id, erp_customer_name, erp_employee_id, erp_employee_name, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')
    ');
    $stmt->execute([
        $companyId,
        $source,
        $audioRef,
        nullable_str($input['id'] ?? null),
        nullable_str($input['date'] ?? null),
        nullable_str($input['time'] ?? null),
        $callerPhone,
        $receiverPhone,
        $direction,
        isset($input['size']) ? (int) $input['size'] : null,
        $customer ? $customer['id'] : null,
        $customer ? $customer['name'] : null,
        $employee ? $employee['id'] : null,
        $employee ? $employee['name'] : null,
    ]);
    $conversationId = (int) $pdo->lastInsertId();

    audit_log($pdo, $companyId, (int) ($currentUser['erp_user_id'] ?? 0), 'register', 'conversation', $conversationId, ['audio_ref' => $audioRef]);

    $row = fetch_one($pdo, 'SELECT * FROM conversations WHERE id = ?', [$conversationId]);
    return ['conversation' => $row, 'created' => true];
}

require_once __DIR__ . '/../Services/ConversationDataService.php';

function get_conversation_detail(PDO $pdo, array $currentUser, int $conversationId): void
{
    $detail = ConversationDataService::getFullDetail($pdo, $conversationId);
    if (!$detail) {
        json_response(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Conversation not found'], 404);
    }
    // Everything in $detail — transcript, recording metadata, fraud findings, the customer's and
    // the employee's names — was previously returned for any id the caller cared to increment
    // through. The guard stays here even though the query moved into ConversationDataService: the
    // service is shared with the pipeline, which has no caller to authorise.
    require_company_access($currentUser, (int) $detail['conversation']['company_id']);

    $detail['ok'] = true;
    json_response($detail);
}

function process_conversation(PDO $pdo, PDO $erp, array $currentUser, int $conversationId): void
{
    $conv = fetch_one($pdo, 'SELECT * FROM conversations WHERE id = ?', [$conversationId]);
    if (!$conv) {
        json_response(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Conversation not found'], 404);
    }
    // Not just a read: this spends money on STT + LLM calls against the owning company's budget.
    require_company_access($currentUser, (int) $conv['company_id']);

    $result = ConversationPipeline::run($pdo, $erp, $conversationId);
    json_response($result, $result['ok'] ? 200 : 500);
}

function nullable_str($value): ?string
{
    if ($value === null) {
        return null;
    }
    $s = trim((string) $value);
    return $s === '' ? null : $s;
}
