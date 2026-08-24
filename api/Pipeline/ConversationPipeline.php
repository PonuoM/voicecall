<?php

require_once __DIR__ . '/../Agents/SttAgent.php';
require_once __DIR__ . '/../Services/AudioSkipped.php';
require_once __DIR__ . '/../Services/AudioUnavailable.php';
require_once __DIR__ . '/../Services/WorkDeferred.php';
require_once __DIR__ . '/../Agents/UnifiedPipelineAgent.php';
require_once __DIR__ . '/../Agents/KnowledgeIndexerAgent.php';
require_once __DIR__ . '/../Services/ErpWebhookService.php';

/**
 * Runs the full 5-stage agent pipeline against one conversation, persisting status as it goes
 * so the frontend can poll progress. Agent 6 (AssistantAgent) is queried on-demand, not run here.
 */
class ConversationPipeline
{
    public static function run(PDO $pdo, PDO $erp, int $conversationId): array
    {
        // config.php's 300s default is a lightweight-request safety net, not a budget for this
        // pipeline - a single long call (e.g. 27 min) can legitimately need the STT step alone to
        // run longer than that (see the scaled timeout in OpenRouterClient::transcribeViaChatAudio).
        // This is a user-initiated, one-conversation action, so letting it run as long as it
        // genuinely needs is the right tradeoff (same reasoning the cron scripts already use).
        set_time_limit(0);

        $conversation = self::loadConversation($pdo, $conversationId);

        try {
            self::clearPriorOutput($pdo, $conversationId);

            self::setStatus($pdo, $conversationId, 'transcribing');
            $transcript = SttAgent::run($pdo, $conversation);
            $transcriptText = $transcript['full_text'];

            if (trim($transcriptText) === '') {
                // Typhoon returning nothing is the correct answer for audio with no intelligible
                // speech — it is a transducer and cannot invent one. That is a decision, not a
                // breakage, so it must not land in the same bucket as a provider timeout.
                throw new AudioSkipped('ถอดเสียงแล้วไม่มีคำพูด (เงียบ หรือเป็นเสียงรบกวน) — ข้ามตามกฎ');
            }

            // 'analyzing', not 'analyzing_unified'. The latter is not in the status enum, and MySQL
            // in non-strict mode answers an invalid enum value by storing the empty string — so
            // every conversation passed through a status that existed nowhere in the schema. It was
            // invisible while the pipeline kept moving, and left conversations 102 and 182 parked at
            // '' when it did not: not 'pending', so nothing retried them, and not any state a person
            // could look up.
            self::setStatus($pdo, $conversationId, 'analyzing');
            $externalContext = json_decode($conversation['external_context'] ?? 'null', true);
            $contextString = $externalContext ? json_encode($externalContext, JSON_UNESCAPED_UNICODE) : null;
            
            UnifiedPipelineAgent::run($pdo, $erp, $conversationId, (int) $conversation['company_id'], $transcriptText, $contextString);

            self::setStatus($pdo, $conversationId, 'indexing');
            KnowledgeIndexerAgent::run($pdo, $conversationId, $transcriptText);

            self::setStatus($pdo, $conversationId, 'completed');

            // The ERP is waiting on this call synchronously (ErpController) and also wants to be
            // told out-of-band, so the webhook fires before the return either way.
            ErpWebhookService::sendSummary($pdo, $conversationId);

            require_once __DIR__ . '/../Services/ConversationDataService.php';
            $detail = ConversationDataService::getFullDetail($pdo, $conversationId);
            $detail['ok'] = true;
            $detail['status'] = 'completed';

            return $detail;
        } catch (AudioSkipped $e) {
            // A terminal state like any other: the recording has been dealt with and will not come
            // back round. Logged at a lower volume than a failure because on this corpus roughly a
            // third of recordings legitimately end here.
            $pdo->prepare('UPDATE conversations SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['skipped', $e->getMessage(), $conversationId]);
            return ['ok' => true, 'conversation_id' => $conversationId, 'status' => 'skipped', 'reason' => $e->getMessage()];
        } catch (WorkDeferred $e) {
            // The opposite of AudioSkipped: nothing was decided about this recording, something
            // outside it got in the way. Back to 'pending', because 'failed' is terminal - nothing
            // in the system retries it - and using it for an obstacle that lifts in hours is what
            // destroyed 148 conversations in the 18-19 Aug 2026 Drive IP block and another 1,509
            // in the MiniMax quota exhaustion the same evening. AudioUnavailable is a subclass, so
            // this one catch covers "could not fetch the audio" and "the provider fell over" alike.
            // The message is still recorded so the reason is visible while the row waits.
            $pdo->prepare('UPDATE conversations SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['pending', $e->getMessage(), $conversationId]);
            return ['ok' => false, 'conversation_id' => $conversationId, 'status' => 'deferred', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE conversations SET status = ?, error_message = ? WHERE id = ?')
                ->execute(['failed', $e->getMessage(), $conversationId]);
            file_put_contents(LOG_DIR . '/pipeline_error.log', date('Y-m-d H:i:s') . " conversation={$conversationId} " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n", FILE_APPEND);
            
            // Notify ERP that the AI process failed
            ErpWebhookService::sendError($pdo, $conversationId, 'PIPELINE_FAILED', $e->getMessage());

            return ['ok' => false, 'conversation_id' => $conversationId, 'status' => 'failed', 'error' => $e->getMessage()];
        } finally {
            // In a finally, not on the success path: a conversation that died at the analysis step
            // spent every token the successful ones did, and the expensive outages are exactly the
            // ones worth being able to price afterwards.
            self::recordUsage($pdo, $conversationId, (int) ($conversation['company_id'] ?? 0));
        }
    }

    /**
     * Persists what the model providers reported spending on this conversation.
     *
     * Wrapped in its own try/catch because accounting must never be able to fail a conversation
     * that otherwise finished: a missing llm_usage table on an environment that has not run
     * migrations yet should cost a log line, not a recording.
     */
    private static function recordUsage(PDO $pdo, int $conversationId, int $companyId): void
    {
        try {
            $rows = OpenRouterClient::takeUsage();
            if (!$rows) {
                return;
            }
            $stmt = $pdo->prepare('INSERT INTO llm_usage
                (conversation_id, company_id, endpoint, model, calls, prompt_tokens, cached_tokens,
                 completion_tokens, total_tokens, seconds)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($rows as $r) {
                $stmt->execute([
                    $conversationId, $companyId ?: null, $r['endpoint'], $r['model'], $r['calls'],
                    $r['prompt_tokens'], $r['cached_tokens'], $r['completion_tokens'],
                    $r['total_tokens'], round($r['seconds'], 2),
                ]);
            }
        } catch (Throwable $e) {
            file_put_contents(LOG_DIR . '/usage_error.log',
                date('Y-m-d H:i:s') . " conversation={$conversationId} " . $e->getMessage() . "\n", FILE_APPEND);
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

        // fraud_checks is deliberately not in that list. Its rows are not just agent output — a
        // reviewer's verdict lives in the same row (review_status/reviewed_by/review_note/
        // reviewed_at), so wiping the table on reprocess destroyed the human decision and the
        // evidence quote it was based on. Only never-reviewed rows are cleared; anything a person
        // has already confirmed or dismissed is kept as the audit record of that call.
        $pdo->prepare("DELETE FROM fraud_checks WHERE conversation_id = ? AND review_status = 'pending'")
            ->execute([$conversationId]);
    }
}
