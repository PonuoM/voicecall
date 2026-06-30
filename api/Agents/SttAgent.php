<?php

require_once __DIR__ . '/../Services/AudioFetcher.php';
require_once __DIR__ . '/../Services/OpenRouterClient.php';

/**
 * Agent 1: Speech-to-Text.
 *
 * OpenRouter's /audio/transcriptions endpoint (used here so the whole pipeline runs on one API
 * key) returns only {text, usage} — no per-segment timestamps, unlike calling OpenAI's Whisper
 * API directly with response_format=verbose_json. So speaker-turn inference works from the
 * plain transcript text alone: the LLM splits it into an ordered list of dialogue turns and
 * labels each with speaker_1/speaker_2 + a customer/employee/unknown role. transcript_segments
 * stores those turns in order (sequence column) with start_time/end_time left NULL — there is no
 * real timing data to put there. If this ever moves to a provider with native diarization and
 * timestamps, replace inferSpeakerTurns() and populate those columns for real.
 */
class SttAgent
{
    public static function run(PDO $pdo, array $conversation): array
    {
        $conversationId = (int) $conversation['id'];

        $audioPath = AudioFetcher::fetchToPcmWav($conversationId, $conversation['source'], $conversation['audio_ref']);

        try {
            $result = OpenRouterClient::transcribeViaChatAudio($audioPath, OPENROUTER_STT_MODEL, 'th');
        } finally {
            @unlink($audioPath); // don't keep decoded PCM around once STT has it
        }

        $fullText = trim($result['text'] ?? '');
        $duration = $result['duration'] ?? null;

        $stmt = $pdo->prepare('INSERT INTO transcripts (conversation_id, full_text, language, word_count) VALUES (?,?,?,?)');
        $stmt->execute([$conversationId, $fullText, null, str_word_count($fullText)]);
        $transcriptId = (int) $pdo->lastInsertId();

        $turns = self::inferSpeakerTurns($fullText, $conversation);

        $seq = 0;
        $rolesByLabel = [];
        $insertSeg = $pdo->prepare('INSERT INTO transcript_segments (transcript_id, conversation_id, sequence, speaker_label, start_time, end_time, text) VALUES (?,?,?,?,NULL,NULL,?)');
        foreach ($turns as $turn) {
            $insertSeg->execute([
                $transcriptId,
                $conversationId,
                $seq++,
                $turn['speaker_label'],
                $turn['text'],
            ]);
            $rolesByLabel[$turn['speaker_label']] = $turn['role'] ?? 'unknown';
        }

        $insertSpeaker = $pdo->prepare('INSERT INTO speakers (conversation_id, speaker_label, role) VALUES (?,?,?)');
        foreach ($rolesByLabel as $label => $role) {
            $insertSpeaker->execute([$conversationId, $label, $role]);
        }

        $pdo->prepare('UPDATE conversations SET duration_seconds = ? WHERE id = ?')
            ->execute([$duration, $conversationId]);

        return [
            'transcript_id' => $transcriptId,
            'full_text' => $fullText,
            'turn_count' => count($turns),
        ];
    }

    /**
     * @return array<array{speaker_label:string,role:string,text:string}>
     */
    private static function inferSpeakerTurns(string $fullText, array $conversation): array
    {
        $fullText = trim($fullText);
        if ($fullText === '') {
            return [];
        }

        // Real, observed failure: with zero acoustic/diarization signal, a small model guessing
        // employee-vs-customer from short ambiguous turns ("ค่ะ", "ใช่", "ครับ") gets it backwards
        // often enough that a user listening to the actual audio caught it. These two context
        // clues are free (already known before STT even runs) and give the model something
        // concrete to anchor on instead of guessing from tone alone.
        $directionHint = $conversation['direction'] === 'OUT'
            ? 'This call is OUTBOUND: the employee dialed the customer. Whoever ANSWERS a ringing phone speaks first, almost always just a bare "hello"/"yes" - so the very first short utterance is most likely the CUSTOMER answering, and the employee speaks next with the substantive opening (identifying themselves/the company, stating why they called).'
            : ($conversation['direction'] === 'IN'
                ? 'This call is INBOUND: the customer dialed the employee. Whoever ANSWERS a ringing phone speaks first, almost always just a bare "hello"/"yes" - so the very first short utterance is most likely the EMPLOYEE answering, and the customer speaks next with the substantive opening (stating their question/request).'
                : '');
        $nameHints = [];
        if (!empty($conversation['erp_employee_name'])) {
            $nameHints[] = "The employee's real name (from caller-ID lookup, NOT necessarily said aloud) is \"{$conversation['erp_employee_name']}\".";
        }
        if (!empty($conversation['erp_customer_name'])) {
            $nameHints[] = "The customer's real name (from caller-ID lookup, NOT necessarily said aloud) is \"{$conversation['erp_customer_name']}\".";
        }
        $nameHintText = $nameHints ? ("\n" . implode(' ', $nameHints)
            . ' If a turn addresses someone by one of these names (e.g. calling out to them, asking "is this so-and-so?"), the speaker of that turn is talking TO that person, not AS them - in Thai telesales calls people very often open by stating the other party\'s name to confirm they reached the right person.') : '';

        $prompt = "Below is a plain-text transcript of a business phone call (no speaker markers, no timestamps - "
            . "it came from an STT engine that only returns continuous text). Split it into an ordered list of "
            . "speaker turns. Use only \"speaker_1\" and \"speaker_2\" as labels (assume two-party conversation "
            . "unless there is clear evidence of a third party). Classify each turn's speaker_label as \"employee\" "
            . "(sales/service agent - greets, identifies the company, offers products/services, explains policy) or "
            . "\"customer\" (asks questions, makes requests/complaints, reports their own situation, responds to "
            . "offers) or \"unknown\" if unclear. {$directionHint}{$nameHintText} Preserve the original wording "
            . "verbatim per turn - do not summarize or translate.\n\n"
            . "TRANSCRIPT:\n{$fullText}\n\n"
            . "Return JSON: {\"turns\": [{\"speaker_label\": \"speaker_1\", \"role\": \"employee\", \"text\": \"...\"}, ...]}";

        try {
            // Stronger model than the agent default (flash-lite) - this task has to track who's
            // who across an entire unsegmented transcript with no acoustic signal, the same
            // reasoning that justified a stronger model for compliance checking applies here.
            $resp = OpenRouterClient::chatJson(
                'You are a precise conversation-analysis assistant. Output strict JSON only.',
                $prompt,
                OPENROUTER_COMPLIANCE_MODEL
            );
            $turns = $resp['turns'] ?? [];
        } catch (Throwable $e) {
            $turns = [];
        }

        if (empty($turns)) {
            // Fallback: no turn split available - keep the whole transcript as one unattributed turn
            // rather than silently dropping it.
            return [['speaker_label' => 'speaker_1', 'role' => 'unknown', 'text' => $fullText]];
        }

        $result = [];
        foreach ($turns as $turn) {
            $text = trim((string) ($turn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $label = ($turn['speaker_label'] ?? 'speaker_1') === 'speaker_2' ? 'speaker_2' : 'speaker_1';
            $role = in_array($turn['role'] ?? null, ['employee', 'customer'], true) ? $turn['role'] : 'unknown';
            $result[] = ['speaker_label' => $label, 'role' => $role, 'text' => $text];
        }
        return $result;
    }
}
