<?php

/**
 * Ghost-number detection: finds an employee repeatedly calling a phone number that is NOT a
 * customer and NOT another employee anywhere in the ERP — the signature of padding talk-time
 * with a personal contact (หัวหมอ โทรเข้าเบอร์ตัวเอง/คนรู้จัก) rather than genuine sales calls.
 *
 * Pure SQL/PHP, no AI cost. The phone system here always puts exactly one leg of every call on
 * a registered employee number (verified against production: 100% of a 7-day sample), so the
 * "other" leg is always classifiable as customer / colleague / unknown.
 *
 * A SINGLE call to an unregistered number is completely normal — cold-calling a prospect that
 * doesn't have a customer record yet is routine telesales work. The signal is REPETITION: the
 * same employee calling the same unregistered number again and again, especially spread across
 * many days/weeks/months, with no customer record ever appearing for it. Verified case in
 * production: one employee called the same unregistered number 7 times across 4 months
 * (2026-03-02 .. 2026-06-27), 3.5 hours total, the number never once became a customer, an
 * order, or a call_history entry (impossible anyway — call_history is keyed by customer_id).
 *
 * Findings are NOT written to fraud_checks: fraud_checks rows require a single conversation_id,
 * but this signal is inherently an aggregate over many calls (often unregistered
 * gdrive_file_index rows, not `conversations`), so it is computed live and shown as its own
 * report (mirrors how ui/long_calls.html's repeated_pairs table already works).
 */
class GhostNumberService
{
    /** Calls shorter than this are ignored — a wrong-number hangup shouldn't feed the pattern. */
    const MIN_DURATION_SECONDS = 30;
    /** A pair needs at least this many calls to be surfaced at all. */
    const MIN_CALLS = 2;

