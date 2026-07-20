<?php

require_once __DIR__ . '/ErpLookupService.php';

/**
 * Smart sampling: picks WHICH unprocessed recordings deserve paid AI analysis, so the platform
 * audits ~5% of call volume instead of burning 15-39k THB/month processing everything.
 *
 * Candidates come from gdrive_file_index (already synced, free) minus already-registered
 * conversations. Slots are filled risk-first, then topped up with pure random picks
 * (SAMPLING_RANDOM_SHARE) so employees can't predict what gets audited:
 *
 *   return_risk      — call party is a customer with a Cancelled/Returned order in the last
 *                      14 days (ยอดตีกลับ — over-promising on calls is a suspected cause)
 *   flagged_employee — call party phone belongs to an ERP user who already has a CONFIRMED
 *                      fraud_check (once caught, everything they say gets audited for a while)
 *   long_call        — duration >= 10 min (unusual for telesale; where big promises happen)
 *   random           — everything else, shuffled
 *
 * This class only SELECTS. Registering + running the pipeline (the part that costs money) is
 * the cron's job, guarded by the OpenRouter usage_daily budget check per call.
 */
class SmartSamplingService
{
    private const RETURN_RISK_LOOKBACK_DAYS = 14;
    private const CANDIDATE_LOOKBACK_DAYS = 7;
    private const LONG_CALL_SECONDS = 600;

