<?php

require_once __DIR__ . '/../Services/OpenRouterClient.php';

/**
 * Agent 5: Knowledge Base Indexer.
 *
 * Chunks the transcript and stores embeddings (via OpenRouter's /embeddings endpoint) alongside
 * the text so Agent 6 can do retrieval-augmented Q&A. MySQL has no native vector index, so
 * similarity search is done in PHP (cosine similarity over JSON-encoded float arrays) by
 * AssistantAgent. That's fine at the scale of a single company's call volume; if the corpus
 * grows into the millions of chunks, swap knowledge_chunks for a real vector store (pgvector,
 * Qdrant, etc.) without changing any other agent.
 */
class KnowledgeIndexerAgent
{
    private const CHUNK_SIZE_CHARS = 1000;
    private const CHUNK_OVERLAP_CHARS = 150;

    public static function run(PDO $pdo, int $conversationId, string $transcriptText): array
    {
        $chunks = self::chunkText($transcriptText);
        if (empty($chunks)) {
            return ['chunks_indexed' => 0];
        }

        $embeddings = OpenRouterClient::embedBatch($chunks);

        $insert = $pdo->prepare('INSERT INTO knowledge_chunks (conversation_id, chunk_index, chunk_text, embedding, embedding_model, token_count) VALUES (?,?,?,?,?,?)');
        foreach ($chunks as $i => $chunkText) {
            $embedding = $embeddings[$i] ?? null;
            $insert->execute([
                $conversationId,
                $i,
                $chunkText,
                $embedding ? json_encode($embedding) : null,
                $embedding ? OPENROUTER_EMBEDDING_MODEL : null,
                (int) (mb_strlen($chunkText) / 4), // rough token estimate
            ]);
        }

        return ['chunks_indexed' => count($chunks)];
    }

    /**
     * Must use mb_* functions throughout, not strlen/substr — these transcripts are Thai
     * (multi-byte UTF-8). A byte-offset cut can slice a character in half, producing invalid
     * UTF-8 that silently breaks json_encode() (returns false) when the chunk is later sent to
     * the embeddings API, which OpenRouter then rejects as "JSON parsing failed".
     */
    private static function chunkText(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        if (mb_strlen($text) <= self::CHUNK_SIZE_CHARS) {
            return [$text];
        }

        $chunks = [];
        $start = 0;
        $len = mb_strlen($text);
        while ($start < $len) {
            $end = min($start + self::CHUNK_SIZE_CHARS, $len);
            // try to break on a sentence/paragraph boundary near the end
            if ($end < $len) {
                $window = mb_substr($text, $start, $end - $start);
                $boundary = mb_strrpos($window, "\n");
                if ($boundary === false) {
                    $boundary = mb_strrpos($window, '. ');
                }
                if ($boundary !== false && $boundary > self::CHUNK_SIZE_CHARS * 0.5) {
                    $end = $start + $boundary + 1;
                }
            }
            $chunks[] = trim(mb_substr($text, $start, $end - $start));
            if ($end >= $len) {
                break;
            }
            $start = max($end - self::CHUNK_OVERLAP_CHARS, $start + 1);
        }
        return array_values(array_filter($chunks, function ($c) {
            return $c !== '';
        }));
    }
}