    /**
     * @return array{pairs: array<array<string,mixed>>, totals: array<string,mixed>}
     */
    public static function forCompany(PDO $pdo, PDO $erp, int $companyId, string $fromDate, string $toDate): array
    {
        // 1. Employee phones (ANY company — staff can share numbers across the group; a call
        //    to/from any of them is an internal call, never a "ghost" number).
        $userRows = fetch_all($erp, 'SELECT id, first_name, last_name, company_id, phone FROM users WHERE phone IS NOT NULL AND phone <> \'\'', []);
        $userByPhone = [];
        $thisCompanyEmployeeIds = [];
        foreach ($userRows as $u) {
            $n = self::normalize($u['phone']);
            if ($n === null) {
                continue;
            }
            $userByPhone[$n] = $u;
            if ((int) $u['company_id'] === $companyId) {
                $thisCompanyEmployeeIds[(int) $u['id']] = true;
            }
        }

        // 2. Customer phones for THIS company only — a number unregistered here but known to a
        //    different company in the group is a separate business relationship, not evidence of
        //    padding on this company's dime (still worth a note, added below as context).
        $custStmt = $erp->prepare('SELECT phone, recipient_phone, backup_phone FROM customers WHERE company_id = ?');
        $custStmt->execute([$companyId]);
        $customerPhones = [];
        foreach ($custStmt->fetchAll() as $c) {
            foreach ([$c['phone'], $c['recipient_phone'], $c['backup_phone']] as $p) {
                $n = self::normalize($p);
                if ($n !== null) {
                    $customerPhones[$n] = true;
                }
            }
        }

        // 3. Every call for this company in the window.
        $recStmt = $pdo->prepare('
            SELECT call_date, caller_phone, receiver_phone, duration_seconds
            FROM gdrive_file_index
            WHERE company_id = ? AND call_date BETWEEN ? AND ? AND duration_seconds >= ?
        ');
        $recStmt->execute([$companyId, $fromDate, $toDate, self::MIN_DURATION_SECONDS]);

        $pairs = [];
        $totalUnknownCalls = 0;
        $totalCallsScanned = 0;
        while ($r = $recStmt->fetch()) {
            $totalCallsScanned++;
            $callerN = self::normalize($r['caller_phone']);
            $recvN = self::normalize($r['receiver_phone']);
            $empA = $callerN !== null ? ($userByPhone[$callerN] ?? null) : null;
            $empB = $recvN !== null ? ($userByPhone[$recvN] ?? null) : null;

            // Exactly one side must be a known employee; both-or-neither can't be classified.
            if ($empA && !$empB) {
                $emp = $empA;
                $other = $recvN;
            } elseif ($empB && !$empA) {
                $emp = $empB;
                $other = $callerN;
            } else {
                continue;
            }
            // Only count calls made by THIS company's own staff — a call FROM another company's
            // employee (via a shared duplicate phone) into this company's line isn't this
            // company's padding to flag.
            if (!isset($thisCompanyEmployeeIds[(int) $emp['id']])) {
                continue;
            }
            if ($other === null || isset($customerPhones[$other]) || isset($userByPhone[$other])) {
                continue;
            }

            $totalUnknownCalls++;
            $key = $emp['id'] . '|' . $other;
            if (!isset($pairs[$key])) {
                $pairs[$key] = [
                    'employee_id' => (int) $emp['id'],
                    'employee_name' => trim($emp['first_name'] . ' ' . $emp['last_name']),
                    'number' => $other,
                    'calls' => 0,
                    'total_seconds' => 0,
                    'max_seconds' => 0,
                    'dates' => [],
                ];
            }
            $pairs[$key]['calls']++;
            $pairs[$key]['total_seconds'] += (int) $r['duration_seconds'];
            $pairs[$key]['max_seconds'] = max($pairs[$key]['max_seconds'], (int) $r['duration_seconds']);
            $pairs[$key]['dates'][$r['call_date']] = true;
        }

        // Keep repeated pairs, or a single call so long it's odd on its own.
        $flagged = array_values(array_filter($pairs, function ($p) {
            return $p['calls'] >= self::MIN_CALLS || $p['max_seconds'] >= 1800;
        }));

        // Cross-company context: is this "unknown" number actually a customer somewhere else in
        // the group? Cheap here — only checking the handful of flagged numbers, not all of them.
        $otherCompanyName = [];
        if (!empty($flagged)) {
            $numbers = array_unique(array_column($flagged, 'number'));
            $variants = [];
            foreach ($numbers as $n) {
                $variants[] = $n;
                $variants[] = strpos($n, '0') === 0 ? '66' . substr($n, 1) : $n;
            }
            $variants = array_unique($variants);
            $ph = implode(',', array_fill(0, count($variants), '?'));
            $stmt = $erp->prepare("
                SELECT c.phone, c.recipient_phone, c.backup_phone, co.name
                FROM customers c JOIN companies co ON co.id = c.company_id
                WHERE c.company_id != ? AND (c.phone IN ({$ph}) OR c.recipient_phone IN ({$ph}) OR c.backup_phone IN ({$ph}))
                LIMIT 200
            ");
            $stmt->execute(array_merge([$companyId], $variants, $variants, $variants));
            foreach ($stmt->fetchAll() as $row) {
                foreach ([$row['phone'], $row['recipient_phone'], $row['backup_phone']] as $p) {
                    $n = self::normalize($p);
                    if ($n !== null && in_array($n, $numbers, true)) {
                        $otherCompanyName[$n] = $row['name'];
                    }
                }
            }
        }

        $out = [];
        foreach ($flagged as $p) {
            $days = count($p['dates']);
            $hours = $p['total_seconds'] / 3600;
            $out[] = [
                'employee_id' => $p['employee_id'],
                'employee_name' => $p['employee_name'],
                'number' => $p['number'],
                'calls' => $p['calls'],
                'distinct_days' => $days,
                'total_hours' => round($hours, 1),
                'max_minutes' => (int) round($p['max_seconds'] / 60),
                'first_seen' => min(array_keys($p['dates'])),
                'last_seen' => max(array_keys($p['dates'])),
                'risk_level' => self::riskLevel($p['calls'], $days, $hours),
                'other_company_customer' => $otherCompanyName[$p['number']] ?? null,
            ];
        }
        usort($out, function ($a, $b) {
            return [$b['total_hours'], $b['calls']] <=> [$a['total_hours'], $a['calls']];
        });

        return [
            'pairs' => $out,
            'totals' => [
                'calls_scanned' => $totalCallsScanned,
                'unknown_number_calls' => $totalUnknownCalls,
                'flagged_pairs' => count($out),
            ],
        ];
    }

    private static function riskLevel(int $calls, int $days, float $hours): string
    {
        if ($hours >= 2 || ($calls >= 5 && $days >= 4)) {
            return 'critical';
        }
        if ($hours >= 1 || ($calls >= 3 && $days >= 3)) {
            return 'high';
        }
        if ($calls >= self::MIN_CALLS) {
            return 'medium';
        }
        return 'low'; // single very-long call
    }

    /** Local-format (0xxxxxxxxx) normalization, matching ErpLookupService::candidateFormats(). */
    private static function normalize(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone);
        if ($d === '' || $d === null) {
            return null;
        }
        if (strpos($d, '66') === 0 && strlen($d) >= 11) {
            return '0' . substr($d, 2);
        }
        if ($d[0] === '0') {
            return $d;
        }
        if (strlen($d) === 9) {
            return '0' . $d;
        }
        return $d;
    }
}
