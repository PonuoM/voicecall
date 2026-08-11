<?php

require_once __DIR__ . '/../Services/GhostNumberService.php';

/**
 * Ghost-number report API — see GhostNumberService for the detection logic.
 *
 *   GET ghost-numbers?company_id=&from=&to=
 *
 * Defaults to a 90-day window: the verified production case took 4 months to accumulate its
 * pattern, so a short window misses exactly the slow-burn cases this is meant to catch.
 */
function handle_ghost_numbers(PDO $pdo, PDO $erp, array $currentUser, ?string $id): void
{
    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $companyId = resolve_company_id($currentUser, $_GET['company_id'] ?? null);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $to = !empty($_GET['to']) ? $_GET['to'] : date('Y-m-d');
    $from = !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -90 days'));

    set_time_limit(120);

    try {
        $result = GhostNumberService::forCompany($pdo, $erp, $companyId, $from, $to);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'INTERNAL_ERROR', 'message' => $e->getMessage()], 500);
    }

    json_response(['ok' => true, 'from' => $from, 'to' => $to] + $result);
}
