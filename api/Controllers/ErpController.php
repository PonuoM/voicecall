<?php

require_once __DIR__ . '/../core/bootstrap.php';
require_once __DIR__ . '/../Services/ErpLookupService.php';

function handle_erp(PDO $pdo, PDO $erp, ?array $currentUser, ?string $id, ?string $action): void
{
    // Custom Auth using ERP_API_KEY for server-to-server calls
    $expectedKey = getenv('ERP_API_KEY');
    if (empty($expectedKey)) {
        json_response(['ok' => false, 'error' => 'NOT_CONFIGURED', 'message' => 'ERP_API_KEY is not configured'], 500);
    }

    $headers = getallheaders();
    
    // Case-insensitive header lookup
    $authHeader = '';
    foreach ($headers as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $authHeader = $v;
            break;
        }
    }
    
    // Fallback if Apache passes it via SERVER array instead
    if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }
    
    if (empty($authHeader) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }

    if (!str_starts_with($authHeader, 'Bearer ') || trim(substr($authHeader, 7)) !== $expectedKey) {
        json_response([
            'ok' => false, 
            'error' => 'UNAUTHORIZED', 
            'message' => 'Invalid or missing Bearer token',
            'debug_received_headers' => $headers,
            'debug_expected_key' => $expectedKey
        ], 401);
    }

    if ($id === 'summarize' && method() === 'POST') {
        erp_summarize($pdo, $erp);
        return;
    }

    json_response(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'ERP resource not found'], 404);
}

function erp_summarize(PDO $pdo, PDO $erp): void
{
    $input = json_input();
    
    $audioUrl = trim((string) ($input['audio_url'] ?? ''));
    if ($audioUrl === '') {
        json_response(['ok' => false, 'error' => 'INVALID_PAYLOAD', 'message' => 'Missing required field: audio_url'], 400);
    }
    
    // Extract Google Drive file ID
    $fileId = null;
    if (preg_match('/(?:id=|\/d\/)([a-zA-Z0-9_-]{25,})/', $audioUrl, $matches)) {
        $fileId = $matches[1];
    } else {
        json_response(['ok' => false, 'error' => 'INVALID_PAYLOAD', 'message' => 'Could not extract Google Drive file ID from audio_url'], 400);
    }

    $companyId = (int) (getenv('ERP_COMPANY_ID') ?: 1);
    $orderId = trim((string) ($input['order_id'] ?? ''));
    $customerId = trim((string) ($input['customer_id'] ?? ''));
    $contextType = trim((string) ($input['context_type'] ?? ''));

    // Optional context injection for AI
    $externalContext = [];
    if ($orderId !== '') $externalContext['order_id'] = $orderId;
    if ($contextType !== '') $externalContext['context_type'] = $contextType;
    $contextStr = !empty($externalContext) ? json_encode($externalContext, JSON_UNESCAPED_UNICODE) : null;

    // Check if we already have this conversation (prevents Duplicate entry 1062)
    $stmtCheck = $pdo->prepare('SELECT id, status FROM conversations WHERE company_id = ? AND source = \'gdrive\' AND audio_ref = ? LIMIT 1');
    $stmtCheck->execute([$companyId, $fileId]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $conversationId = (int)$existing['id'];
        // We reuse the existing conversation ID and let the pipeline run again.
        // The ConversationPipeline will automatically clear old summaries and re-transcribe.
    } else {
        $stmt = $pdo->prepare('
            INSERT INTO conversations
                (company_id, source, audio_ref, erp_customer_id, status)
            VALUES (?, \'gdrive\', ?, ?, \'pending\')
        ');
        
        $stmt->execute([
            $companyId,
            $fileId,
            $customerId !== '' ? $customerId : null
        ]);
        
        $conversationId = (int) $pdo->lastInsertId();
    }

    if ($contextStr !== null) {
        // Safe update for external_context in case the column exists (ignore if it fails)
        try {
            $pdo->prepare('UPDATE conversations SET external_context = ? WHERE id = ?')->execute([$contextStr, $conversationId]);
        } catch (Throwable $e) {
            // external_context column might not exist, silently ignore
        }
    }
    
    // --- Process Synchronously ---
    require_once __DIR__ . '/../Pipeline/ConversationPipeline.php';
    
    // Extend time limit so the HTTP request doesn't time out while waiting for AI
    set_time_limit(0);

    $result = ConversationPipeline::run($pdo, $erp, $conversationId);

    if (!$result['ok']) {
        json_response([
            'ok' => false,
            'status' => 'failed',
            'conversation_id' => $conversationId,
            'message' => $result['error'] ?? 'Pipeline failed'
        ], 500);
    }

    // Fetch the final results to return synchronously
    $conv = fetch_one($pdo, 'SELECT * FROM conversations WHERE id = ?', [$conversationId]);
    $summary = fetch_one($pdo, 'SELECT * FROM summaries WHERE conversation_id = ?', [$conversationId]);
    $entities = fetch_one($pdo, 'SELECT * FROM extracted_entities WHERE conversation_id = ?', [$conversationId]);

    $keywords = [];
    if ($summary && !empty($summary['important_keywords'])) {
        $decoded = json_decode($summary['important_keywords'], true);
        if (is_array($decoded)) {
            $keywords = $decoded;
        }
    }

    json_response([
        'ok' => true,
        'status' => 'completed',
        'conversation_id' => $conversationId,
        'message' => 'Audio processed successfully.',
        'call_date' => $conv['call_date'] ?? null,
        'call_time' => $conv['call_time'] ?? null,
        'caller_phone' => $conv['caller_phone'] ?? null,
        'receiver_phone' => $conv['receiver_phone'] ?? null,
        'direction' => $conv['direction'] ?? null,
        'erp_customer_id' => $conv['erp_customer_id'] ?? null,
        'erp_employee_id' => $conv['erp_employee_id'] ?? null,
        'summary' => [
            'executive_summary' => $summary['executive_summary'] ?? null,
            'customer_sentiment' => $summary['customer_sentiment'] ?? null,
            'important_keywords' => $keywords
        ],
        'entities' => [
            'issue_category' => $entities['issue_category'] ?? null,
            'priority' => $entities['priority'] ?? null,
        ]
    ], 200);
}
