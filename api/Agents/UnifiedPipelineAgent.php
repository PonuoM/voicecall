<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';
require_once __DIR__ . '/../Services/ErpLookupService.php';

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
    "sentiment_score": "number from -1.0 to 1.0"
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
  }
}
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
        $rulesStmt = $pdo->prepare('SELECT rule_name, description FROM compliance_rules WHERE company_id = ? AND active = 1');
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
        $result = OpenRouterClient::chatJson($systemPrompt, $userPrompt, OPENROUTER_COMPLIANCE_MODEL);
        
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

        // 5. Save Entities
        $entities = $result['entities'] ?? [];
        $stmtEnt = $pdo->prepare('
            INSERT INTO extracted_entities (conversation_id, customer_name, employee_name, company_name, phone, email, date, time, product, promotion, price, order_info, complaint, appointment, request, issue_category, tags, priority, sentiment_score, matched_product_id, linked_order_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ');
        $stmtEnt->execute([
            $conversationId,
            $entities['customer_name'] ?? null,
            $entities['employee_name'] ?? null,
            $entities['company_name'] ?? null,
            $entities['phone'] ?? null,
            $entities['email'] ?? null,
            $entities['date'] ?? null,
            $entities['time'] ?? null,
            $entities['product'] ?? null,
            $entities['promotion'] ?? null,
            $entities['price'] ?? null,
            json_encode($entities['order_info'] ?? [], JSON_UNESCAPED_UNICODE),
            $entities['complaint'] ?? null,
            json_encode($entities['appointment'] ?? [], JSON_UNESCAPED_UNICODE),
            $entities['request'] ?? null,
            $entities['issue_category'] ?? null,
            json_encode($entities['tags'] ?? [], JSON_UNESCAPED_UNICODE),
            $entities['priority'] ?? null,
            $entities['sentiment_score'] ?? null,
            null, // Could do lookup here, skipping for brevity
            null
        ]);

        // 6. Save Compliance
        $compliance = $result['compliance'] ?? [];
        $violations = $compliance['violations'] ?? [];
        
        $overallStatus = 'compliant';
        if (count($violations) > 0) {
            $hasHighOrCritical = false;
            foreach ($violations as $v) {
                if (in_array(strtolower($v['severity'] ?? ''), ['high', 'critical'], true)) {
                    $hasHighOrCritical = true;
                    break;
                }
            }
            $overallStatus = $hasHighOrCritical ? 'critical_violation' : 'minor_violation';
        }

        $stmtComp = $pdo->prepare('INSERT INTO compliance_reports (conversation_id, overall_status, violations_json) VALUES (?,?,?)');
        $stmtComp->execute([$conversationId, $overallStatus, json_encode($violations, JSON_UNESCAPED_UNICODE)]);

        return $result;
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
