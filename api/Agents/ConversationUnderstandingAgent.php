<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';

/** Agent 2: Conversation Understanding - summary, topics, sentiment, intent. */
class ConversationUnderstandingAgent
{
    private const SYSTEM_PROMPT = <<<PROMPT
You are an expert conversation analyst for a Thai contact-center voice intelligence platform.
Read the transcript and produce a structured analysis. Be concise and factual - do not invent
information that is not supported by the transcript. Write every free-text field (executive_summary,
key_topics, action_items.description, decisions_made, follow_up_tasks, customer_intent,
important_keywords) in Thai language, regardless of what language the transcript itself uses -
these calls are for Thai-speaking staff. Output strict JSON only, matching this shape:

{
  "executive_summary": "2-4 sentence summary of the whole conversation",
  "key_topics": ["topic1", "topic2"],
  "action_items": [{"description": "...", "owner": "employee|customer|unspecified", "due_date": "YYYY-MM-DD or null"}],
  "decisions_made": ["decision1"],
  "follow_up_tasks": ["task1"],
  "customer_intent": "short phrase describing what the customer wanted",
  "customer_sentiment": "positive|neutral|negative|mixed",
  "important_keywords": ["keyword1", "keyword2"]
}
PROMPT;

    public static function run(PDO $pdo, int $conversationId, string $transcriptText): array
    {
        $result = OpenRouterClient::chatJson(self::SYSTEM_PROMPT, $transcriptText);

        $executiveSummary = $result['executive_summary'] ?? '';
        $keyTopics = $result['key_topics'] ?? [];
        $actionItems = $result['action_items'] ?? [];
        $decisionsMade = $result['decisions_made'] ?? [];
        $followUpTasks = $result['follow_up_tasks'] ?? [];
        $customerIntent = $result['customer_intent'] ?? null;
        $customerSentiment = self::normalizeSentiment($result['customer_sentiment'] ?? null);
        $keywords = $result['important_keywords'] ?? [];

        $stmt = $pdo->prepare('
            INSERT INTO summaries (conversation_id, executive_summary, key_topics, action_items, decisions_made, follow_up_tasks, customer_intent, customer_sentiment, important_keywords)
            VALUES (?,?,?,?,?,?,?,?,?)
        ');
        $stmt->execute([
            $conversationId,
            $executiveSummary,
            json_encode($keyTopics, JSON_UNESCAPED_UNICODE),
            json_encode($actionItems, JSON_UNESCAPED_UNICODE),
            json_encode($decisionsMade, JSON_UNESCAPED_UNICODE),
            json_encode($followUpTasks, JSON_UNESCAPED_UNICODE),
            $customerIntent,
            $customerSentiment,
            json_encode($keywords, JSON_UNESCAPED_UNICODE),
        ]);

        $insertKeyword = $pdo->prepare('INSERT INTO keywords (conversation_id, keyword, weight) VALUES (?,?,1.0)');
        foreach ($keywords as $kw) {
            if (is_string($kw) && $kw !== '') {
                $insertKeyword->execute([$conversationId, $kw]);
            }
        }

        $insertAction = $pdo->prepare('INSERT INTO action_items (conversation_id, description, owner, due_date) VALUES (?,?,?,?)');
        foreach ($actionItems as $item) {
            if (!is_array($item) || empty($item['description'])) {
                continue;
            }
            $dueDate = $item['due_date'] ?? null;
            if ($dueDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                $dueDate = null;
            }
            $insertAction->execute([$conversationId, $item['description'], $item['owner'] ?? null, $dueDate]);
        }

        return $result;
    }

    private static function normalizeSentiment(?string $value): ?string
    {
        $allowed = ['positive', 'neutral', 'negative', 'mixed'];
        $value = strtolower((string) $value);
        return in_array($value, $allowed, true) ? $value : 'neutral';
    }
}
