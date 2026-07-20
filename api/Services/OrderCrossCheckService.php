<?php

/**
 * Deterministic order cross-check (fraud ideas #1 + #2):
 *   missing_order  — the AI heard the customer agree to buy, but no ERP order appeared for that
 *                    customer within the window → possible off-system sale (ขายตัดหน้าบริษัท)
 *   price_mismatch — an order exists but its total differs from the amount quoted in the call →
 *                    possible pocketing the difference / unauthorized discount
 *
 * Same division of labor as FraudCheckService: the LLM only supplies what was SAID
 * (sale_outcome, price, order_info); every verdict here is plain SQL/PHP against
 * primacom_mini_erp.orders. Runs from api/cron/fraud_order_check.php, never inline in the
 * pipeline — a legitimate order can be keyed hours or days after the call, so judging at
 * process time would flag honest employees. All findings are human-review items.
 */
class OrderCrossCheckService
{
    /** Days to wait after the call before daring a "missing order" verdict. */
    public const GRACE_DAYS = 2;
    /** An order must appear within call_date .. call_date + WINDOW_DAYS to count as "recorded". */
    public const WINDOW_DAYS = 5;
    /** Don't re-scan conversations older than this (bounds cron cost; verdicts settle). */
    public const MAX_AGE_DAYS = 30;

    private const PRICE_TOLERANCE_ABS = 50.0;  // baht
    private const PRICE_TOLERANCE_PCT = 0.05;

    /**
     * @param array $conv     conversations row (id, company_id, call_date, erp_customer_id, ...)
     * @param array $entities extracted_entities row (price, order_info, sale_outcome, product)
     * @return array{checked:bool,flagged:int} checked=false → no purchase signal, nothing written
     */
    public static function runForConversation(PDO $pdo, PDO $erp, array $conv, array $entities): array
    {
        $conversationId = (int) $conv['id'];
        $companyId = (int) $conv['company_id'];
        $customerId = (int) $conv['erp_customer_id'];
        $callDate = (string) $conv['call_date'];

        $orderInfo = json_decode($entities['order_info'] ?? 'null', true) ?: [];
        $spokenTotal = self::firstNumeric([$orderInfo['total'] ?? null, $entities['price'] ?? null]);
        $saleOutcome = $entities['sale_outcome'] ?? null;

        // Purchase signal: explicit closed_won from the new prompt, or (legacy rows analyzed
        // before sale_outcome existed) a concrete amount discussed in the call.
        $strongSignal = $saleOutcome === 'closed_won';
        $weakSignal = $spokenTotal !== null;
        if (!$strongSignal && !$weakSignal) {
            return ['checked' => false, 'flagged' => 0];
        }
        if ($saleOutcome !== null && !$strongSignal) {
            // The model explicitly said this was NOT a closed sale (follow_up/declined/
            // not_sales_call) — a missing order is then expected, not suspicious.
            return ['checked' => false, 'flagged' => 0];
        }

        // Idempotent re-run per conversation for these check types only (payment_channel rows
        // belong to the pipeline and must survive).
        $pdo->prepare("DELETE FROM fraud_checks WHERE conversation_id = ? AND check_type IN ('missing_order','price_mismatch')")
            ->execute([$conversationId]);

        $orders = self::findOrders($erp, $companyId, $customerId, $callDate);
        $flagged = 0;

        if (empty($orders)) {
            self::insert($pdo, $conversationId, $companyId, 'missing_order', 'flagged',
                $strongSignal ? 'high' : 'medium',
                $spokenTotal !== null ? number_format($spokenTotal, 2, '.', '') : null,
                ($strongSignal
                    ? 'AI สรุปว่าลูกค้าตกลงซื้อในสายนี้'
                    : 'มีการคุยยอดสั่งซื้อ/ราคาในสายนี้' . ($spokenTotal !== null ? ' (' . number_format($spokenTotal, 2) . ' บาท)' : ''))
                . ' แต่ไม่พบ order ของลูกค้ารายนี้ในระบบ ERP ภายใน ' . self::WINDOW_DAYS
                . ' วันหลังการโทร — อาจเป็นการขายนอกระบบ ควรตรวจสอบกับพนักงาน');
            $flagged++;
            return ['checked' => true, 'flagged' => $flagged];
        }

        // Order exists → record the match (audit trail), then compare price if we heard one.
        $best = self::closestByTotal($orders, $spokenTotal);
        self::insert($pdo, $conversationId, $companyId, 'missing_order', 'clear', 'low',
            (string) $best['id'],
            'พบ order ในระบบ: #' . $best['id'] . ' วันที่ ' . $best['order_date']
            . ' ยอด ' . number_format((float) $best['total_amount'], 2) . ' บาท (' . $best['order_status'] . ')');

        if ($spokenTotal !== null) {
            $orderTotal = (float) $best['total_amount'];
            $diff = abs($orderTotal - $spokenTotal);
            $tolerance = max(self::PRICE_TOLERANCE_ABS, $spokenTotal * self::PRICE_TOLERANCE_PCT);

            if ($diff <= $tolerance) {
                self::insert($pdo, $conversationId, $companyId, 'price_mismatch', 'clear', 'low',
                    number_format($spokenTotal, 2, '.', ''),
                    'ยอดที่พูดในสาย (' . number_format($spokenTotal, 2) . ' บาท) ตรงกับ order #' . $best['id']
                    . ' (' . number_format($orderTotal, 2) . ' บาท)');
            } else {
                // Customer quoted MORE than what was keyed in = classic pocket-the-difference
                // pattern (esp. COD) → high. Less than keyed = over-discount/misquote → medium.
                self::insert($pdo, $conversationId, $companyId, 'price_mismatch', 'flagged',
                    $spokenTotal > $orderTotal ? 'high' : 'medium',
                    number_format($spokenTotal, 2, '.', ''),
                    'ยอดที่พูดในสาย ' . number_format($spokenTotal, 2) . ' บาท ไม่ตรงกับ order #' . $best['id']
                    . ' ในระบบ ' . number_format($orderTotal, 2) . ' บาท (ต่างกัน ' . number_format($diff, 2) . ' บาท)'
                    . ($spokenTotal > $orderTotal
                        ? ' — ลูกค้าจ่ายมากกว่าที่บันทึก อาจมีการเก็บส่วนต่าง ควรตรวจสอบโดยด่วน'
                        : ' — บันทึกต่ำกว่าที่ตกลง อาจเป็นส่วนลดเกินอำนาจหรือแจ้งราคาผิด'));
                $flagged++;
            }
        }

        return ['checked' => true, 'flagged' => $flagged];
    }

