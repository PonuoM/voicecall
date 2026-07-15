<?php
// api/external/v1/result.php
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
    $stmt = $pdo->prepare('SELECT status FROM conversations WHERE id = ?');
    $stmt->execute([$conversationId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conv) {
        json_response(['success' => false, 'message' => 'Job not found'], 404);
    }

    if ($conv['status'] !== 'completed') {
        json_response([
            'success' => false, 
            'message' => 'Analysis not yet completed',
            'current_status' => $conv['status']
        ], 400);
    }

    // Fetch results
    $res = ['success' => true, 'job_id' => $jobIdParam, 'status' => 'completed', 'results' => []];

    // Transcript
    $tStmt = $pdo->prepare('SELECT full_text FROM transcripts WHERE conversation_id = ?');
    $tStmt->execute([$conversationId]);
    $res['results']['transcript'] = $tStmt->fetchColumn() ?: '';

    // Summary
    $sStmt = $pdo->prepare('SELECT * FROM summaries WHERE conversation_id = ?');
    $sStmt->execute([$conversationId]);
    if ($sum = $sStmt->fetch(PDO::FETCH_ASSOC)) {
        // Decode JSON fields
        foreach (['key_topics', 'action_items', 'decisions_made', 'follow_up_tasks', 'important_keywords'] as $k) {
            $sum[$k] = json_decode($sum[$k] ?? '[]', true);
        }
        $res['results']['summary'] = $sum;
    }

    // Entities
    $eStmt = $pdo->prepare('SELECT * FROM extracted_entities WHERE conversation_id = ?');
    $eStmt->execute([$conversationId]);
    if ($ent = $eStmt->fetch(PDO::FETCH_ASSOC)) {
        foreach (['order_info', 'appointment', 'tags'] as $k) {
            $ent[$k] = json_decode($ent[$k] ?? '[]', true);
        }
        $res['results']['entities'] = $ent;
    }

    // Compliance
    $cStmt = $pdo->prepare('SELECT overall_status, violations_json FROM compliance_reports WHERE conversation_id = ?');
    $cStmt->execute([$conversationId]);
    if ($comp = $cStmt->fetch(PDO::FETCH_ASSOC)) {
        $res['results']['compliance'] = [
            'overall_status' => $comp['overall_status'],
            'violations' => json_decode($comp['violations_json'] ?? '[]', true)
        ];
    }

    json_response($res);

} catch (Exception $e) {
    json_response(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
