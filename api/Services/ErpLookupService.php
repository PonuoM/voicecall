<?php

/**
 * Read-only phone-number lookups against the remote primacom_mini_erp DB (customers/users).
 * There is no shared call-id between a Google Drive recording and any primacom_mini_erp row —
 * see memory note on call_history — so phone number is the only reliable join key, and even
 * that is best-effort: customers.phone is not unique (duplicate/legacy records exist) and many
 * call numbers won't match any known customer or employee at all. Callers must treat a null
 * result as normal, not an error.
 */
class ErpLookupService
{
    /**
     * @return array{id:int,name:string}|null
     */
    public static function findCustomerByPhone(PDO $erp, ?string $phone): ?array
    {
        $candidates = self::candidateFormats($phone);
        if (empty($candidates)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $erp->prepare("
            SELECT customer_id, first_name, last_name
            FROM customers
            WHERE phone IN ({$placeholders}) OR recipient_phone IN ({$placeholders}) OR backup_phone IN ({$placeholders})
            ORDER BY date_registered DESC
            LIMIT 1
        ");
        $stmt->execute(array_merge($candidates, $candidates, $candidates));
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['customer_id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        ];
    }

    /**
     * @return array{id:int,name:string}|null
     */
    public static function findEmployeeByPhone(PDO $erp, ?string $phone): ?array
    {
        $candidates = self::candidateFormats($phone);
        if (empty($candidates)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $erp->prepare("
            SELECT id, first_name, last_name
            FROM users
            WHERE phone IN ({$placeholders})
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute($candidates);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return [
            'id' => (int) $row['id'],
            'name' => trim($row['first_name'] . ' ' . $row['last_name']),
        ];
    }

    /**
     * Active product catalog for a company — small enough (tens of rows, not thousands) to hand
     * to an LLM whole as grounding context, so EntityExtractionAgent matches the product the
     * customer actually ordered against real names/prices instead of guessing from a possibly
     * garbled STT transcription of the product name.
     * @return array<array{id:int,name:string,price:float}>
     */
    public static function getProductCatalog(PDO $erp, int $companyId): array
    {
        $stmt = $erp->prepare("
            SELECT id, name, price
            FROM products
            WHERE company_id = ? AND status = 'Active'
            ORDER BY id
        ");
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll();

        $catalog = [];
        foreach ($rows as $row) {
            $catalog[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'price' => (float) $row['price'],
            ];
        }
        return $catalog;
    }

    /**
     * Orders + line items for a customer placed within $windowDays of the call date, in either
     * direction. A call that sounds like an order confirmation/closing-the-sale is most likely
     * referring to one of these real orders rather than a number the LLM has to invent from the
     * transcript alone.
     * @return array<array{id:string,order_date:string,total_amount:float,order_status:string,items:array<array{product_name:string,quantity:int,price_per_unit:float,net_total:float}>}>
     */
    public static function findNearbyOrders(PDO $erp, int $customerId, ?string $callDate, int $windowDays = 3): array
    {
        if (!$callDate) {
            return [];
        }

        $stmt = $erp->prepare("
            SELECT id, order_date, total_amount, order_status
            FROM orders
            WHERE customer_id = ?
              AND order_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
            ORDER BY order_date DESC
        ");
        $stmt->execute([$customerId, $callDate, $windowDays, $callDate, $windowDays]);
        $orders = $stmt->fetchAll();
        if (empty($orders)) {
            return [];
        }

        $itemsStmt = $erp->prepare('
            SELECT product_name, quantity, price_per_unit, net_total
            FROM order_items
            WHERE parent_order_id = ?
        ');

        $result = [];
        foreach ($orders as $order) {
            $itemsStmt->execute([$order['id']]);
            $result[] = [
                'id' => $order['id'],
                'order_date' => $order['order_date'],
                'total_amount' => (float) $order['total_amount'],
                'order_status' => $order['order_status'],
                'items' => $itemsStmt->fetchAll(),
            ];
        }
        return $result;
    }

    /**
     * Batch version of findCustomerByPhone(), returning EVERY customer record that carries the
     * number, not just the best one. 26k phone numbers in this ERP sit on more than one customer
     * row (~24% of the customer base), and telesales log calls against whichever record they had
     * open — so anything searching call_history or orders by customer must look at all of them or
     * it will report "no CRM entry" for calls that were logged against a duplicate.
     *
     * $companyId MUST be passed whenever the result will be used to look up CRM entries or orders:
     * 26,244 of the 26,246 duplicated phone numbers are the same person registered under DIFFERENT
     * companies in the group, so without scoping, a company-1 recording would pick up company-7
     * call logs and orders.
     *
     * 'id'/'name' are the newest registration (what the ERP UI shows); 'all_ids' is every match
     * within the requested company.
     * @param string[] $phones raw phone strings
     * @return array<string,array{id:int,name:string,all_ids:int[]}> keyed by the ORIGINAL input
     */
    public static function findCustomersByPhones(PDO $erp, array $phones, ?int $companyId = null): array
    {
        $formatsByPhone = [];
        $allFormats = [];
        foreach ($phones as $phone) {
            $formats = self::candidateFormats($phone);
            if (empty($formats)) {
                continue;
            }
            $formatsByPhone[$phone] = $formats;
            foreach ($formats as $f) {
                $allFormats[$f] = true;
            }
        }
        if (empty($allFormats)) {
            return [];
        }

        $formatList = array_keys($allFormats);
        $ph = implode(',', array_fill(0, count($formatList), '?'));
        $params = array_merge($formatList, $formatList, $formatList);
        $companySql = '';
        if ($companyId !== null) {
            $companySql = ' AND company_id = ?';
            $params[] = $companyId;
        }
        $stmt = $erp->prepare("
            SELECT customer_id, first_name, last_name, phone, recipient_phone, backup_phone
            FROM customers
            WHERE (phone IN ({$ph}) OR recipient_phone IN ({$ph}) OR backup_phone IN ({$ph})){$companySql}
            ORDER BY date_registered ASC
        ");
        $stmt->execute($params);

        // Ascending order means later rows overwrite id/name, leaving the newest registration in
        // place — matching findCustomerByPhone()'s ORDER BY date_registered DESC LIMIT 1 — while
        // all_ids accumulates every duplicate.
        $byStoredPhone = [];
        foreach ($stmt->fetchAll() as $row) {
            $customerId = (int) $row['customer_id'];
            foreach ([$row['phone'], $row['recipient_phone'], $row['backup_phone']] as $p) {
                if ($p === null || $p === '') {
                    continue;
                }
                $existing = $byStoredPhone[$p]['all_ids'] ?? [];
                $existing[$customerId] = true;
                $byStoredPhone[$p] = [
                    'id' => $customerId,
                    'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                    'all_ids' => $existing,
                ];
            }
        }
        foreach ($byStoredPhone as $p => $entry) {
            $byStoredPhone[$p]['all_ids'] = array_keys($entry['all_ids']);
        }

        $result = [];
        foreach ($formatsByPhone as $phone => $formats) {
            foreach ($formats as $f) {
                if (isset($byStoredPhone[$f])) {
                    $result[$phone] = $byStoredPhone[$f];
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Batch version of findEmployeeByPhone() — one query for a whole list of numbers instead of
     * one round-trip each, for report screens that resolve a ranking of callers at once.
     * @param string[] $phones raw phone strings (any format candidateFormats() understands)
     * @return array<string,array{id:int,name:string}> keyed by the ORIGINAL input string, so
     *         callers can look results up without re-normalizing; unmatched phones are absent.
     */
    public static function findEmployeesByPhones(PDO $erp, array $phones): array
    {
        $formatsByPhone = [];
        $allFormats = [];
        foreach ($phones as $phone) {
            $formats = self::candidateFormats($phone);
            if (empty($formats)) {
                continue;
            }
            $formatsByPhone[$phone] = $formats;
            foreach ($formats as $f) {
                $allFormats[$f] = true;
            }
        }
        if (empty($allFormats)) {
            return [];
        }

        $formatList = array_keys($allFormats);
        $placeholders = implode(',', array_fill(0, count($formatList), '?'));
        $stmt = $erp->prepare("SELECT id, first_name, last_name, phone FROM users WHERE phone IN ({$placeholders})");
        $stmt->execute($formatList);

        $byStoredPhone = [];
        foreach ($stmt->fetchAll() as $row) {
            $byStoredPhone[$row['phone']] = [
                'id' => (int) $row['id'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            ];
        }

        $result = [];
        foreach ($formatsByPhone as $phone => $formats) {
            foreach ($formats as $f) {
                if (isset($byStoredPhone[$f])) {
                    $result[$phone] = $byStoredPhone[$f];
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Recording phone numbers come in as E.164 ("+66945547266"). primacom_mini_erp itself is
     * NOT internally consistent about which format it stores: `customers.phone` is reliably Thai
     * local format ("0945547266"), but `users.phone` is a real mix - confirmed directly against
     * the live DB that 52 of 96 employee phone numbers are stored as "66XXXXXXXXX" (international,
     * no leading 0, no +) rather than local format. A phone lookup that only tries local format
     * silently fails to match the majority of employees, so every lookup tries both forms.
     * @return string[] candidate formats to check, e.g. ["0994164440", "66994164440"]
     */
    public static function candidateFormats(?string $phone): array
    {
        if ($phone === null) {
            return [];
        }
        $digits = preg_replace('/\D/', '', $phone);
        if ($digits === '' || $digits === null) {
            return [];
        }

        $local = null;
        if (strpos($digits, '66') === 0 && strlen($digits) === 11) {
            $local = '0' . substr($digits, 2);
        } elseif ($digits[0] === '0' && strlen($digits) === 10) {
            $local = $digits;
        } elseif (strlen($digits) === 9) {
            // missing leading 0 (e.g. stripped somewhere upstream)
            $local = '0' . $digits;
        }

        if ($local === null) {
            return [$digits];
        }
        return [$local, '66' . substr($local, 1)];
    }
}
