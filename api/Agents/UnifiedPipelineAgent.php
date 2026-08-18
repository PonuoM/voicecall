<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';
require_once __DIR__ . '/../Services/ThaiNumerals.php';
require_once __DIR__ . '/../Services/ErpLookupService.php';
require_once __DIR__ . '/../Services/FraudCheckService.php';

/**
 * Unified Pipeline Agent - Best Practice: Token Optimization.
 * Combines Conversation Understanding, Entity Extraction, and Compliance Checking into a single LLM call.
 */
class UnifiedPipelineAgent
{
    public const DEFAULT_SYSTEM_PROMPT = <<<PROMPT
You are an expert voice intelligence analyst for a Thai contact-center.
Read the transcript and context, and produce a structured analysis. Output strict JSON only matching this shape:

{
  "summary": {
    "executive_summary": "2-4 sentence summary of the whole conversation (in Thai)",
    "key_topics": ["topic1", "topic2"],
    "action_items": [{"description": "...", "owner": "employee|customer|unspecified", "due_date": "YYYY-MM-DD or null"}],
    "decisions_made": ["decision1"],
    "follow_up_tasks": ["task1"],
    "customer_intent": "short phrase describing what the customer wanted (in Thai)",
    "customer_sentiment": "positive|neutral|negative|mixed",
    "important_keywords": ["keyword1", "keyword2"]
  },
  "entities": {
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
    "sentiment_score": "number from -1.0 to 1.0",
    "sale_outcome": "closed_won|follow_up|declined|not_sales_call"
  },
  "compliance": {
    "violations": [
      {
        "rule_name": "must match provided rule name",
        "severity": "low|medium|high|critical",
        "evidence": "verbatim quote from transcript",
        "explanation": "why (in Thai)",
        "suggested_improvement": "what to do (in Thai)"
      }
    ]
  },
  "fraud_signals": {
    "payment_channels": [
      {
        "channel_type": "bank_account|promptpay|line_id|wallet|other",
        "raw_mention": "the exact form spoken in the transcript",
        "normalized_value": "digits only for bank_account/promptpay, the ID itself for line_id",
        "bank_name": "bank name if mentioned, else null",
        "spoken_by": "employee|customer|unknown",
        "purpose": "why this channel was given (in Thai)",
        "evidence": "verbatim quote from transcript"
      }
    ],
    "off_channel_contacts": [
      {
        "channel_type": "line_id|personal_phone|other",
        "raw_mention": "the exact form spoken in the transcript (e.g. the LINE ID, or 'ไลน์' if no ID given)",
        "requested_by": "employee|customer",
        "stated_reason": "why, in the speaker's own words (in Thai) -- verbatim or close paraphrase, not your own inference",
        "evidence": "verbatim quote from transcript"
      }
    ]
  }
}

For "entities.sale_outcome": "closed_won" ONLY when the customer clearly agreed in this call to
buy / confirm an order (explicit agreement, not mere interest). "follow_up" when undecided or a
callback was arranged, "declined" when the customer refused, "not_sales_call" for anything that
was not a sales conversation.

For "fraud_signals.payment_channels": capture EVERY mention of a destination for transferring
money or sending payment slips — bank account numbers, PromptPay numbers, LINE IDs given for
payment/slip/ordering, e-wallets (TrueMoney etc). Numbers spoken as Thai words
("สองหกสี่สามศูนย์...") MUST be converted to Arabic digits in "normalized_value". Include a
phone number ONLY when it is referenced as a PromptPay/payment destination — not numbers
exchanged merely for calling back. Do NOT decide whether a channel is fraudulent; report
every mention neutrally (verification happens outside the model). If none, return
{"payment_channels": []}.

Do NOT include a number or ID as a payment channel when the surrounding context is any of:
(a) a product/formula/SKU code -- this business sells fertilizer identified by numeric ratios
    (e.g. "สูตร 6-3-3", "12-4-4"); a number introduced as "สูตร" (formula), a product ratio, or a
    batch/lot code is a product identifier, never a payment destination, even if it is a bank-account-
    length digit string;
(b) an automated system message -- carrier voicemail greetings, IVR prompts, and "the number you
    dialed is unavailable / please leave a message" scripts read a phone number back mechanically;
    this is not a human providing a payment channel;
(c) a callback number -- "จดเบอร์ผมไว้", "โทรกลับได้ที่", or similar, where the number is offered so
    the OTHER party can call back, not so money can be sent to it.
Only capture a channel when there is an explicit payment-purpose anchor: "โอนมาที่", "พร้อมเพย์เบอร์",
"ส่งสลิปมาที่", "บัญชีชื่อ", or equivalent. If the purpose is ambiguous, leave it out rather than guess
-- a missed channel costs nothing; a wrong one wastes a reviewer's time chasing a fertilizer formula.

For "fraud_signals.off_channel_contacts": capture it when someone in the call actively SUGGESTS or
ASKS to continue outside this recorded channel -- "แอดไลน์มาคุยกันนะ", "โทรหาผมที่เบอร์ส่วนตัวดีกว่า",
offering or requesting a LINE ID or a personal phone number as somewhere to continue the
conversation. In "stated_reason" write down whatever reason was actually given in the call, in the
speaker's own words -- do not invent one and do not judge whether it is a good reason, that
happens outside the model. Do NOT capture:
- the company's own official LINE/page being mentioned or confirmed (routine order confirmation
  through an already-established channel is normal business here, not a redirect);
