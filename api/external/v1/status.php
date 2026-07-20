<?php
// api/external/v1/status.php
require_once __DIR__ . '/../../core/bootstrap.php';

if (method() !== 'GET') {
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

$jobIdParam = $_GET['job_id'] ?? '';
if (!str_starts_with($jobIdParam, 'conv_')) {
    json_response(['success' => false, 'message' => 'Invalid job_id format'], 400);
}

$conversationId = (int) substr($jobIdParam, 5);

try {
    $stmt = $pdo->prepare('SELECT status, error_message FROM conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        json_response(['success' => false, 'message' => 'Job not found'], 404);
    }

    $status = $conv['status'];
    $progress = 0;
    
    switch ($status) {
        case 'pending': $progress = 10; break;
        case 'transcribing': $progress = 30; break;
        case 'analyzing_unified': $progress = 60; break;
        case 'indexing': $progress = 90; break;
        case 'completed': $progress = 100; break;
        case 'failed': $progress = 100; break;
    }

    $response = [
        'success' => true,
        'job_id' => $jobIdParam,
        'status' => $status,
        'progress' => $progress,
        'current_step' => $status
    ];
    
    if ($status === 'failed') {
        $response['error_message'] = $conv['error_message'];
    }

    json_response($response);
} catch (Exception $e) {
    json_response(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