    /**
     * Non-cancelled orders for this customer within the window after the call.
     * @return array<array<string,mixed>>
     */
    private static function findOrders(PDO $erp, int $companyId, int $customerId, string $callDate): array
    {
        $stmt = $erp->prepare('
            SELECT id, order_date, total_amount, order_status
            FROM orders
            WHERE customer_id = ? AND company_id = ?
              AND order_date >= ? AND order_date < DATE_ADD(?, INTERVAL ? DAY)
              AND order_status != \'Cancelled\'
            ORDER BY order_date
        ');
        $stmt->execute([$customerId, $companyId, $callDate . ' 00:00:00', $callDate . ' 00:00:00', self::WINDOW_DAYS + 1]);
        return $stmt->fetchAll();
    }

    /** Pick the order whose total is closest to the spoken amount (first order when none heard). */
    private static function closestByTotal(array $orders, ?float $spokenTotal): array
    {
        if ($spokenTotal === null || count($orders) === 1) {
            return $orders[0];
        }
        usort($orders, function (array $a, array $b) use ($spokenTotal) {
            return abs((float) $a['total_amount'] - $spokenTotal) <=> abs((float) $b['total_amount'] - $spokenTotal);
        });
        return $orders[0];
    }

    private static function firstNumeric(array $candidates): ?float
    {
        foreach ($candidates as $c) {
            if ($c !== null && $c !== '' && is_numeric($c) && (float) $c > 0) {
                return (float) $c;
            }
        }
        return null;
    }

    private static function insert(PDO $pdo, int $conversationId, int $companyId, string $checkType, string $status, string $risk, ?string $detectedValue, string $explanation): void
    {
        $pdo->prepare('
            INSERT INTO fraud_checks
              (conversation_id, company_id, check_type, source, status, risk_level, channel_type,
               detected_value, bank_name, spoken_by, purpose, evidence, explanation, matched_bank_account_id)
            VALUES (?,?,?,\'erp\',?,?,NULL,?,NULL,NULL,NULL,NULL,?,NULL)
        ')->execute([$conversationId, $companyId, $checkType, $status, $risk, $detectedValue, $explanation]);
    }
}
