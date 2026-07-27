<?php

require_once __DIR__ . '/ErpLookupService.php';

/**
 * Returned-order investigation (ตีกลับ): starts from primacom_mini_erp orders whose status says
 * the sale came back (Returned / Cancelled / BadDebt) and gathers every recording of the
 * customer's numbers across the life of the case — from the sales calls BEFORE the order was
 * keyed to the follow-up calls AFTER the parcel bounced — so a reviewer can hear whether this
 * was a real customer who refused the parcel, or an order keyed with no real sale behind it
 * (ส่งเล่นๆ เพื่อปั้นยอด).
 *
 * The case window per order is [order_date - PRE_ORDER_DAYS, closed_at + POST_CLOSE_DAYS]:
 * a phone sale is keyed minutes-to-days after the call that closed it, and the "why didn't you
 * accept it" follow-up happens shortly after the return lands.
 *
 * closed_at (วันจบเคส) comes from order_audit_log (field_name='order_status'), NOT
 * order_status_logs — the latter is dead (7 rows total, all Dec 2025), while order_audit_log
 * covered 413/413 Returned orders in the last 3 months when checked. Verified lag from
 * order_date to the Returned flip is typically 9-35 days, so the audit row is the only usable
 * end-of-case marker; when it is missing (some Cancelled orders die at creation without a
 * status flip) the case is treated as closing at delivery_date, falling back to order_date.
 *
 * Recordings join by phone only (no shared id exists — see ErpLookupService header): every
 * number on the customer record plus the order's own recipient_phone, matched against
 * gdrive_file_index which stores E.164 ('+66XXXXXXXXX').
 */
class ReturnedOrderService
{
    /** Days of calls to include BEFORE the order was keyed — the sales call(s) that produced it. */
    const PRE_ORDER_DAYS = 7;
    /** Days of calls to include AFTER the case closed — the follow-up / รับคืน conversation. */
    const POST_CLOSE_DAYS = 7;
    /** Hard cap per list request; the UI narrows by date when it hits this. */
    const MAX_ORDERS = 400;
    /** Statuses a caller may ask for; anything else is rejected at the controller. */
    const ALLOWED_STATUSES = ['Returned', 'Cancelled', 'BadDebt'];

