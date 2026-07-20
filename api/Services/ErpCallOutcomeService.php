<?php

require_once __DIR__ . '/ErpLookupService.php';

/**
 * Enriches recordings with what the ERP says happened: the telesale's own CRM entry
 * (primacom_mini_erp.call_history — status/result, where result='ขายได้' means the call closed a
 * sale) and any order the customer placed around that call, with its status so returns
 * (Cancelled/Returned = ตีกลับ) are visible next to the recording.
 *
 * There is no shared id between a Drive recording and either table, so the join is:
 *   recording phone -> customers.customer_id, then nearest call_history row by time.
 * Verified against production: the CRM entry lands a median of +3-4 minutes after the recording
 * (the telesale logging the call right after hanging up), so a 30-minute window catches it while
 * staying tight enough not to grab the next call to the same customer. Roughly 40-70% of
 * recordings match a CRM row — a miss is normal (the telesale simply never logged it), and is
 * itself worth seeing, so callers must treat null as data, not error.
 *
 * NOTE: this depends on recording timestamps being Asia/Bangkok. OneCall writes UTC filenames;
 * migrations/008_fix_onecall_timezone.sql and GdriveIndexer::parseFilename normalize that at the
 * point of entry. Undo either and every match here silently degrades.
 */
class ErpCallOutcomeService
{
    /**
     * How far outside the call itself a CRM entry may sit and still be considered the same call.
     * The window spans [start - SLACK, start + duration + SLACK]: telesales log a call after
     * hanging up, so for a 2-hour call the entry lands 2 hours after the recording's start time.
     */
    const MATCH_SLACK_MINUTES = 30;
    /** Orders placed from the call date up to this many days later count as "from this call". */
    const ORDER_WINDOW_DAYS = 5;

    /** call_history.result values that mean the call closed a sale. */
    const RESULT_SOLD = 'ขายได้';
    /** primacom_mini_erp.orders statuses that mean the order came back (ตีกลับ). */
    const RETURNED_STATUSES = ['Cancelled', 'Returned', 'BadDebt'];

