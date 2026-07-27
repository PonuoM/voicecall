<?php

require_once __DIR__ . '/core/bootstrap.php';

try {
    switch ($resource) {
        case 'conversations':
            require_once __DIR__ . '/Controllers/ConversationController.php';
            handle_conversations($pdo, erp(), $currentUser, $id, $action);
            break;

        case 'compliance':
            require_once __DIR__ . '/Controllers/ComplianceController.php';
            handle_compliance($pdo, $currentUser, $id, $action);
            break;

        case 'fraud':
            require_once __DIR__ . '/Controllers/FraudController.php';
            handle_fraud($pdo, $currentUser, $id, $action);
            break;

        case 'cost':
            require_once __DIR__ . '/Controllers/CostController.php';
            handle_cost($pdo, $currentUser, $id, $action);
            break;

        case 'long-calls':
            require_once __DIR__ . '/Controllers/LongCallController.php';
            handle_long_calls($pdo, erp(), $currentUser, $id);
            break;

        case 'call-outcomes':
            require_once __DIR__ . '/Controllers/CallOutcomeController.php';
            handle_call_outcomes($pdo, erp(), $currentUser, $id);
            break;

        case 'ghost-numbers':
            require_once __DIR__ . '/Controllers/GhostNumberController.php';
            handle_ghost_numbers($pdo, erp(), $currentUser, $id);
            break;

        case 'returned-orders':
            require_once __DIR__ . '/Controllers/ReturnedOrderController.php';
            handle_returned_orders($pdo, erp(), $currentUser, $id);
            break;

        case 'assistant':
            require_once __DIR__ . '/Controllers/AssistantController.php';
            handle_assistant($pdo, $currentUser, $id, $action);
            break;

        case 'drive-index':
            require_once __DIR__ . '/Controllers/DriveIndexController.php';
            handle_drive_index($pdo, $currentUser, $id);
            break;

        case 'prompts':
            require_once __DIR__ . '/Controllers/PromptController.php';
            handle_prompts($pdo, $currentUser, $id, $action);
            break;

        default:
            json_response(['ok' => false, 'error' => 'NOT_FOUND', 'message' => "Unknown resource '{$resource}'"], 404);
    }
} catch (Throwable $e) {
    file_put_contents(LOG_DIR . '/api_error.log', date('Y-m-d H:i:s') . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
    json_response(['ok' => false, 'error' => 'INTERNAL_ERROR', 'message' => $e->getMessage()], 500);
}