    /**
     * @param string[] $statuses non-empty subset of ALLOWED_STATUSES
     * @return array{orders: array<array<string,mixed>>, totals: array<string,mixed>, truncated: bool}
     */
    public static function listForCompany(PDO $pdo, PDO $erp, int $companyId, string $from, string $to, array $statuses): array
    {
        $ph = implode(',', array_fill(0, count($statuses), '?'));
        $orders = fetch_all($erp, "
            SELECT id, customer_id, company_id, creator_id, upsell_user_id, order_date,
                   delivery_date, customer_received_date, total_amount, payment_method,
                   payment_status, order_status, sales_channel, recipient_phone
            FROM orders
            WHERE company_id = ? AND order_status IN ({$ph})
              AND order_date >= ? AND order_date < DATE_ADD(?, INTERVAL 1 DAY)
            ORDER BY order_date DESC
            LIMIT " . (self::MAX_ORDERS + 1),
            array_merge([$companyId], $statuses, [$from, $to]));

        $truncated = count($orders) > self::MAX_ORDERS;
        if ($truncated) {
            $orders = array_slice($orders, 0, self::MAX_ORDERS);
        }
        if (empty($orders)) {
            return ['orders' => [], 'totals' => ['orders' => 0, 'amount' => 0.0, 'no_call_before' => 0, 'no_call_at_all' => 0], 'truncated' => false];
        }

        $orderIds = array_column($orders, 'id');
        $closedAt = self::closedAtByOrder($erp, $orderIds);

        // Customer names + every phone on the record.
        $custIds = array_values(array_unique(array_filter(array_map('intval', array_column($orders, 'customer_id')))));
        $customers = [];
        if (!empty($custIds)) {
            $cph = implode(',', array_fill(0, count($custIds), '?'));
            foreach (fetch_all($erp, "SELECT customer_id, first_name, last_name, phone, recipient_phone, backup_phone FROM customers WHERE customer_id IN ({$cph})", $custIds) as $c) {
                $customers[(int) $c['customer_id']] = $c;
            }
        }

        // Creator / upsell user names.
        $userIds = [];
        foreach ($orders as $o) {
            foreach (['creator_id', 'upsell_user_id'] as $k) {
                if (!empty($o[$k])) {
                    $userIds[(int) $o[$k]] = true;
                }
            }
        }
        $users = self::userNames($erp, array_keys($userIds));

        // Per-order phone set and case window, then one batched recording scan for all of them.
        $phonesByOrder = [];
        $windowByOrder = [];
        foreach ($orders as $o) {
            $cust = $customers[(int) $o['customer_id']] ?? null;
            $phones = self::phoneSet($cust, $o['recipient_phone']);
            $phonesByOrder[$o['id']] = $phones;
            $windowByOrder[$o['id']] = self::caseWindow($o, $closedAt[$o['id']] ?? null);
        }
        $recs = self::recordingsForPhones($pdo, $companyId, $phonesByOrder, $windowByOrder);

        $out = [];
        $totals = ['orders' => 0, 'amount' => 0.0, 'no_call_before' => 0, 'no_call_at_all' => 0];
        foreach ($orders as $o) {
            $cust = $customers[(int) $o['customer_id']] ?? null;
            $w = $windowByOrder[$o['id']];
            $counts = ['total' => 0, 'before' => 0, 'after' => 0, 'seconds' => 0];
            foreach ($recs[$o['id']] ?? [] as $r) {
                $counts['total']++;
                $counts['seconds'] += (int) $r['duration_seconds'];
                $ts = $r['call_date'] . ' ' . ($r['call_time'] ?: '00:00:00');
                if ($ts < $o['order_date']) {
                    $counts['before']++;
                } elseif (!empty($closedAt[$o['id']]) && $ts > $closedAt[$o['id']]) {
                    $counts['after']++;
                }
            }

            $creator = $users[(int) $o['creator_id']] ?? null;
            $upsell = !empty($o['upsell_user_id']) ? ($users[(int) $o['upsell_user_id']] ?? null) : null;
            $noCallBefore = $counts['before'] === 0;
            $row = [
                'id' => $o['id'],
                'order_date' => $o['order_date'],
                'order_status' => $o['order_status'],
                'payment_method' => $o['payment_method'],
                'payment_status' => $o['payment_status'],
                'total_amount' => (float) $o['total_amount'],
                'sales_channel' => $o['sales_channel'],
                'closed_at' => $closedAt[$o['id']] ?? null,
                'customer_id' => (int) $o['customer_id'],
                'customer_name' => $cust ? trim($cust['first_name'] . ' ' . $cust['last_name']) : null,
                'customer_phone' => $cust ? ($cust['phone'] ?: $o['recipient_phone']) : $o['recipient_phone'],
                'creator_name' => $creator,
                'upsell_name' => $upsell,
                'calls_total' => $counts['total'],
                'calls_before_order' => $counts['before'],
                'calls_after_close' => $counts['after'],
                'call_seconds' => $counts['seconds'],
                'window_from' => $w[0],
                'window_to' => $w[1],
                // Order keyed with no recorded sales call in the week before it: for a phone-sale
                // channel that is the ปั้นยอด signature — but judge alongside sales_channel, an
                // online order legitimately has no call.
                'no_call_before_order' => $noCallBefore,
            ];
            $out[] = $row;
            $totals['orders']++;
            $totals['amount'] += (float) $o['total_amount'];
            if ($noCallBefore) {
                $totals['no_call_before']++;
            }
            if ($counts['total'] === 0) {
                $totals['no_call_at_all']++;
            }
        }
        $totals['amount'] = round($totals['amount'], 2);

        return ['orders' => $out, 'totals' => $totals, 'truncated' => $truncated];
    }