    /**
     * @param array<array<string,mixed>> $recordings rows with keys:
     *        gdrive_file_id (or any unique 'key'), call_date, call_time, caller_phone, receiver_phone
     * @return array<string,array{customer:?array,call_log:?array,orders:array,sold:bool,returned:bool}>
     *         keyed by each recording's gdrive_file_id
     */
    public static function forRecordings(PDO $erp, array $recordings): array
    {
        if (empty($recordings)) {
            return [];
        }

        // 1. Resolve every phone on both legs in one round-trip.
        $phones = [];
        foreach ($recordings as $r) {
            foreach ([$r['caller_phone'] ?? null, $r['receiver_phone'] ?? null] as $p) {
                if ($p) {
                    $phones[$p] = true;
                }
            }
        }
        $customers = ErpLookupService::findCustomersByPhones($erp, array_keys($phones));

        $custByRecording = [];
        $customerIds = [];
        foreach ($recordings as $r) {
            // The customer may be on either leg depending on call direction.
            $cust = $customers[$r['receiver_phone'] ?? ''] ?? $customers[$r['caller_phone'] ?? ''] ?? null;
            $custByRecording[$r['gdrive_file_id']] = $cust;
            if ($cust) {
                $customerIds[$cust['id']] = true;
            }
        }

        $result = [];
        foreach ($recordings as $r) {
            $result[$r['gdrive_file_id']] = [
                'customer' => $custByRecording[$r['gdrive_file_id']],
                'call_log' => null,
                'orders' => [],
                'sold' => false,
                'returned' => false,
            ];
        }
        if (empty($customerIds)) {
            return $result;
        }

        $ids = array_keys($customerIds);
        $dates = array_filter(array_column($recordings, 'call_date'));
        if (empty($dates)) {
            return $result;
        }
        $minDate = min($dates);
        $maxDate = max($dates);

        // 2. All CRM entries for those customers across the covered date span, then pick the
        //    nearest one per recording in PHP — one query instead of one per row.
        $logs = self::fetchAllByCustomer($erp, "
            SELECT customer_id, caller_id, date, caller, status, result, notes
            FROM call_history
            WHERE customer_id IN (%s)
              AND date BETWEEN DATE_SUB(?, INTERVAL 1 DAY) AND DATE_ADD(?, INTERVAL 1 DAY)
        ", $ids, [$minDate, $maxDate]);

        // 3. Same for orders, over the call date plus the order window.
        $orders = self::fetchAllByCustomer($erp, "
            SELECT customer_id, id, order_date, total_amount, order_status, payment_status
            FROM orders
            WHERE customer_id IN (%s)
              AND order_date >= ? AND order_date < DATE_ADD(?, INTERVAL " . (self::ORDER_WINDOW_DAYS + 1) . " DAY)
            ORDER BY order_date
        ", $ids, [$minDate, $maxDate]);

        $slackSec = self::MATCH_SLACK_MINUTES * 60;
        foreach ($recordings as $r) {
            $key = $r['gdrive_file_id'];
            $cust = $custByRecording[$key];
            if (!$cust || empty($r['call_date'])) {
                continue;
            }
            $callTs = strtotime($r['call_date'] . ' ' . ($r['call_time'] ?: '00:00:00'));
            $callEndTs = $callTs + (int) ($r['duration_seconds'] ?? 0);

            // Nearest CRM entry inside [start - slack, end + slack], measured from whichever end
            // of the call it is closest to — an entry made while the call is still in progress is
            // a perfect match (distance 0), not a distant one.
            $best = null;
            $bestDiff = null;
            foreach ($logs[$cust['id']] ?? [] as $log) {
                $ts = strtotime($log['date']);
                $diff = $ts < $callTs ? $callTs - $ts : ($ts > $callEndTs ? $ts - $callEndTs : 0);
                if ($diff <= $slackSec && ($bestDiff === null || $diff < $bestDiff)) {
                    $bestDiff = $diff;
                    $best = $log;
                }
            }
            if ($best) {
                $result[$key]['call_log'] = [
                    'status' => $best['status'],
                    'result' => $best['result'],
                    'notes' => $best['notes'],
                    'logged_at' => $best['date'],
                    'caller_id' => (int) $best['caller_id'],
                    'caller_name' => $best['caller'],
                    'diff_minutes' => (int) round($bestDiff / 60),
                ];
                $result[$key]['sold'] = trim((string) $best['result']) === self::RESULT_SOLD;
            }

            // Orders placed from the call date onwards, inside the window.
            $from = strtotime($r['call_date'] . ' 00:00:00');
            $to = $from + (self::ORDER_WINDOW_DAYS + 1) * 86400;
            foreach ($orders[$cust['id']] ?? [] as $o) {
                $ots = strtotime($o['order_date']);
                if ($ots < $from || $ots >= $to) {
                    continue;
                }
                $returned = in_array($o['order_status'], self::RETURNED_STATUSES, true);
                $result[$key]['orders'][] = [
                    'id' => $o['id'],
                    'order_date' => $o['order_date'],
                    'total_amount' => (float) $o['total_amount'],
                    'order_status' => $o['order_status'],
                    'payment_status' => $o['payment_status'],
                    'is_returned' => $returned,
                    // How long after the call the order was keyed. 0 = same day (strong link);
                    // anything further is only "this customer ordered soon after we called", which
                    // the reader has to weigh — so it is always shown, never implied.
                    'days_after' => (int) floor(($ots - $from) / 86400),
                ];
                if ($returned) {
                    $result[$key]['returned'] = true;
                }
            }
        }

        return $result;
    }

    /**
     * Runs one IN(...) query and groups the rows by customer_id.
     * @return array<int,array<array<string,mixed>>>
     */
    private static function fetchAllByCustomer(PDO $erp, string $sqlTemplate, array $ids, array $extraParams): array
    {
        $chunks = array_chunk($ids, 500); // keep the IN list a sane size for very large pages
        $grouped = [];
        foreach ($chunks as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $erp->prepare(sprintf($sqlTemplate, $ph));
            $stmt->execute(array_merge($chunk, $extraParams));
            foreach ($stmt->fetchAll() as $row) {
                $grouped[(int) $row['customer_id']][] = $row;
            }
        }
        return $grouped;
    }
}
