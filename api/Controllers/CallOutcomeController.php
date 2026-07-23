<?php

require_once __DIR__ . '/../Services/ErpCallOutcomeService.php';

/**
 * Per-recording ERP outcome for a batch of Drive files — what the main dashboard calls for the
 * rows it is currently showing, so a 130k-file company doesn't have to enrich everything up front.
 *
 *   GET call-outcomes?ids=<gdrive_file_id>,<gdrive_file_id>,...
 *
 * Recording details come from gdrive_file_index by id rather than from the caller, so the client
 * cannot influence which customer a recording resolves to.
 */
function handle_call_outcomes(PDO $pdo, PDO $erp, array $currentUser, ?string $id): void
{
    if (method() !== 'GET') {
        json_response(['ok' => false, 'error' => 'NOT_FOUND'], 404);
    }

    $raw = trim((string) ($_GET['ids'] ?? ''));
    if ($raw === '') {
        json_response(['ok' => true, 'data' => []]);
    }

    $ids = array_values(array_filter(array_unique(explode(',', $raw)), function ($v) {
        return preg_match('/^[A-Za-z0-9_-]{10,128}$/', $v) === 1;
    }));
    if (empty($ids)) {
        json_response(['ok' => true, 'data' => []]);
    }
    $ids = array_slice($ids, 0, 100); // one screenful at a time

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $companyFilter = '';
    // Super admins browse every company; everyone else is pinned to their own.
    if (empty($currentUser['erp_is_super_admin'])) {
        $companyFilter = ' AND company_id = ?';
        $params[] = (int) ($currentUser['erp_company_id'] ?? 0);
    }

    $rows = fetch_all($pdo, "
        SELECT gdrive_file_id, company_id, call_date, call_time, caller_phone, receiver_phone, duration_seconds
        FROM gdrive_file_index
        WHERE gdrive_file_id IN ({$placeholders}){$companyFilter}
    ", $params);

    if (empty($rows)) {
        json_response(['ok' => true, 'data' => []]);
    }

    try {
        $outcomes = ErpCallOutcomeService::forRecordings($erp, $rows);
    } catch (Throwable $e) {
        // The dashboard works fine without this column — report the failure rather than 500ing.
        json_response(['ok' => true, 'data' => [], 'erp_error' => $e->getMessage()]);
    }

    $data = [];
    foreach ($outcomes as $fileId => $o) {
        $data[$fileId] = [
            'customer_name' => $o['customer']['name'] ?? null,
            'customer_id' => $o['customer']['id'] ?? null,
            'call_result' => $o['call_log']['result'] ?? null,
            'call_status' => $o['call_log']['status'] ?? null,
            'sold' => $o['sold'],
            'returned' => $o['returned'],
            'orders' => $o['orders'],
            'crm_coverage' => $o['crm_coverage'],
            'nearby_log_at' => $o['nearby_log_at'] ?? null,
        ];
    }

    json_response(['ok' => true, 'data' => $data]);
}