    /**
     * Everything the review modal needs for one case: order + items, the status trail, the
     * telesale's own CRM entries, and every recording of the customer's numbers in the window.
     * @return array<string,mixed>|null null when the order does not exist
     */
    public static function caseDetail(PDO $pdo, PDO $erp, string $orderId): ?array
    {
        $order = fetch_one($erp, 'SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$order) {
            return null;
        }
        $companyId = (int) $order['company_id'];

        $items = fetch_all($erp, 'SELECT product_name, quantity, price_per_unit, net_total FROM order_items WHERE parent_order_id = ?', [$orderId]);

        // Full order_status trail (not just the final flip) — shows ship-out and bounce dates.
        $history = fetch_all($erp, "
            SELECT field_name, old_value, new_value, api_source, changed_by, created_at
            FROM order_audit_log
            WHERE order_id = ? AND field_name = 'order_status'
            ORDER BY created_at
        ", [$orderId]);
        $userIds = array_filter(array_map('intval', array_column($history, 'changed_by')));
        foreach ([$order['creator_id'], $order['upsell_user_id']] as $uid) {
            if (!empty($uid)) {
                $userIds[] = (int) $uid;
            }
        }
        $users = self::userNames($erp, array_values(array_unique($userIds)));
        foreach ($history as &$h) {
            $h['changed_by_name'] = $users[(int) $h['changed_by']] ?? null;
        }
        unset($h);

        $closedAt = null;
        foreach ($history as $h) {
            if ($h['new_value'] === $order['order_status']) {
                $closedAt = $h['created_at']; // latest flip into the current (returned) status wins
            }
        }

        $cust = null;
        if (!empty($order['customer_id'])) {
            $cust = fetch_one($erp, 'SELECT customer_id, first_name, last_name, phone, recipient_phone, backup_phone FROM customers WHERE customer_id = ?', [(int) $order['customer_id']]);
        }
        $phones = self::phoneSet($cust, $order['recipient_phone']);

        // Duplicate customer records sharing these numbers within the company — call_history may
        // have been logged against any of them (see ErpLookupService::findCustomersByPhones).
        $allCustIds = !empty($order['customer_id']) ? [(int) $order['customer_id']] : [];
        if (!empty($phones)) {
            foreach (ErpLookupService::findCustomersByPhones($erp, $phones, $companyId) as $match) {
                $allCustIds = array_merge($allCustIds, $match['all_ids']);
            }
            $allCustIds = array_values(array_unique($allCustIds));
        }

        list($winFrom, $winTo) = self::caseWindow($order, $closedAt);

        $crmLogs = [];
        if (!empty($allCustIds)) {
            $cph = implode(',', array_fill(0, count($allCustIds), '?'));
            $crmLogs = fetch_all($erp, "
                SELECT date, caller, status, result, notes
                FROM call_history
                WHERE customer_id IN ({$cph})
                  AND date >= ? AND date < DATE_ADD(?, INTERVAL 1 DAY)
                ORDER BY date
            ", array_merge($allCustIds, [$winFrom, $winTo]));
        }

        $recordings = self::caseRecordings($pdo, $erp, $companyId, $phones, $winFrom, $winTo, $order['order_date'], $closedAt);

        return [
            'order' => [
                'id' => $order['id'],
                'company_id' => $companyId,
                'order_date' => $order['order_date'],
                'delivery_date' => $order['delivery_date'],
                'customer_received_date' => $order['customer_received_date'],
                'total_amount' => (float) $order['total_amount'],
                'payment_method' => $order['payment_method'],
                'payment_status' => $order['payment_status'],
                'order_status' => $order['order_status'],
                'sales_channel' => $order['sales_channel'],
                'shipping_provider' => $order['shipping_provider'],
                'notes' => $order['notes'],
                'note_system' => $order['note_system'],
                'address' => trim(implode(' ', array_filter([$order['street'], $order['subdistrict'], $order['district'], $order['province'], $order['postal_code']]))),
                'recipient_name' => trim(($order['recipient_first_name'] ?? '') . ' ' . ($order['recipient_last_name'] ?? '')),
                'recipient_phone' => $order['recipient_phone'],
                'creator_name' => $users[(int) $order['creator_id']] ?? null,
                'upsell_name' => !empty($order['upsell_user_id']) ? ($users[(int) $order['upsell_user_id']] ?? null) : null,
                'closed_at' => $closedAt,
            ],
            'items' => $items,
            'customer' => $cust ? [
                'id' => (int) $cust['customer_id'],
                'name' => trim($cust['first_name'] . ' ' . $cust['last_name']),
                'phones' => $phones,
                'all_ids' => $allCustIds,
            ] : null,
            'status_history' => $history,
            'crm_logs' => $crmLogs,
            'recordings' => $recordings,
            'window' => ['from' => $winFrom, 'to' => $winTo],
        ];
    }

    // ---- internals ----------------------------------------------------------------------

    /**
     * Latest order_status flip INTO each order's current status = when the case closed.
     * Grouped by (order_id, new_value) so a re-shipped-then-returned-again order still resolves
     * to its final bounce, not the first one.
     * @param string[] $orderIds
     * @return array<string,string> order_id => datetime
     */
    private static function closedAtByOrder(PDO $erp, array $orderIds): array
    {
        $result = [];
        foreach (array_chunk($orderIds, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = fetch_all($erp, "
                SELECT l.order_id, l.new_value, MAX(l.created_at) AS changed_at
                FROM order_audit_log l
                JOIN orders o ON o.id = l.order_id AND o.order_status = l.new_value
                WHERE l.order_id IN ({$ph}) AND l.field_name = 'order_status'
                GROUP BY l.order_id, l.new_value
            ", $chunk);
            foreach ($rows as $r) {
                $result[$r['order_id']] = $r['changed_at'];
            }
        }
        return $result;
    }

    /**
     * @return array{0:string,1:string} [from_date, to_date] (Y-m-d, inclusive)
     */
    private static function caseWindow(array $order, ?string $closedAt): array
    {
        $orderTs = strtotime($order['order_date']);
        $endRaw = $closedAt ?: ($order['delivery_date'] ?: $order['order_date']);
        $endTs = strtotime($endRaw) ?: $orderTs;
        return [
            date('Y-m-d', $orderTs - self::PRE_ORDER_DAYS * 86400),
            date('Y-m-d', $endTs + self::POST_CLOSE_DAYS * 86400),
        ];
    }

    /**
     * Every distinct phone on the customer record plus the order's shipping contact.
     * @return string[] raw strings as stored in the ERP (local format)
     */
    private static function phoneSet(?array $cust, ?string $orderRecipientPhone): array
    {
        $set = [];
        $candidates = $cust
            ? [$cust['phone'], $cust['recipient_phone'], $cust['backup_phone'], $orderRecipientPhone]
            : [$orderRecipientPhone];
        foreach ($candidates as $p) {
            $n = self::normalize($p);
            if ($n !== null) {
                $set[$n] = true;
            }
        }
        return array_keys($set);
    }

    /**
     * One batched gdrive_file_index scan for every order's phone set, bucketed back per order.
     * gdrive_file_index stores E.164 with a leading '+', but match all three shapes anyway —
     * defensive against the same format drift ErpLookupService documents inside the ERP itself.
     * @param array<string,string[]> $phonesByOrder order_id => local-format phones
     * @param array<string,array{0:string,1:string}> $windowByOrder order_id => [from, to]
     * @return array<string,array<array<string,mixed>>> order_id => recording rows
     */
    private static function recordingsForPhones(PDO $pdo, int $companyId, array $phonesByOrder, array $windowByOrder): array
    {
        $orderIdsByPhone = [];
        $allVariants = [];
        $minFrom = null;
        $maxTo = null;
        foreach ($phonesByOrder as $orderId => $phones) {
            foreach ($phones as $p) {
                $orderIdsByPhone[$p][] = $orderId;
                foreach (self::e164Variants($p) as $v) {
                    $allVariants[$v] = $p;
                }
            }
            list($f, $t) = $windowByOrder[$orderId];
            $minFrom = $minFrom === null ? $f : min($minFrom, $f);
            $maxTo = $maxTo === null ? $t : max($maxTo, $t);
        }
        if (empty($allVariants)) {
            return [];
        }

        $result = [];
        foreach (array_chunk(array_keys($allVariants), 600) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $rows = fetch_all($pdo, "
                SELECT gdrive_file_id, call_date, call_time, caller_phone, receiver_phone,
                       direction, duration_seconds
                FROM gdrive_file_index
                WHERE company_id = ? AND call_date BETWEEN ? AND ?
                  AND (caller_phone IN ({$ph}) OR receiver_phone IN ({$ph}))
            ", array_merge([$companyId, $minFrom, $maxTo], $chunk, $chunk));

            foreach ($rows as $r) {
                foreach ([$r['caller_phone'], $r['receiver_phone']] as $leg) {
                    $local = $allVariants[$leg] ?? null;
                    if ($local === null) {
                        continue;
                    }
                    foreach ($orderIdsByPhone[$local] as $orderId) {
                        list($f, $t) = $windowByOrder[$orderId];
                        if ($r['call_date'] >= $f && $r['call_date'] <= $t) {
                            $result[$orderId][$r['gdrive_file_id']] = $r;
                        }
                    }
                }
            }
        }
        foreach ($result as $orderId => $byFile) {
            $result[$orderId] = array_values($byFile);
        }
        return $result;
    }

    /**
     * Recordings for one case, enriched for the timeline: which leg is the employee, link to any
     * processed conversation, and where in the case each call falls (before / during / after).
     * @param string[] $phones local-format customer phones
     * @return array<array<string,mixed>>
     */
    private static function caseRecordings(PDO $pdo, PDO $erp, int $companyId, array $phones, string $winFrom, string $winTo, string $orderDate, ?string $closedAt): array
    {
        if (empty($phones)) {
            return [];
        }
        $variants = [];
        foreach ($phones as $p) {
            foreach (self::e164Variants($p) as $v) {
                $variants[$v] = true;
            }
        }
        $variants = array_keys($variants);
        $ph = implode(',', array_fill(0, count($variants), '?'));
        $rows = fetch_all($pdo, "
            SELECT g.gdrive_file_id, g.call_date, g.call_time, g.caller_phone, g.receiver_phone,
                   g.direction, g.duration_seconds,
                   c.id AS conversation_id, c.status AS conversation_status
            FROM gdrive_file_index g
            LEFT JOIN conversations c ON c.source = 'gdrive' AND c.audio_ref = g.gdrive_file_id
            WHERE g.company_id = ? AND g.call_date BETWEEN ? AND ?
              AND (g.caller_phone IN ({$ph}) OR g.receiver_phone IN ({$ph}))
            ORDER BY g.call_date, g.call_time
        ", array_merge([$companyId, $winFrom, $winTo], $variants, $variants));

        $customerSide = [];
        foreach ($variants as $v) {
            $customerSide[$v] = true;
        }

        // The non-customer leg is (almost always) the employee — resolve names in one batch.
        $otherPhones = [];
        foreach ($rows as $r) {
            $other = isset($customerSide[$r['caller_phone']]) ? $r['receiver_phone'] : $r['caller_phone'];
            if ($other) {
                $otherPhones[$other] = true;
            }
        }
        $employees = ErpLookupService::findEmployeesByPhones($erp, array_keys($otherPhones));

        $out = [];
        foreach ($rows as $r) {
            $customerIsCaller = isset($customerSide[$r['caller_phone']]);
            $other = $customerIsCaller ? $r['receiver_phone'] : $r['caller_phone'];
            $emp = $employees[$other] ?? null;
            $ts = $r['call_date'] . ' ' . ($r['call_time'] ?: '00:00:00');
            if ($ts < $orderDate) {
                $phase = 'before';
            } elseif ($closedAt !== null && $ts > $closedAt) {
                $phase = 'after';
            } else {
                $phase = 'during';
            }
            $out[] = [
                'gdrive_file_id' => $r['gdrive_file_id'],
                'call_date' => $r['call_date'],
                'call_time' => $r['call_time'],
                'direction' => $r['direction'],
                'duration_seconds' => (int) $r['duration_seconds'],
                'customer_phone' => $customerIsCaller ? $r['caller_phone'] : $r['receiver_phone'],
                'employee_phone' => $other,
                'employee_name' => $emp ? $emp['name'] : null,
                'conversation_id' => $r['conversation_id'] !== null ? (int) $r['conversation_id'] : null,
                'conversation_status' => $r['conversation_status'],
                'phase' => $phase,
            ];
        }
        return $out;
    }

    /**
     * All storage shapes of one local-format number: '+66XXXXXXXXX' (what gdrive_file_index
     * actually stores), plus '66...' and '0...' as drift insurance.
     * @return string[]
     */
    private static function e164Variants(string $local): array
    {
        if ($local === '' || $local[0] !== '0') {
            return [$local];
        }
        $tail = substr($local, 1);
        return ['+66' . $tail, '66' . $tail, $local];
    }

    /** Local-format (0xxxxxxxxx) normalization — same rules as GhostNumberService. */
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

    /**
     * @param int[] $ids
     * @return array<int,string> user id => full name
     */
    private static function userNames(PDO $erp, array $ids): array
    {
        if (empty($ids)) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach (fetch_all($erp, "SELECT id, first_name, last_name FROM users WHERE id IN ({$ph})", $ids) as $u) {
            $out[(int) $u['id']] = trim($u['first_name'] . ' ' . $u['last_name']);
        }
        return $out;
    }
}
