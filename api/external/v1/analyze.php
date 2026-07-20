<?php
// api/external/v1/analyze.php
require_once __DIR__ . '/../../core/bootstrap.php';

if (method() !== 'POST') {
    json_response(['success' => false, 'message' => 'Method Not Allowed'], 405);
}

// Simple external auth
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';
$config = require __DIR__ . '/../../../config.php';
$expectedToken = 'Bearer ' . ($config['api']['auth_token'] ?? '');

if ($authHeader !== $expectedToken) {
    json_response(['success' => false, 'message' => 'Unauthorized external access'], 401);
}

// 1. Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['success' => false, 'message' => 'Invalid JSON'], 400);
}

$refId = $input['reference_id'] ?? '';
$audioUrl = $input['audio_url'] ?? '';
$callDate = $input['call_date'] ?? date('Y-m-d H:i:s');
$context = $input['context'] ?? null;
$companyId = $input['company_id'] ?? 1; // Default to company 1 if not provided

if (!$refId || !$audioUrl) {
    json_response(['success' => false, 'message' => 'Missing reference_id or audio_url'], 400);
}

// 2. Insert into conversations table as 'pending'
try {
    $stmt = $pdo->prepare('
        INSERT INTO conversations (company_id, call_code, audio_file_path, status, external_context)
        VALUES (?, ?, ?, ?, ?)
    ');
    $contextJson = $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : null;
    
    $stmt->execute([
        $companyId,
        $refId,
        $audioUrl,
        'pending',
        $contextJson
    ]);
    
    $jobId = $pdo->lastInsertId();
    
    json_response([
        'success' => true,
        'job_id' => 'conv_' . $jobId,
        'status' => 'pending',
        'message' => 'Audio submitted successfully. Please poll status using job_id.'
    ]);
} catch (Exception $e) {
    json_response(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
