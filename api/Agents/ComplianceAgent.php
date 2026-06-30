<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';

/** Agent 4: Compliance & Rule Checker - evaluates the transcript against configurable business rules. */
class ComplianceAgent
{
    private const SYSTEM_PROMPT = <<<PROMPT
You are a strict compliance auditor for a Thai contact-center voice intelligence platform.
You will be given a list of business rules and a call transcript. For EACH violation you find,
report it with concrete evidence (a short quote from the transcript). Do not report a violation
unless you can quote supporting evidence. If a required disclosure/script rule was never triggered
(e.g. no promotion was discussed, so "explain promotion terms" does not apply), do not report it
as a violation. "evidence" must be a verbatim quote from the transcript (keep its original
language). Write "explanation" and "suggested_improvement" in Thai language, since these reports
are read by Thai-speaking staff. Output strict JSON only, matching this shape:

{
  "violations": [
    {
      "rule_name": "must match one of the provided rule names",
      "severity": "low|medium|high|critical",
      "evidence": "short verbatim quote from the transcript",
      "explanation": "why this is a violation (in Thai)",
      "suggested_improvement": "what the employee should have said/done instead (in Thai)"
    }
  ]
}

If there are no violations, return {"violations": []}.
PROMPT;

    public static function run(PDO $pdo, int $conversationId, int $companyId, string $transcriptText): array
    {
        $rulesStmt = $pdo->prepare('SELECT id, rule_name, category, description, rule_type, severity_default, prohibited_words, required_phrases FROM compliance_rules WHERE company_id = ? AND active = 1');
        $rulesStmt->execute([$companyId]);
        $rules = $rulesStmt->fetchAll();

        $model = OPENROUTER_COMPLIANCE_MODEL;

        $violations = [];
        if (!empty($rules)) {
            $rulesText = self::formatRules($rules);
            $userPrompt = "BUSINESS RULES:\n{$rulesText}\n\nTRANSCRIPT:\n{$transcriptText}";
            $result = OpenRouterClient::chatJson(self::SYSTEM_PROMPT, $userPrompt, $model);
            $violations = $result['violations'] ?? [];
        }

        $rulesByName = [];
        foreach ($rules as $r) {
            $rulesByName[$r['rule_name']] = $r;
        }

        $overallStatus = 'compliant';
        if (count($violations) > 0) {
            $hasHighOrCritical = false;
            foreach ($violations as $v) {
                if (in_array(strtolower($v['severity'] ?? ''), ['high', 'critical'], true)) {
                    $hasHighOrCritical = true;
                    break;
                }
            }
            $overallStatus = $hasHighOrCritical ? 'violations_found' : 'minor_issues';
        }

        $reportStmt = $pdo->prepare('INSERT INTO compliance_reports (conversation_id, overall_status, violation_count, model_used) VALUES (?,?,?,?)');
        $reportStmt->execute([$conversationId, $overallStatus, count($violations), $model]);
        $reportId = (int) $pdo->lastInsertId();

        $insertViolation = $pdo->prepare('
            INSERT INTO violations (compliance_report_id, conversation_id, rule_id, rule_name, severity, evidence, explanation, suggested_improvement)
            VALUES (?,?,?,?,?,?,?,?)
        ');
        foreach ($violations as $v) {
            $ruleName = $v['rule_name'] ?? 'Unspecified rule';
            $rule = $rulesByName[$ruleName] ?? null;
            $severity = self::normalizeSeverity($v['severity'] ?? ($rule['severity_default'] ?? 'medium'));
            $insertViolation->execute([
                $reportId,
                $conversationId,
                $rule['id'] ?? null,
                $ruleName,
                $severity,
                $v['evidence'] ?? null,
                $v['explanation'] ?? null,
                $v['suggested_improvement'] ?? null,
            ]);
        }

        return ['report_id' => $reportId, 'overall_status' => $overallStatus, 'violations' => $violations];
    }

    private static function formatRules(array $rules): string
    {
        $lines = [];
        foreach ($rules as $r) {
            $line = "- [{$r['rule_name']}] ({$r['category']}, default severity: {$r['severity_default']}): {$r['description']}";
            $prohibited = json_decode($r['prohibited_words'] ?? '[]', true) ?: [];
            $required = json_decode($r['required_phrases'] ?? '[]', true) ?: [];
            if (!empty($prohibited)) {
                $line .= ' Prohibited terms: ' . implode(', ', $prohibited) . '.';
            }
            if (!empty($required)) {
                $line .= ' Expected phrases (when applicable): ' . implode(', ', $required) . '.';
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function normalizeSeverity(?string $value): string
    {
        $allowed = ['low', 'medium', 'high', 'critical'];
        $value = strtolower((string) $value);
        return in_array($value, $allowed, true) ? $value : 'medium';
    }
}
