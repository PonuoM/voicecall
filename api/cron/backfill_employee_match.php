<?php
/**
 * One-time backfill: re-checks every conversation with erp_employee_id still NULL against the
 * fixed ErpLookupService::findEmployeeByPhone() (see git history - the old version only tried
 * "0XXXXXXXXX" format, but ~54% of primacom_mini_erp.users.phone values are stored as
 * "66XXXXXXXXX" instead, so most employee matches were silently failing before this fix).
 * Safe to re-run - only touches rows that are still NULL.
 *
 * Run via CLI:
 *   php api/cron/backfill_employee_match.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Services/ErpLookupService.php';

// config.php's load_env() has to run before this check, or getenv() always sees an empty key.
if (PHP_SAPI !== 'cli') {
    $expectedKey = getenv('CRON_HTTP_KEY') ?: '';
    if (!$expectedKey || ($_GET['key'] ?? '') !== $expectedKey) {
        http_response_code(403);
        header('Content-Type: text/plain');
        die("Forbidden\n");
    }
}

set_time_limit(0);

$pdo = db_connect();
$erp = erp_connect();

$rows = $pdo->query("SELECT id, caller_phone, receiver_phone FROM conversations WHERE erp_employee_id IS NULL")->fetchAll();
echo "Checking " . count($rows) . " conversation(s) with no employee match yet.\n";

$update = $pdo->prepare('UPDATE conversations SET erp_employee_id = ?, erp_employee_name = ? WHERE id = ?');
$fixed = 0;

foreach ($rows as $row) {
    $employee = ErpLookupService::findEmployeeByPhone($erp, $row['caller_phone'])
        ?: ErpLookupService::findEmployeeByPhone($erp, $row['receiver_phone']);
    if ($employee) {
        $update->execute([$employee['id'], $employee['name'], $row['id']]);
        echo "  conversation {$row['id']}: matched employee #{$employee['id']} ({$employee['name']})\n";
        $fixed++;
    }
}

echo "Done. Fixed {$fixed} of " . count($rows) . " conversation(s).\n";
