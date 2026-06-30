<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';

/** Agent 6: AI Assistant - RAG search + natural-language Q&A over the knowledge base. */
class AssistantAgent
{
    private const TOP_K = 8;

    private const SYSTEM_PROMPT = <<<PROMPT
You are an internal AI assistant for a contact-center voice intelligence platform. Answer the
user's question using ONLY the provided conversation excerpts as evidence. Each excerpt is tagged
with its conversation_id and date. If the excerpts don't contain enough information to answer,
say so plainly - do not fabricate. Cite conversation_id(s) you used in your answer. Answer in the
same language the question was asked in (Thai question -> Thai answer, English question -> English answer).
PROMPT;

    public static function answer(PDO $pdo, int $companyId, string $question, ?int $erpUserId = null): array
    {
        $sources = self::retrieve($pdo, $companyId, $question);

        if (empty($sources)) {
            $answer = 'ไม่พบข้อมูลที่เกี่ยวข้องในฐานความรู้สำหรับคำถามนี้ / No relevant conversations found in the knowledge base for this question.';
        } else {
            $context = self::formatContext($sources);
            $answer = OpenRouterClient::chat(self::SYSTEM_PROMPT, "CONTEXT EXCERPTS:\n{$context}\n\nQUESTION:\n{$question}");
        }

        $sourceSummaries = array_map(function ($s) {
            return [
                'conversation_id' => $s['conversation_id'],
                'similarity' => round($s['similarity'], 4),
                'excerpt' => mb_substr($s['chunk_text'], 0, 200),
            ];
        }, $sources);

        $stmt = $pdo->prepare('INSERT INTO assistant_queries (company_id, erp_user_id, question, answer, sources) VALUES (?,?,?,?,?)');
        $stmt->execute([$companyId, $erpUserId, $question, $answer, json_encode($sourceSummaries, JSON_UNESCAPED_UNICODE)]);

        return ['answer' => $answer, 'sources' => $sourceSummaries];
    }

    /**
     * Naive but correct RAG retrieval: embed the question, pull every chunk
     * belonging to this company, rank by cosine similarity in PHP. See the
     * note in KnowledgeIndexerAgent about swapping to a vector DB at scale.
     */
    private static function retrieve(PDO $pdo, int $companyId, string $question): array
    {
        $stmt = $pdo->prepare('
            SELECT kc.id, kc.conversation_id, kc.chunk_text, kc.embedding
            FROM knowledge_chunks kc
            JOIN conversations c ON c.id = kc.conversation_id
            WHERE c.company_id = ? AND kc.embedding IS NOT NULL
        ');
        $stmt->execute([$companyId]);
        $rows = $stmt->fetchAll();
        if (empty($rows)) {
            return [];
        }

        $questionEmbedding = OpenRouterClient::embedBatch([$question])[0] ?? null;
        if (!$questionEmbedding) {
            return [];
        }

        foreach ($rows as &$row) {
            $vec = json_decode($row['embedding'], true);
            $row['similarity'] = is_array($vec) ? self::cosineSimilarity($questionEmbedding, $vec) : -1;
        }
        unset($row);

        usort($rows, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        return array_slice($rows, 0, self::TOP_K);
    }

    private static function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private static function formatContext(array $sources): string
    {
        $lines = [];
        foreach ($sources as $s) {
            $lines[] = "[conversation_id={$s['conversation_id']}] {$s['chunk_text']}";
        }
        return implode("\n---\n", $lines);
    }
}
