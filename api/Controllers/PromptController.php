<?php
// api/Controllers/PromptController.php
require_once __DIR__ . '/../Agents/UnifiedPipelineAgent.php';

function handle_prompts(PDO $pdo, $currentUser, $id, $action)
{
    if (!$currentUser || empty($currentUser['erp_is_super_admin'])) {
        json_response(['ok' => false, 'error' => 'FORBIDDEN', 'message' => 'Super Admin only'], 403);
    }

    $companyId = (int)($_GET['company_id'] ?? 0);
    if (!$companyId && method() === 'GET') {
        json_response(['ok' => false, 'error' => 'BAD_REQUEST', 'message' => 'Missing company_id'], 400);
    }

    if (method() === 'GET') {
        $stmt = $pdo->prepare("SELECT additional_prompt, max_tokens FROM ai_prompts WHERE company_id = ? AND agent_name = 'unified'");
        $stmt->execute([$companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $defaultPrompt = UnifiedPipelineAgent::DEFAULT_SYSTEM_PROMPT;
        
        json_response([
            'ok' => true,
            'default_prompt' => $defaultPrompt,
            'additional_prompt' => $row ? $row['additional_prompt'] : '',
            'max_tokens' => $row ? $row['max_tokens'] : null
        ]);
    }

    if (method() === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $companyIdPost = (int)($input['company_id'] ?? 0);
        $additionalPrompt = $input['additional_prompt'] ?? '';
        $maxTokens = $input['max_tokens'] ?? null;

        if (!$companyIdPost) {
            json_response(['ok' => false, 'error' => 'BAD_REQUEST', 'message' => 'Missing company_id'], 400);
        }
        
        if (trim($additionalPrompt) === '') {
            $additionalPrompt = null;
        }
        if ($maxTokens === '') {
            $maxTokens = null;
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO ai_prompts (company_id, agent_name, additional_prompt, max_tokens) 
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                additional_prompt = VALUES(additional_prompt),
                max_tokens = VALUES(max_tokens)
            ');
            $stmt->execute([$companyIdPost, 'unified', $additionalPrompt, $maxTokens]);

            json_response(['ok' => true, 'message' => 'Prompt saved successfully']);
        } catch (Exception $e) {
            json_response(['ok' => false, 'error' => 'DB_ERROR', 'message' => $e->getMessage()], 500);
        }
    }

    json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
}
