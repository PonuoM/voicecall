<?php

require_once __DIR__ . '/../Agents/AssistantAgent.php';

function handle_assistant(PDO $pdo, array $currentUser, ?string $id, ?string $action): void
{
    $companyId = (int) ($currentUser['erp_company_id'] ?? 0);

    if ($id === 'ask' && method() === 'POST') {
        $input = json_input();
        $question = trim($input['question'] ?? '');
        if ($question === '') {
            json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'question is required'], 422);
        }
        $result = AssistantAgent::answer($pdo, $companyId, $question, (int) ($currentUser['erp_user_id'] ?? 0));
        json_response(['ok' => true, 'answer' => $result['answer'], 'sources' => $result['sources']]);
    }

    if ($id === 'history' && method() === 'GET') {
        $rows = fetch_all($pdo, 'SELECT id, question, answer, sources, created_at FROM assistant_queries WHERE company_id = ? ORDER BY created_at DESC LIMIT 50', [$companyId]);
        foreach ($rows as &$r) {
            $r['sources'] = json_decode($r['sources'] ?? '[]', true);
        }
        json_response(['ok' => true, 'data' => $rows]);
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
}
