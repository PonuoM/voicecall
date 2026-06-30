<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';
require_once __DIR__ . '/../Services/ErpLookupService.php';

/** Agent 3: Structured Data Extraction - pulls named entities into DB-ready JSON. */
class EntityExtractionAgent
{
    private const SYSTEM_PROMPT = <<<PROMPT
You are a structured-data extraction engine for a Thai contact-center voice intelligence
platform. Extract entities mentioned in the transcript. If a field is not mentioned, use null
(or an empty array/object for list/object fields). Never guess values that aren't in the transcript.
Keep proper nouns (customer_name, employee_name, company_name, product, promotion) exactly as
spoken/named in the transcript - do not translate names. Write descriptive free-text fields
(complaint, request, issue_category, tags) in Thai language, since these calls are for
Thai-speaking staff. Output strict JSON only, matching this shape:

{
  "customer_name": "string or null",
  "employee_name": "string or null",
  "company_name": "string or null",
  "phone": "string or null",
  "email": "string or null",
  "date": "YYYY-MM-DD or null",
  "time": "HH:MM or null",
  "product": "string or null",
  "promotion": "string or null",
  "price": "number or null",
  "order_info": {"order_id": null, "items": [], "total": null},
  "complaint": "string or null",
  "appointment": {"date": null, "time": null, "purpose": null},
  "request": "string or null",
  "issue_category": "string or null",
  "tags": ["short-tag1", "short-tag2"],
  "priority": "low|medium|high|urgent",
  "sentiment_score": "number from -1.0 (very negative) to 1.0 (very positive)",
  "matched_product_id": "integer id from PRODUCT CATALOG if the product discussed clearly matches one entry, else null - do not invent an id that isn't in the catalog",
  "linked_order_id": "id string from CUSTOMER ORDERS if this call is discussing/confirming one of those orders, else null - do not invent an id that isn't in that list"
}
PROMPT;

    public static function run(PDO $pdo, PDO $erp, int $conversationId, string $transcriptText): array
    {
        $conv = fetch_one($pdo, 'SELECT company_id, erp_customer_id, call_date FROM conversations WHERE id = ?', [$conversationId]);
        $companyId = (int) ($conv['company_id'] ?? 0);
        $catalog = $companyId ? ErpLookupService::getProductCatalog($erp, $companyId) : [];
        $nearbyOrders = ($conv && $conv['erp_customer_id'])
            ? ErpLookupService::findNearbyOrders($erp, (int) $conv['erp_customer_id'], $conv['call_date'])
            : [];

        $userPrompt = self::buildGroundedPrompt($transcriptText, $catalog, $nearbyOrders);
        $result = OpenRouterClient::chatJson(self::SYSTEM_PROMPT, $userPrompt);

        $matchedProduct = self::resolveMatchedProduct($catalog, $result['matched_product_id'] ?? null);
        $linkedOrder = self::resolveLinkedOrder($nearbyOrders, $result['linked_order_id'] ?? null);

        $priority = self::normalizePriority($result['priority'] ?? null);
        $sentimentScore = isset($result['sentiment_score']) ? max(-1.0, min(1.0, (float) $result['sentiment_score'])) : null;
        $date = self::normalizeDate($result['date'] ?? null);
        $time = self::normalizeTime($result['time'] ?? null);

        $stmt = $pdo->prepare('
            INSERT INTO extracted_entities
                (conversation_id, customer_name, employee_name, company_name, phone, email, conv_date, conv_time,
                 product, promotion, price, order_info, complaint, appointment_info, request, issue_category,
                 priority, sentiment_score, raw_json,
                 matched_product_id, matched_product_name, matched_product_price,
                 linked_order_id, linked_order_date, linked_order_total)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $conversationId,
            self::str($result['customer_name'] ?? null),
            self::str($result['employee_name'] ?? null),
            self::str($result['company_name'] ?? null),
            self::str($result['phone'] ?? null),
            self::str($result['email'] ?? null),
            $date,
            $time,
            self::str($result['product'] ?? null),
            self::str($result['promotion'] ?? null),
            isset($result['price']) && is_numeric($result['price']) ? (float) $result['price'] : null,
            json_encode($result['order_info'] ?? [], JSON_UNESCAPED_UNICODE),
            self::str($result['complaint'] ?? null),
            json_encode($result['appointment'] ?? [], JSON_UNESCAPED_UNICODE),
            self::str($result['request'] ?? null),
            self::str($result['issue_category'] ?? null),
            $priority,
            $sentimentScore,
            json_encode($result, JSON_UNESCAPED_UNICODE),
            $matchedProduct ? $matchedProduct['id'] : null,
            $matchedProduct ? $matchedProduct['name'] : null,
            $matchedProduct ? $matchedProduct['price'] : null,
            $linkedOrder ? $linkedOrder['id'] : null,
            $linkedOrder ? $linkedOrder['order_date'] : null,
            $linkedOrder ? $linkedOrder['total_amount'] : null,
        ]);