- the customer simply stating they already have the company's contact info;
- a callback number given for the same reason as in payment_channels (so the OTHER party can call
  back), which is not a request to move the conversation itself off-channel.
If genuinely nothing was said about why, leave "stated_reason" as an empty string rather than
guessing -- an empty reason is itself useful signal, and a fabricated one is not.
PROMPT;

    /**
     * required_phrases / prohibited_words are stored as a JSON array string, or NULL. Decodes
     * defensively -- a malformed or empty value should silently contribute nothing to the prompt,
     * not throw mid-pipeline over a data-entry mistake in a rule someone typed by hand.
     * @return string[]
     */
    private static function decodeWordList(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $decoded), function ($w) {
            return trim($w) !== '';
        }));
    }

    public static function run(PDO $pdo, PDO $erp, int $conversationId, int $companyId, string $transcriptText, ?string $externalContext = null): array
    {
        // 1. Get Custom Additional Prompt
        $promptStmt = $pdo->prepare("SELECT additional_prompt, max_tokens FROM ai_prompts WHERE company_id = ? AND agent_name = 'unified' LIMIT 1");
        $promptStmt->execute([$companyId]);
        $customPrompt = $promptStmt->fetch(PDO::FETCH_ASSOC);
        
        $systemPrompt = self::DEFAULT_SYSTEM_PROMPT;
        if ($customPrompt && !empty($customPrompt['additional_prompt'])) {
            $systemPrompt .= "\n\n=== ADDITIONAL INSTRUCTIONS ===\n" . $customPrompt['additional_prompt'];
        }
        
        // 2. Get Compliance Rules
        //
        // required_phrases / prohibited_words are stored per rule but, until now, never left this
        // function -- only rule_name and description reached the model. For "Company identification
        // at call start" that meant the model was judging compliance from "must identify the company
        // name" alone, with no idea WHICH names count, so a legitimate sub-brand greeting ("ปุ๋ยน้ำธาตุ
        // โมโน") could read as a miss even though it is one of the company's own product lines. The
        // configured anchors exist specifically to prevent that guesswork and were sitting unused.
        $rulesStmt = $pdo->prepare('SELECT id, rule_name, description, rule_type, required_phrases, prohibited_words FROM compliance_rules WHERE company_id = ? AND active = 1');
        $rulesStmt->execute([$companyId]);
        $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);

        $rulesText = "BUSINESS RULES:\n";
        if (empty($rules)) {
            $rulesText .= "None\n";
        } else {
            foreach ($rules as $r) {
                $rulesText .= "- {$r['rule_name']}: {$r['description']}\n";
                $required = self::decodeWordList($r['required_phrases']);
                if ($required) {
                    $rulesText .= "  ACCEPTED as satisfying this rule (any ONE of these counts, exact wording not required):"
                        . " " . implode(', ', $required) . "\n";
                }
                $prohibited = self::decodeWordList($r['prohibited_words']);
                if ($prohibited) {
                    $rulesText .= "  PROHIBITED phrases for this rule (flag if the agent says anything with this"
                        . " meaning, not only these exact words): " . implode(', ', $prohibited) . "\n";
                }
            }
            // Recordings here sometimes start mid-call rather than at the actual ring -- a transcript
            // whose first turn already reads like an answer or a name confirmation, not a greeting,
            // gives no evidence either way about what was said before the recording began. Judging a
            // "call start" rule against audio that never captured the start produces exactly the kind
            // of violation nothing could have prevented.
            $rulesText .= "\nFor any rule about the OPENING of the call (e.g. company identification, "
                . "required opening greeting): if the transcript's first turn already sounds like it is "
                . "continuing a conversation already in progress (e.g. answering a question, confirming "
                . "a name, no greeting at all), do NOT flag a violation for that rule -- the recording "
                . "likely missed the true start, so there is no evidence the agent skipped it.\n";
        }
        
        $contextText = "";
        if ($externalContext) {
            $contextText = "EXTERNAL CONTEXT:\n{$externalContext}\n\n";
        }

        // Spoken digit runs are resolved before the model sees them. Typhoon writes numbers as
        // words — "ส่วนเก้าสามสามหนึ่งห้าสามแปดสามศูนย์" for 0933153830 — and asking the model to read
        // those back cost us the first real call through the new pipeline: it answered
        // 093-315-8305, the right digits with two swapped. Reading ten words in order is a lookup,
        // and a lookup should not be probabilistic. ErpLookupService matches customers to the ERP by
        // phone number, so one transposed digit becomes a ghost number and an unlinked order.
        //
        // Only digit runs are touched; compositional numerals like "ห้าสิบ" or "สามพันเจ็ดร้อย" are
        // left as written, because the model reads those correctly and rewriting them would risk
        // real text for a problem that does not exist. The decoded sequences are also listed
        // separately and marked authoritative, so there is no ambiguity about which wins.
        $normalizedTranscript = ThaiNumerals::normalize($transcriptText);
        $sequences = ThaiNumerals::extractSequences($transcriptText);

        $numbersText = '';
        if ($sequences) {
            $numbersText = "NUMBER SEQUENCES (already decoded from spoken Thai — use these EXACT digits,"
                . " do not re-derive them from the words):\n- " . implode("\n- ", $sequences) . "\n\n";
        }

        $userPrompt = "{$contextText}{$rulesText}\n{$numbersText}TRANSCRIPT:\n{$normalizedTranscript}";
        
        // 3. Call LLM
        $result = OpenRouterClient::chatJson($systemPrompt, $userPrompt, OpenRouterClient::complianceModel());
        
        // 4. Save Summary
        $summary = $result['summary'] ?? [];
        $stmtSum = $pdo->prepare('
            INSERT INTO summaries (conversation_id, executive_summary, key_topics, action_items, decisions_made, follow_up_tasks, customer_intent, customer_sentiment, important_keywords)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        $stmtSum->execute([
            $conversationId,
            $summary['executive_summary'] ?? '',
            json_encode($summary['key_topics'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($summary['action_items'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($summary['decisions_made'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($summary['follow_up_tasks'] ?? [], JSON_UNESCAPED_UNICODE),
            $summary['customer_intent'] ?? null,
            self::normalizeSentiment($summary['customer_sentiment'] ?? null),
            json_encode($summary['important_keywords'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);

        $keywords = $summary['important_keywords'] ?? [];
        $insertKeyword = $pdo->prepare('INSERT INTO keywords (conversation_id, keyword, weight) VALUES (?,?,1.0)');
        foreach ($keywords as $kw) {
            if (is_string($kw) && $kw !== '') {
                $insertKeyword->execute([$conversationId, $kw]);
            }
        }

        // 5. Save Entities — column names must match the real schema (conv_date/conv_time/
        // appointment_info; tags live in the conversation_tags table, not a column here)
        $entities = $result['entities'] ?? [];
        $stmtEnt = $pdo->prepare('
            INSERT INTO extracted_entities (conversation_id, customer_name, employee_name, company_name, phone, email, conv_date, conv_time, product, promotion, price, order_info, complaint, appointment_info, request, issue_category, priority, sale_outcome, sentiment_score, raw_json, matched_product_id, linked_order_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $priority = strtolower(trim((string) ($entities['priority'] ?? '')));
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $priority = null;
        }
        $saleOutcome = strtolower(trim((string) ($entities['sale_outcome'] ?? '')));
        if (!in_array($saleOutcome, ['closed_won', 'follow_up', 'declined', 'not_sales_call'], true)) {
            $saleOutcome = null;
        }
        $sentimentScore = isset($entities['sentiment_score']) && is_numeric($entities['sentiment_score'])
            ? max(-1.0, min(1.0, (float) $entities['sentiment_score'])) : null;
        $stmtEnt->execute([
            $conversationId,
            self::str($entities['customer_name'] ?? null, 255),
            self::str($entities['employee_name'] ?? null, 255),
            self::str($entities['company_name'] ?? null, 255),
            self::str($entities['phone'] ?? null, 50),
            self::str($entities['email'] ?? null, 255),
            self::normalizeDate($entities['date'] ?? null),
            self::normalizeTime($entities['time'] ?? null),
            self::str($entities['product'] ?? null, 255),
            self::str($entities['promotion'] ?? null, 255),
            isset($entities['price']) && is_numeric($entities['price']) ? (float) $entities['price'] : null,
            json_encode($entities['order_info'] ?? [], JSON_UNESCAPED_UNICODE),
            $entities['complaint'] ?? null,
            json_encode($entities['appointment'] ?? [], JSON_UNESCAPED_UNICODE),
            $entities['request'] ?? null,
            self::str($entities['issue_category'] ?? null, 100),
            $priority,
            $saleOutcome,
            $sentimentScore,
            json_encode($result, JSON_UNESCAPED_UNICODE),
            null, // matched_product_id: ERP grounding not implemented in the unified agent yet
            null  // linked_order_id
        ]);

        $insertTag = $pdo->prepare('INSERT INTO conversation_tags (conversation_id, tag) VALUES (?,?)');
        foreach (($entities['tags'] ?? []) as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $insertTag->execute([$conversationId, mb_substr(trim($tag), 0, 100)]);
            }
        }

        // 6. Save Compliance — schema is overall_status enum('compliant','minor_issues',
        // 'violations_found') + violation_count + model_used; individual violations go in the
        // violations table (there is no violations_json column)
        $compliance = $result['compliance'] ?? [];
        $violations = $compliance['violations'] ?? [];
        if (!is_array($violations)) {
            $violations = [];
        }

        $hasHighOrCritical = false;
        foreach ($violations as $v) {
            if (in_array(strtolower($v['severity'] ?? ''), ['high', 'critical'], true)) {
                $hasHighOrCritical = true;
                break;
            }
        }
        $overallStatus = count($violations) === 0 ? 'compliant' : ($hasHighOrCritical ? 'violations_found' : 'minor_issues');

        $stmtComp = $pdo->prepare('INSERT INTO compliance_reports (conversation_id, overall_status, violation_count, model_used) VALUES (?,?,?,?)');
        $stmtComp->execute([$conversationId, $overallStatus, count($violations), OpenRouterClient::complianceModel()]);
        $reportId = (int) $pdo->lastInsertId();

        $ruleIdByName = [];
        foreach ($rules as $r) {
            $ruleIdByName[$r['rule_name']] = (int) $r['id'];
        }
        $insertViolation = $pdo->prepare('
            INSERT INTO violations (compliance_report_id, conversation_id, rule_id, rule_name, severity, evidence, explanation, suggested_improvement)
            VALUES (?,?,?,?,?,?,?,?)
        ');
        foreach ($violations as $v) {
            $severity = strtolower(trim((string) ($v['severity'] ?? '')));
            if (!in_array($severity, ['low', 'medium', 'high', 'critical'], true)) {
                $severity = 'low';
            }
            $ruleName = trim((string) ($v['rule_name'] ?? ''));
            $insertViolation->execute([
                $reportId,
                $conversationId,
                $ruleIdByName[$ruleName] ?? null,
                mb_substr($ruleName, 0, 255),
                $severity,
                $v['evidence'] ?? null,
                $v['explanation'] ?? null,
                $v['suggested_improvement'] ?? null,
            ]);
        }

        // 7. Fraud cross-check — the LLM above only extracted payment-channel mentions; the
        // verdict (is this an official company account?) is computed deterministically against
        // primacom_mini_erp.bank_account in FraudCheckService, never by the model.
        try {
            $paymentChannels = $result['fraud_signals']['payment_channels'] ?? [];
            FraudCheckService::run($pdo, $erp, $conversationId, $companyId, is_array($paymentChannels) ? $paymentChannels : [], $transcriptText);
        } catch (Throwable $e) {
            // Non-fatal: the extraction survives in extracted_entities.raw_json, so a failed
            // cross-check (e.g. ERP unreachable) is recoverable without paying for the LLM again.
            file_put_contents(LOG_DIR . '/fraud_error.log', date('Y-m-d H:i:s') . " conversation={$conversationId} " . $e->getMessage() . "\n", FILE_APPEND);
        }

        // 8. Off-channel contact — same extract-here/decide-there split as payment channels. The
        // model reports who suggested leaving the recorded channel and the reason they actually
        // gave; FraudCheckService decides whether that reason clears it (sending a photo, which a
        // phone call cannot do) or needs a human's attention, by matching the stated words rather
        // than trusting either the model's or this comment's opinion of what counts as a good reason.
        try {
            $offChannel = $result['fraud_signals']['off_channel_contacts'] ?? [];
            FraudCheckService::runOffChannel($pdo, $conversationId, $companyId, is_array($offChannel) ? $offChannel : []);
        } catch (Throwable $e) {
            file_put_contents(LOG_DIR . '/fraud_error.log', date('Y-m-d H:i:s') . " conversation={$conversationId} (off_channel) " . $e->getMessage() . "\n", FILE_APPEND);
        }

        return $result;
    }

    private static function str($value, int $maxLen): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $s = trim((string) $value);
        return $s === '' ? null : mb_substr($s, 0, $maxLen);
    }

    private static function normalizeDate(?string $value): ?string
    {
        return ($value && is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
    }

    private static function normalizeTime(?string $value): ?string
    {
        return ($value && is_string($value) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $value)) ? $value : null;
    }

    private static function normalizeSentiment(?string $sent): string
    {
        $s = strtolower(trim($sent ?? ''));
        if (in_array($s, ['positive', 'neutral', 'negative', 'mixed'])) {
            return $s;
        }
        return 'neutral';
    }
}