    /**
     * @return array<array<string,mixed>> gdrive_file_index rows + ['sample_reason' => string],
     *                                    at most $maxCalls, risk picks first
     */
    public static function pickCandidates(PDO $pdo, PDO $erp, int $maxCalls): array
    {
        $candidates = fetch_all($pdo, '
            SELECT g.*
            FROM gdrive_file_index g
            LEFT JOIN conversations c ON c.source = \'gdrive\' AND c.audio_ref = g.gdrive_file_id
            WHERE c.id IS NULL
              AND g.duration_seconds >= ?
              AND g.call_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND g.company_id IS NOT NULL
        ', [SAMPLING_MIN_DURATION_SECONDS, self::CANDIDATE_LOOKBACK_DAYS]);

        if (empty($candidates)) {
            return [];
        }

        $returnRiskPhones = self::returnRiskPhones($erp);
        $flaggedEmployeePhones = self::flaggedEmployeePhones($pdo, $erp);

        $buckets = ['return_risk' => [], 'flagged_employee' => [], 'long_call' => [], 'random' => []];
        foreach ($candidates as $cand) {
            $phones = self::phoneForms($cand['caller_phone']) + self::phoneForms($cand['receiver_phone']);
            if (array_intersect_key($phones, $returnRiskPhones)) {
                $buckets['return_risk'][] = $cand;
            } elseif (array_intersect_key($phones, $flaggedEmployeePhones)) {
                $buckets['flagged_employee'][] = $cand;
            } elseif ((float) $cand['duration_seconds'] >= self::LONG_CALL_SECONDS) {
                $buckets['long_call'][] = $cand;
            } else {
                $buckets['random'][] = $cand;
            }
        }

        // Random slots are reserved; risk buckets share the rest in priority order. Unused risk
        // slots spill over to random.
        $randomSlots = max(1, (int) round($maxCalls * SAMPLING_RANDOM_SHARE));
        $riskSlots = $maxCalls - $randomSlots;

        $picked = [];
        foreach (['return_risk', 'flagged_employee', 'long_call'] as $reason) {
            shuffle($buckets[$reason]);
            foreach ($buckets[$reason] as $cand) {
                if (count($picked) >= $riskSlots) {
                    break 2;
                }
                $cand['sample_reason'] = $reason;
                $picked[] = $cand;
            }
        }

        shuffle($buckets['random']);
        $remaining = $maxCalls - count($picked);
        foreach (array_slice($buckets['random'], 0, max(0, $remaining)) as $cand) {
            $cand['sample_reason'] = 'random';
            $picked[] = $cand;
        }

        return $picked;
    }

    /**
     * Registers one picked gdrive_file_index row as a pending conversation (mirrors
     * register_one_conversation_row in ConversationController — same enrichment, same shape).
     * @return int conversation id (existing id if already registered by someone meanwhile)
     */
    public static function registerCandidate(PDO $pdo, PDO $erp, array $cand): int
    {
        $existing = fetch_one($pdo, 'SELECT id FROM conversations WHERE source = \'gdrive\' AND audio_ref = ?', [$cand['gdrive_file_id']]);
        if ($existing) {
            return (int) $existing['id'];
        }

        $customer = ErpLookupService::findCustomerByPhone($erp, $cand['caller_phone'])
            ?: ErpLookupService::findCustomerByPhone($erp, $cand['receiver_phone']);
        $employee = ErpLookupService::findEmployeeByPhone($erp, $cand['caller_phone'])
            ?: ErpLookupService::findEmployeeByPhone($erp, $cand['receiver_phone']);

        $pdo->prepare('
            INSERT INTO conversations
                (company_id, source, audio_ref, call_code, call_date, call_time, caller_phone, receiver_phone,
                 direction, size_bytes, duration_seconds, erp_customer_id, erp_customer_name, erp_employee_id, erp_employee_name, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'pending\')
        ')->execute([
            (int) $cand['company_id'],
            'gdrive',
            $cand['gdrive_file_id'],
            $cand['call_code'],
            $cand['call_date'],
            $cand['call_time'],
            $cand['caller_phone'],
            $cand['receiver_phone'],
            $cand['direction'],
            $cand['size_bytes'],
            $cand['duration_seconds'],
            $customer ? $customer['id'] : null,
            $customer ? $customer['name'] : null,
            $employee ? $employee['id'] : null,
            $employee ? $employee['name'] : null,
        ]);
        $conversationId = (int) $pdo->lastInsertId();

        audit_log($pdo, (int) $cand['company_id'], null, 'smart_sample', 'conversation', $conversationId, [
            'reason' => $cand['sample_reason'],
            'audio_ref' => $cand['gdrive_file_id'],
        ]);

        return $conversationId;
    }

    /**
     * Phones of customers with a Cancelled/Returned order in the lookback window, as a
     * digit-keyed set (both 0x and 66x forms).
     * @return array<string,true>
     */
    private static function returnRiskPhones(PDO $erp): array
    {
        $rows = fetch_all($erp, "
            SELECT DISTINCT c.phone, c.recipient_phone, c.backup_phone
            FROM orders o
            JOIN customers c ON c.customer_id = o.customer_id
            WHERE o.order_status IN ('Cancelled', 'Returned')
              AND o.order_date >= DATE_SUB(CURDATE(), INTERVAL " . self::RETURN_RISK_LOOKBACK_DAYS . " DAY)
        ", []);
        $set = [];
        foreach ($rows as $r) {
            foreach ([$r['phone'], $r['recipient_phone'], $r['backup_phone']] as $p) {
                $set += self::phoneForms($p);
            }
        }
        return $set;
    }

    /**
     * Phones (from ERP users) of employees with at least one CONFIRMED fraud check.
     * @return array<string,true>
     */
    private static function flaggedEmployeePhones(PDO $pdo, PDO $erp): array
    {
        $ids = fetch_all($pdo, "
            SELECT DISTINCT c.erp_employee_id
            FROM fraud_checks f
            JOIN conversations c ON c.id = f.conversation_id
            WHERE f.review_status = 'confirmed' AND c.erp_employee_id IS NOT NULL
        ", []);
        if (empty($ids)) {
            return [];
        }
        $idList = implode(',', array_map(function ($r) { return (int) $r['erp_employee_id']; }, $ids));
        $rows = fetch_all($erp, "SELECT phone FROM users WHERE id IN ({$idList})", []);
        $set = [];
        foreach ($rows as $r) {
            $set += self::phoneForms($r['phone']);
        }
        return $set;
    }

    /**
     * Digit-only variants of a phone (local 0x and stripped-E.164 66x) as set keys.
     * @return array<string,true>
     */
    private static function phoneForms(?string $phone): array
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($digits) < 9) {
            return [];
        }
        $forms = [$digits => true];
        if (strpos($digits, '66') === 0 && strlen($digits) >= 10) {
            $forms['0' . substr($digits, 2)] = true;
        } elseif (strpos($digits, '0') === 0) {
            $forms['66' . substr($digits, 1)] = true;
        }
        return $forms;
    }
}