        $insertTag = $pdo->prepare('INSERT INTO conversation_tags (conversation_id, tag) VALUES (?,?)');
        foreach (($result['tags'] ?? []) as $tag) {
            if (is_string($tag) && $tag !== '') {
                $insertTag->execute([$conversationId, $tag]);
            }
        }

        // Phase 1 already tried to match this call to an ERP customer by the recording's
        // caller/receiver phone number. If that came up empty but the transcript itself mentions
        // a phone number, retry the lookup with it — never create a new customer record locally,
        // primacom_mini_erp.customers is read-only and is the only source of truth for who a
        // customer is.
        self::retryErpCustomerLookup($pdo, $erp, $conversationId, self::str($result['phone'] ?? null));

        return $result;
    }

    private static function buildGroundedPrompt(string $transcriptText, array $catalog, array $nearbyOrders): string
    {
        $parts = [];

        if (!empty($catalog)) {
            $lines = array_map(function ($p) {
                return "- id={$p['id']}: {$p['name']} ({$p['price']} บาท)";
            }, $catalog);
            $parts[] = "PRODUCT CATALOG (official names/prices for this company - match whatever product "
                . "the transcript mentions, however garbled the speech-to-text spelling is, to the closest "
                . "entry below; only set matched_product_id if you are confident, otherwise leave it null):\n"
                . implode("\n", $lines);
        }

        if (!empty($nearbyOrders)) {
            $lines = [];
            foreach ($nearbyOrders as $order) {
                $itemsDesc = implode(', ', array_map(function ($i) {
                    return "{$i['product_name']} x{$i['quantity']} @ {$i['price_per_unit']}";
                }, $order['items']));
                $lines[] = "- id={$order['id']} | {$order['order_date']} | total {$order['total_amount']} บาท | "
                    . "{$order['order_status']} | items: {$itemsDesc}";
            }
            $parts[] = "CUSTOMER'S REAL ORDERS NEAR THIS CALL DATE (if the call is confirming, discussing, or "
                . "following up on one of these, set linked_order_id to its id; otherwise leave it null):\n"
                . implode("\n", $lines);
        }

        $parts[] = "TRANSCRIPT:\n{$transcriptText}";
        return implode("\n\n", $parts);
    }

    /**
     * @return array{id:int,name:string,price:float}|null
     */
    private static function resolveMatchedProduct(array $catalog, $matchedProductId): ?array
    {
        if ($matchedProductId === null || $matchedProductId === '') {
            return null;
        }
        foreach ($catalog as $p) {
            if ($p['id'] === (int) $matchedProductId) {
                return $p; // re-derive from our own catalog list, never trust the LLM's echoed id alone
            }
        }
        return null; // model hallucinated an id that isn't in the list we gave it
    }

    /**
     * @return array{id:string,order_date:string,total_amount:float}|null
     */
    private static function resolveLinkedOrder(array $nearbyOrders, $linkedOrderId): ?array
    {
        if (!$linkedOrderId) {
            return null;
        }
        foreach ($nearbyOrders as $o) {
            if ($o['id'] === (string) $linkedOrderId) {
                return $o; // re-derive from our own order list, never trust the LLM's echoed id alone
            }
        }
        return null; // model hallucinated an id that isn't in the list we gave it
    }

    private static function retryErpCustomerLookup(PDO $pdo, PDO $erp, int $conversationId, ?string $extractedPhone): void
    {
        if (!$extractedPhone) {
            return;
        }
        $conv = fetch_one($pdo, 'SELECT erp_customer_id FROM conversations WHERE id = ?', [$conversationId]);
        if (!$conv || $conv['erp_customer_id']) {
            return; // already matched, or conversation missing
        }
        $customer = ErpLookupService::findCustomerByPhone($erp, $extractedPhone);
        if (!$customer) {
            return;
        }
        $pdo->prepare('UPDATE conversations SET erp_customer_id = ?, erp_customer_name = ? WHERE id = ?')
            ->execute([$customer['id'], $customer['name'], $conversationId]);
    }

    private static function str($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : $s;
    }

    private static function normalizePriority(?string $value): string
    {
        $allowed = ['low', 'medium', 'high', 'urgent'];
        $value = strtolower((string) $value);
        return in_array($value, $allowed, true) ? $value : 'medium';
    }

    private static function normalizeDate(?string $value): ?string
    {
        return ($value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
    }

    private static function normalizeTime(?string $value): ?string
    {
        return ($value && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) ? $value : null;
    }
}
