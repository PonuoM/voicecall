<?php
/**
 * One-off repair: matches employee/customer names against the ERP for conversations that were
 * registered without it. backlog_drain.php deliberately skips this lookup for short calls (see
 * its comment) because one lookup per row against a remote DB, times tens of thousands of rows,
 * was a real cost with nothing reading the result. Nothing read it, until the timeline page did -
 * that assumption is why 52,011 of 52,330 production conversations have no erp_employee_id and
 * 52,065 have no erp_customer_id.
 *
 * This uses the batch lookup (ErpLookupService::find*ByPhones - one query per few hundred numbers
 * instead of one per row) to make the same job cheap enough to actually run. Processed in pages
 * by id, existing matches are never overwritten (COALESCE keeps whatever is already there), and
 * customer matches are scoped to the conversation's own company_id to avoid cross-company
 * phone collisions.
 *
 * Run: php ops/backfill_erp_names.php [batch_size]
 */

require_once __DIR__ . '/../api/config.php';
require_once __DIR__ . '/../api/Services/ErpLookupService.php';
set_time_limit(0);

const PROD = ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_voicelog', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'];
const ERP = ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_mini_erp', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'];

function connect($cfg)
{
    return new PDO(
        "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4",
        $cfg['user'],
        $cfg['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
}

$pdo = connect(PROD);
$erp = connect(ERP);

$batchSize = isset($argv[1]) ? max(50, (int) $argv[1]) : 2000;

$update = $pdo->prepare('
    UPDATE conversations SET
        erp_customer_id = COALESCE(erp_customer_id, ?),
        erp_customer_name = COALESCE(erp_customer_name, ?),
        erp_employee_id = COALESCE(erp_employee_id, ?),
        erp_employee_name = COALESCE(erp_employee_name, ?)
    WHERE id = ?
');

$lastId = 0;
$totalRows = 0;
$totalCustMatched = 0;
$totalEmpMatched = 0;
$batchNum = 0;

while (true) {
    $stmt = $pdo->prepare('
        SELECT id, company_id, caller_phone, receiver_phone
        FROM conversations
        WHERE id > ? AND (erp_employee_id IS NULL OR erp_customer_id IS NULL)
        ORDER BY id ASC
        LIMIT ?
    ');
    $stmt->bindValue(1, $lastId, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
    if (!$rows) {
        break;
    }
    $batchNum++;

    // Group by company_id so the customer lookup can be scoped per company (findCustomersByPhones
    // takes one companyId at a time); this system only has 2 companies, so that is at most 2 extra
    // sub-queries per batch, not per row.
    $byCompany = [];
    $allPhones = [];
    foreach ($rows as $row) {
        $byCompany[(int) $row['company_id']][] = $row;
        if ($row['caller_phone']) $allPhones[] = $row['caller_phone'];
        if ($row['receiver_phone']) $allPhones[] = $row['receiver_phone'];
    }
    $allPhones = array_values(array_unique($allPhones));

    $employees = ErpLookupService::findEmployeesByPhones($erp, $allPhones);

    $customersByCompany = [];
    foreach (array_keys($byCompany) as $companyId) {
        $customersByCompany[$companyId] = ErpLookupService::findCustomersByPhones($erp, $allPhones, $companyId);
    }

    $custMatched = 0;
    $empMatched = 0;
    foreach ($rows as $row) {
        $companyId = (int) $row['company_id'];
        $customers = $customersByCompany[$companyId] ?? [];

        $customer = $customers[$row['caller_phone']] ?? $customers[$row['receiver_phone']] ?? null;
        $employee = $employees[$row['caller_phone']] ?? $employees[$row['receiver_phone']] ?? null;

        if ($customer) $custMatched++;
        if ($employee) $empMatched++;

        $update->execute([
            $customer['id'] ?? null,
            $customer['name'] ?? null,
            $employee['id'] ?? null,
            $employee['name'] ?? null,
            $row['id'],
        ]);
    }

    $totalRows += count($rows);
    $totalCustMatched += $custMatched;
    $totalEmpMatched += $empMatched;
    $lastId = (int) $rows[count($rows) - 1]['id'];

    echo "batch {$batchNum}: {$totalRows} rows so far (this batch: " . count($rows)
        . ", matched customer={$custMatched} employee={$empMatched}, last id={$lastId})\n";
}

echo "\nDone. {$totalRows} conversation(s) checked. customer matched={$totalCustMatched} employee matched={$totalEmpMatched}\n";
