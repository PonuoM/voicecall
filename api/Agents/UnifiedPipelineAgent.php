<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';
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
PROMPT;

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
        $rulesStmt = $pdo->prepare('SELECT id, rule_name, description FROM compliance_rules WHERE company_id = ? AND active = 1');
        $rulesStmt->execute([$companyId]);
        $rules = $rulesStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $rulesText = "BUSINESS RULES:\n";
        if (empty($rules)) {
            $rulesText .= "None\n";
        } else {
            foreach ($rules as $r) {
                $rulesText .= "- {$r['rule_name']}: {$r['description']}\n";
            }
        }
        
        $contextText = "";
        if ($externalContext) {
            $contextText = "EXTERNAL CONTEXT:\n{$externalContext}\n\n";
        }

        $userPrompt = "{$contextText}{$rulesText}\nTRANSCRIPT:\n{$transcriptText}";
        
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
