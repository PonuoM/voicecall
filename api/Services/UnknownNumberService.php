<?php

require_once __DIR__ . '/ErpLookupService.php';

/**
 * Finds phone numbers that repeatedly place outbound calls under a company's call log while
 * matching nothing in the ERP — not an employee (in ANY company; staff sometimes share numbers
 * across the group) and not a customer (of this company).
 *
 * The direction matters. This only looks at the CALLER side on purpose: an unregistered number that
 * keeps *originating* calls to many different other unregistered numbers is doing something —
 * cold outreach, or, in the verified case that motivated this, an entirely different business
 * (rubber flooring) whose recordings got filed into an agriculture company's folder because they
 * share whatever phone infrastructure produced them. An unregistered number that only ever
 * *receives* calls does not have that signature and is left alone here.
 *
 * This never auto-skips. GhostNumberService already established that one leg of a call is normally
 * a known employee 100% of the time in a week-long sample — a repeated violation of that invariant
 * is worth a human's five seconds, not a silent drop, because the same signature also describes a
 * brand-new employee whose phone has not made it into the ERP yet. scan() only proposes; a person
 * decides through UnknownNumberController, and the decision sticks (skip/allow persists per number
 * per company, keyed by phone_number, not re-asked on every scan).
 */
class UnknownNumberService
{
    /** Below this the pattern is indistinguishable from one cold-call to a fresh prospect. */
    const MIN_CALLS = 2;
    /** How far back to look each scan — recordings arrive daily, so this only needs to cover drift. */
    const LOOKBACK_DAYS = 180;
    /** Evidence rows kept per number for a reviewer to read without leaving the page. */
    const SAMPLE_SIZE = 5;

    /**
     * Scans one company's call log and upserts candidates crossing MIN_CALLS into
     * unknown_number_reviews. Existing decisions ('skip'/'allow') are never touched — only a
     * 'pending' row's stats move, and only pending rows can be newly created.
     *
     * @return int candidates found this run (including ones already decided previously)
     */
    public static function scan(PDO $pdo, PDO $erp, int $companyId): int
    {
        $knownPhones = self::knownPhoneSet($erp, $companyId);

        $rows = fetch_all($pdo, '
            SELECT caller_phone, receiver_phone, call_date, duration_seconds
            FROM gdrive_file_index
            WHERE company_id = ?
              AND call_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
              AND caller_phone IS NOT NULL AND receiver_phone IS NOT NULL
        ', [$companyId, self::LOOKBACK_DAYS]);

        $groups = [];
        foreach ($rows as $r) {
            $caller = self::normalize($r['caller_phone']);
            $receiver = self::normalize($r['receiver_phone']);
            if ($caller === null || $receiver === null || $caller === $receiver) {
                continue;
            }
            // Both sides must be unrecognised — a receiver that IS a known customer means the
            // caller is plausibly one of our own staff whose number just is not in ERP yet, which
            // is a data-hygiene gap, not this pattern.
            if (isset($knownPhones[$caller]) || isset($knownPhones[$receiver])) {
                continue;
            }

            if (!isset($groups[$caller])) {
                $groups[$caller] = ['calls' => 0, 'destinations' => [], 'seconds' => 0,
                    'first' => $r['call_date'], 'last' => $r['call_date'], 'sample' => []];
            }
            $g = &$groups[$caller];
            $g['calls']++;
            $g['destinations'][$receiver] = true;
            $g['seconds'] += (int) $r['duration_seconds'];
            $g['first'] = min($g['first'], $r['call_date']);
            $g['last'] = max($g['last'], $r['call_date']);
            if (count($g['sample']) < self::SAMPLE_SIZE) {
                $g['sample'][] = ['date' => $r['call_date'], 'other_number' => $receiver,
                    'duration_seconds' => (int) $r['duration_seconds']];
            }
            unset($g);
        }

        $candidates = array_filter($groups, function ($g) {
            return $g['calls'] >= self::MIN_CALLS;
        });

        if (!$candidates) {
            return 0;
        }

        // Column list deliberately excludes decision/decided_by/decided_at/note on the UPDATE
        // side — an already-decided row keeps its verdict no matter what a re-scan finds.
        $stmt = $pdo->prepare('
            INSERT INTO unknown_number_reviews
                (company_id, phone_number, call_count, distinct_destinations, first_seen, last_seen, total_seconds, sample_json)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                call_count = VALUES(call_count),
                distinct_destinations = VALUES(distinct_destinations),
                first_seen = LEAST(first_seen, VALUES(first_seen)),
                last_seen = GREATEST(last_seen, VALUES(last_seen)),
                total_seconds = VALUES(total_seconds),
                sample_json = VALUES(sample_json)
        ');

        foreach ($candidates as $phone => $g) {
            $stmt->execute([
                $companyId, $phone, $g['calls'], count($g['destinations']),
                $g['first'], $g['last'], $g['seconds'],
                json_encode($g['sample'], JSON_UNESCAPED_UNICODE),
            ]);
        }

        return count($candidates);
    }

    /**
     * Numbers this company has decided to keep out of the AI pipeline, normalized for direct
     * comparison. Loaded once per drain run rather than queried per candidate — the table stays
     * small (tens of rows at most) so this costs nothing next to a Drive download.
     *
     * @return array<string,true>
     */
    public static function skipSet(PDO $pdo, int $companyId): array
    {
        $rows = fetch_all($pdo, "
            SELECT phone_number FROM unknown_number_reviews
            WHERE company_id = ? AND decision = 'skip'
        ", [$companyId]);

        $set = [];
        foreach ($rows as $r) {
            $set[$r['phone_number']] = true;
        }
        return $set;
    }

    /**
     * Every phone number this company (or, for staff, any company) already has an identity for —
     * employees anywhere in the group, plus this company's customers across all three phone
     * columns. Same shape as GhostNumberService's own lookup, kept separate rather than shared
     * because the two callers want different lifetimes for the result (a one-off scan here vs. a
     * per-report build there).
     *
     * @return array<string,true>
     */
    private static function knownPhoneSet(PDO $erp, int $companyId): array
    {
        $known = [];

        $users = $erp->query("SELECT phone FROM users WHERE phone IS NOT NULL AND phone <> ''")->fetchAll();
        foreach ($users as $u) {
            $n = self::normalize($u['phone']);
            if ($n !== null) {
                $known[$n] = true;
            }
        }

        $stmt = $erp->prepare('SELECT phone, recipient_phone, backup_phone FROM customers WHERE company_id = ?');
        $stmt->execute([$companyId]);
        foreach ($stmt->fetchAll() as $c) {
            foreach ([$c['phone'], $c['recipient_phone'], $c['backup_phone']] as $p) {
                $n = self::normalize($p);
                if ($n !== null) {
                    $known[$n] = true;
                }
            }
        }

        return $known;
    }

    /**
     * @return string|null local 0XXXXXXXXX form, or null if unparseable.
     *
     * Public because backlog_drain.php needs the exact same normalization to check a candidate's
     * caller_phone against skipSet() -- a second hand-rolled version there missed the +66 prefix
     * gdrive_file_index actually stores numbers in, and the skip check silently matched nothing.
     */
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || $digits === null) {
            return null;
        }
        if (strpos($digits, '66') === 0 && strlen($digits) === 11) {
            return '0' . substr($digits, 2);
        }
        if ($digits[0] === '0' && strlen($digits) === 10) {
            return $digits;
        }
        if (strlen($digits) === 9) {
            return '0' . $digits;
        }
        return null;
    }
}
