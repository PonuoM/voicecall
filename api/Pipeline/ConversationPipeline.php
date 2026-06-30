<?php

require_once __DIR__ . '/../Agents/SttAgent.php';
require_once __DIR__ . '/../Agents/ConversationUnderstandingAgent.php';
require_once __DIR__ . '/../Agents/EntityExtractionAgent.php';
require_once __DIR__ . '/../Agents/ComplianceAgent.php';
require_once __DIR__ . '/../Agents/KnowledgeIndexerAgent.php';

/**
 * Runs the full 5-stage agent pipeline against one conversation, persisting status as it goes
 * so the frontend can poll progress. Agent 6 (AssistantAgent) is queried on-demand, not run here.
 */
class ConversationPipeline
{
    public static function run(PDO $pdo, PDO $erp, int $conversationId): array
    {
        $conversation = self::loadConversation($pdo, $conversationId);

        try {
            self::clearPriorOutput($pdo, $conversationId);

            self::setStatus($pdo, $conversationId, 'transcribing');
            $transcript = SttAgent::run($pdo, $conversation);
            $transcriptText = $transcript['full_text'];

            if (trim($transcriptText) === '') {
                throw new RuntimeException('Transcription produced no text (silent or unsupported audio?)');
            }

            self::setStatus($pdo, $conversationId, 'analyzing');
            ConversationUnderstandingAgent::run($pdo, $conversationId, $transcriptText);

            self::setStatus($pdo, $conversationId, 'extracting');
            EntityExtractionAgent::run($pdo, $erp, $conversationId, $transcriptText);

            self::setStatus($pdo, $conversationId, 'checking_compliance');
            ComplianceAgent::run($pdo, $conversationId, (int) $conversation['company_id'], $transcriptText);

            self::setStatus($pdo, $conversationId, 'indexing');
            KnowledgeIndexerAgent::run($pdo, $conversationId, $transcriptText);

            self::setStatus($pdo, $conversationId, 'completed');

            return ['ok' => true, 'conversation_id' => $conversationId, 'status' => 'completed', 'transcript' => $transcript];
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE conversations SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['failed', $e->getMessage(), $conversationId]);
            file_put_contents(LOG_DIR . '/pipeline_error.log', date('Y-m-d H:i:s') . " conversation={$conversationId} " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
            return ['ok' => false, 'conversation_id' => $conversationId, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private static function loadConversation(PDO $pdo, int $conversationId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM conversations WHERE id = ?');
        $stmt->execute([$conversationId]);
        $conversation = $stmt->fetch();
        if (!$conversation) {
            throw new RuntimeException("Conversation {$conversationId} not found");
        }
        return $conversation;
    }

    private static function setStatus(PDO $pdo, int $conversationId, string $status): void
    {
        $pdo->prepare('UPDATE conversations SET status = ?, error_message = NULL WHERE id = ?')->execute([$status, $conversationId]);
    }

    /**
     * Reprocessing (POST .../process on an already-completed conversation) must be able to run
     * clean — delete every previous agent output for this conversation first so the unique
     * constraints (one transcript/summary/entities/compliance-report row per conversation) don't
     * block re-insertion. transcript_segments/speakers cascade from transcripts/conversations.
     */
    private static function clearPriorOutput(PDO $pdo, int $conversationId): void
    {
        foreach (['transcripts', 'summaries', 'keywords', 'action_items', 'conversation_tags', 'extracted_entities', 'compliance_reports', 'knowledge_chunks', 'speakers'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE conversation_id = ?")->execute([$conversationId]);
        }
    }
}
