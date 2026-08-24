<?php

// config.php, not core/bootstrap.php: this is pulled in from inside ConversationPipeline::run(),
// and a require inside a function executes the file in that function's scope - bootstrap's
// top-level `$pdo = db_connect()` would quietly rebind run()'s own $pdo parameter, and the cron
// scripts (which deliberately never load bootstrap) would pick up a second connection and a
// route parse they have no use for. fetch_one()/fetch_all() are all this needs, and they live
// in config.php, which every entry point has already loaded.
require_once __DIR__ . '/../config.php';

class ConversationDataService
{
    /**
     * Fetches all related data for a conversation.
     * Returns null if the conversation doesn't exist.
     */
    public static function getFullDetail(PDO $pdo, int $conversationId): ?array
    {
        $conv = fetch_one($pdo, 'SELECT * FROM conversations WHERE id = ?', [$conversationId]);
        if (!$conv) {
            return null;
        }

        $transcript = fetch_one($pdo, 'SELECT * FROM transcripts WHERE conversation_id = ?', [$conversationId]);
        $segments = fetch_all($pdo, 'SELECT speaker_label, start_time, end_time, text FROM transcript_segments WHERE conversation_id = ? ORDER BY sequence ASC', [$conversationId]);
        $speakers = fetch_all($pdo, 'SELECT speaker_label, role, identified_name FROM speakers WHERE conversation_id = ?', [$conversationId]);
        $summary = fetch_one($pdo, 'SELECT * FROM summaries WHERE conversation_id = ?', [$conversationId]);
        $entities = fetch_one($pdo, 'SELECT * FROM extracted_entities WHERE conversation_id = ?', [$conversationId]);
        $tags = fetch_all($pdo, 'SELECT tag FROM conversation_tags WHERE conversation_id = ?', [$conversationId]);
        $actionItems = fetch_all($pdo, 'SELECT * FROM action_items WHERE conversation_id = ?', [$conversationId]);
        $complianceReport = fetch_one($pdo, 'SELECT * FROM compliance_reports WHERE conversation_id = ?', [$conversationId]);
        $violations = fetch_all($pdo, 'SELECT * FROM violations WHERE conversation_id = ? ORDER BY severity DESC', [$conversationId]);
        $fraudChecks = fetch_all($pdo, "SELECT * FROM fraud_checks WHERE conversation_id = ? ORDER BY FIELD(risk_level,'critical','high','medium','low')", [$conversationId]);

        if ($summary) {
            foreach (['key_topics', 'action_items', 'decisions_made', 'follow_up_tasks', 'important_keywords'] as $field) {
                if (isset($summary[$field])) {
                    $summary[$field] = json_decode($summary[$field], true);
                }
            }
        }
        
        if ($entities) {
            foreach (['order_info', 'appointment_info'] as $field) {
                if (isset($entities[$field])) {
                    $entities[$field] = json_decode($entities[$field], true);
                }
            }
            unset($entities['raw_json']);
        }

        return [
            'conversation' => $conv,
            'transcript' => $transcript,
            'segments' => $segments,
            'speakers' => $speakers,
            'summary' => $summary ?: null,
            'entities' => $entities ?: null,
            'tags' => array_column($tags, 'tag'),
            'action_items' => $actionItems,
            'compliance_report' => $complianceReport ?: null,
            'violations' => $violations,
            'fraud_checks' => $fraudChecks,
        ];
    }
}
