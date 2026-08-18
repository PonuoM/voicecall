<?php
/**
 * Refreshes the review queue in unknown_number_reviews for every company.
 *
 * Deliberately separate from the fast, frequent worker cycle (sync/reap/process/drain, every 20
 * minutes) — a scan reads a company's whole gdrive_file_index against the ERP, and the pattern this
 * looks for (a number quietly placing outbound calls for months) does not change on a 20-minute
 * clock. Run every few hours from its own systemd timer; see ops/.
 *
 *   php api/cron/scan_unknown_numbers.php
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/UnknownNumberService.php';

set_time_limit(0);

if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        exit("Forbidden\n");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$pdo = db_connect();
$erp = erp_connect();

// Both companies today; a third joining later is a config change, not a code change.
$companyIds = array_filter(array_map('intval', explode(',', getenv('UNKNOWN_NUMBER_COMPANY_IDS') ?: '1,2')));

foreach ($companyIds as $companyId) {
    $found = UnknownNumberService::scan($pdo, $erp, $companyId);
    echo date('Y-m-d H:i:s') . " company {$companyId}: {$found} candidate(s) at >= " . UnknownNumberService::MIN_CALLS . " calls\n";
}
