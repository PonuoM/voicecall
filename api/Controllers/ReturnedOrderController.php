<?php

require_once __DIR__ . '/../Services/ReturnedOrderService.php';

/**
 * Returned-order (ตีกลับ) investigation API — see ReturnedOrderService for the case logic.
 *
 *   GET returned-orders?company_id=&from=&to=&statuses=Returned,Cancelled,BadDebt
 *   GET returned-orders/{orderId}
 *
 * Default window is 60 days of ORDER dates: the bounce lands 9-35 days after ordering, so a
 * short window would hide orders whose return just came back this week.
 */
function handle_returned_orders(PDO $pdo, PDO $erp, array $currentUser, ?string $id): void
{
    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    set_time_limit(120);

    if ($id !== null && $id !== '') {
        try {
            $detail = ReturnedOrderService::caseDetail($pdo, $erp, $id);
        } catch (Throwable $e) {
            json_response(['ok' => false, 'error' => 'INTERNAL_ERROR', 'message' => $e->getMessage()], 500);
            return;
        }
        if (!$detail) {
            json_response(['ok' => false, 'error' => 'NOT_FOUND', 'message' => 'Order not found'], 404);
        }
        // Company scoping: a non-super-admin must not open another company's case by guessing ids.
        if (empty($currentUser['erp_is_super_admin'])
            && (int) $detail['order']['company_id'] !== (int) ($currentUser['erp_company_id'] ?? 0)) {
            json_response(['ok' => false, 'error' => 'FORBIDDEN', 'message' => 'Order belongs to another company'], 403);
        }
        json_response(['ok' => true] + $detail);
    }

    $companyId = !empty($_GET['company_id']) ? (int) $_GET['company_id'] : (int) ($currentUser['erp_company_id'] ?? 0);
    if (!$companyId) {
        json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'company_id is required'], 422);
    }

    $to = !empty($_GET['to']) ? $_GET['to'] : date('Y-m-d');
    $from = !empty($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime($to . ' -60 days'));

    $statuses = ['Returned'];
    if (!empty($_GET['statuses'])) {
        $statuses = array_values(array_intersect(
            array_map('trim', explode(',', $_GET['statuses'])),
            ReturnedOrderService::ALLOWED_STATUSES
        ));
        if (empty($statuses)) {
            json_response(['ok' => false, 'error' => 'VALIDATION', 'message' => 'statuses must be a subset of ' . implode(',', ReturnedOrderService::ALLOWED_STATUSES)], 422);
        }
    }

    try {
        $result = ReturnedOrderService::listForCompany($pdo, $erp, $companyId, $from, $to, $statuses);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'error' => 'INTERNAL_ERROR', 'message' => $e->getMessage()], 500);
        return;
    }

    json_response(['ok' => true, 'from' => $from, 'to' => $to, 'statuses' => $statuses] + $result);
}
