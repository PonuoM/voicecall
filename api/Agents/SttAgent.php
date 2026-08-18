<?php

require_once __DIR__ . '/../Services/AudioFetcher.php';
require_once __DIR__ . '/../Services/AudioQuality.php';
require_once __DIR__ . '/../Services/AudioSkipped.php';
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
            // Gate 1, before anything is paid for. Roughly a third of these recordings carry no
            // speech at all, and the model answers silence by inventing a conversation rather than
            // returning nothing — so this has to run before the request, not after it.
            $audio = AudioQuality::measure($audioPath);
            $rejection = AudioQuality::rejectionReason($audio);
            if ($rejection !== null) {
                // Declined, not broken — see AudioSkipped.
                throw new AudioSkipped($rejection);
            }

            // Two different wire formats, picked by where transcription is pointed. The
            // self-hosted Typhoon service speaks the standard OpenAI multipart contract; OpenRouter
            // needs the audio base64'd inside a chat/completions body, because its own
            // /audio/transcriptions only proxies OpenAI's audio models. Sending either shape to the
            // other endpoint fails outright, so this is not a preference — it has to match.
            $result = OpenRouterClient::sttIsSelfHosted()
                ? OpenRouterClient::transcribeMultipart($audioPath, 'th')
                : OpenRouterClient::transcribeViaChatAudio($audioPath, null, 'th');
        } finally {
            @unlink($audioPath); // don't keep decoded PCM around once STT has it
        }

        $fullText = trim($result['text'] ?? '');
        // Duration measured from the decoded PCM, not whatever the STT response reported. It is the
        // denominator of the loop check below, and the provider's value is frequently absent — 5 of
        // 46 sampled conversations carry duration_seconds = 0, which would make any rate check
        // divide by zero and pass everything through.
        $duration = $audio['seconds'] > 0 ? $audio['seconds'] : ($result['duration'] ?? null);

        // Gate 2, before the transcript is stored. A loop that reaches the database does not just
        // sit there: the pipeline feeds it to the analysis agent, which bills for it a second time
        // as input and then writes summaries, entities and fraud checks derived from text nobody
        // ever said.
        $loop = AudioQuality::loopReason($fullText, (float) $duration);
        if ($loop !== null) {
            throw new AudioSkipped($loop);
        }

        $stmt = $pdo->prepare('INSERT INTO transcripts (conversation_id, full_text, language, word_count) VALUES (?,?,?,?)');
        $stmt->execute([$conversationId, $fullText, null, str_word_count($fullText)]);
        $transcriptId = (int) $pdo->lastInsertId();

        $turns = self::inferSpeakerTurns($fullText, $conversation);
        self::persistTurns($pdo, $conversationId, $transcriptId, $turns);

        $pdo->prepare('UPDATE conversations SET duration_seconds = ? WHERE id = ?')
            ->execute([$duration, $conversationId]);

        return [
            'transcript_id' => $transcriptId,
            'full_text' => $fullText,
            'turn_count' => count($turns),
        ];
    }

    /**
     * Re-splits an already-transcribed call without touching audio or the transcript text itself -
     * for calls whose turn split degenerated to one block (the pre-chunking hang) even though the
     * transcript underneath was always fine. Replaces transcript_segments/speakers for this
     * conversation; leaves everything else (summaries, fraud checks, etc.) untouched.
     */
    public static function resplitExistingTranscript(PDO $pdo, array $conversation, int $transcriptId, string $fullText): array
    {
        $conversationId = (int) $conversation['id'];
        $turns = self::inferSpeakerTurns($fullText, $conversation);
        self::persistTurns($pdo, $conversationId, $transcriptId, $turns);
        return ['transcript_id' => $transcriptId, 'turn_count' => count($turns)];
    }

    private static function persistTurns(PDO $pdo, int $conversationId, int $transcriptId, array $turns): void
    {
        $pdo->prepare('DELETE FROM transcript_segments WHERE conversation_id = ?')->execute([$conversationId]);
        $pdo->prepare('DELETE FROM speakers WHERE conversation_id = ?')->execute([$conversationId]);

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
    }

    /**
     * A model call that has to regenerate the whole transcript back out (verbatim, per turn) inside
     * JSON scales its own output with input length - and on a real 4,096-character call, MiniMax
     * never returned at all: the request timed out at 600s with zero bytes received, not a slow
     * reply, a stuck one. The analysis call on the same transcript answers in about a minute
     * because it only has to produce a short summary, not echo the call back. Capping how much
     * verbatim text any single call must reproduce keeps every call in that same fast regime
     * regardless of how long the phone call itself was.
     */
    private const TURN_SPLIT_CHUNK_CHARS = 1200;

    /**
     * @return array<array{speaker_label:string,role:string,text:string}>
     */
    private static function inferSpeakerTurns(string $fullText, array $conversation): array
    {
        $fullText = trim($fullText);
        if ($fullText === '') {
            return [];
        }

        $chunks = self::splitTranscriptIntoChunks($fullText, self::TURN_SPLIT_CHUNK_CHARS);

        $result = [];
        $lastRole = null;
        foreach ($chunks as $i => $chunk) {
            $turns = self::inferTurnsForChunk($chunk, $conversation, $i === 0, $lastRole, (int) $conversation['id']);
            foreach ($turns as $turn) {
                $result[] = $turn;
                $lastRole = $turn['role'];
            }
        }
        return $result;
    }

    /**
     * Splits on whitespace near the target size when the transcript has any (STT output
     * occasionally leaves gaps at pauses); otherwise cuts at the character limit outright. Thai is
     * written unspaced, so a mid-word cut here costs the model at most one word of context at a
     * seam it already has to guess across - the same tradeoff already accepted where audio itself
     * is chunked (see the 30-second-window fix in the ASR service).
     */
    private static function splitTranscriptIntoChunks(string $text, int $limit): array
    {
        $len = mb_strlen($text);
        if ($len <= $limit) {
            return [$text];
        }

        $chunks = [];
        $pos = 0;
        while ($pos < $len) {
            $end = min($pos + $limit, $len);
            if ($end < $len) {
                $window = mb_substr($text, $pos, $limit);
                $lastSpace = mb_strrpos($window, ' ');
                if ($lastSpace !== false && $lastSpace > $limit * 0.5) {
                    $end = $pos + $lastSpace + 1;
                }
            }
            $chunks[] = trim(mb_substr($text, $pos, $end - $pos));
            $pos = $end;
        }
        return array_values(array_filter($chunks, function ($c) {
            return $c !== '';
        }));
    }

    /**
     * @return array<array{speaker_label:string,role:string,text:string}>
     */
    private static function inferTurnsForChunk(string $chunk, array $conversation, bool $isFirstChunk, ?string $lastRole, int $conversationId): array
    {
        // Real, observed failure: with zero acoustic/diarization signal, a small model guessing
        // employee-vs-customer from short ambiguous turns ("ค่ะ", "ใช่", "ครับ") gets it backwards
        // often enough that a user listening to the actual audio caught it. These two context
        // clues are free (already known before STT even runs) and give the model something
        // concrete to anchor on instead of guessing from tone alone.
        if ($isFirstChunk) {
            $continuityHint = $conversation['direction'] === 'OUT'
                ? 'This call is OUTBOUND: the employee dialed the customer. Whoever ANSWERS a ringing phone speaks first, almost always just a bare "hello"/"yes" - so the very first short utterance is most likely the CUSTOMER answering, and the employee speaks next with the substantive opening (identifying themselves/the company, stating why they called).'
                : ($conversation['direction'] === 'IN'
                    ? 'This call is INBOUND: the customer dialed the employee. Whoever ANSWERS a ringing phone speaks first, almost always just a bare "hello"/"yes" - so the very first short utterance is most likely the EMPLOYEE answering, and the customer speaks next with the substantive opening (stating their question/request).'
                    : '');
        } else {
            // Mid-call chunk: there is no "who answers first" signal, but the previous chunk's
            // last speaker is known for free and the conversation almost always alternates.
            $continuityHint = $lastRole && $lastRole !== 'unknown'
                ? "This is a MID-CALL continuation of an ongoing conversation (not the start of the call). The turn immediately before this excerpt was spoken by the {$lastRole}, so this excerpt most likely opens with the other party unless the same speaker is clearly still talking."
                : 'This is a MID-CALL continuation of an ongoing conversation (not the start of the call).';
        }
        $nameHints = [];
        if (!empty($conversation['erp_employee_name'])) {
            $nameHints[] = "The employee's real name (from caller-ID lookup, NOT necessarily said aloud) is \"{$conversation['erp_employee_name']}\".";
        }
        if (!empty($conversation['erp_customer_name'])) {
            $nameHints[] = "The customer's real name (from caller-ID lookup, NOT necessarily said aloud) is \"{$conversation['erp_customer_name']}\".";
        }
        $nameHintText = $nameHints ? ("\n" . implode(' ', $nameHints)
            . ' If a turn addresses someone by one of these names (e.g. calling out to them, asking "is this so-and-so?"), the speaker of that turn is talking TO that person, not AS them - in Thai telesales calls people very often open by stating the other party\'s name to confirm they reached the right person.') : '';

        $prompt = "Below is an excerpt of a plain-text transcript of a business phone call (no speaker markers, "
            . "no timestamps - it came from an STT engine that only returns continuous text). Split it into an "
            . "ordered list of speaker turns. Use only \"speaker_1\" and \"speaker_2\" as labels (assume "
            . "two-party conversation unless there is clear evidence of a third party). Classify each turn's "
            . "speaker_label as \"employee\" (sales/service agent - greets, identifies the company, offers "
            . "products/services, explains policy) or \"customer\" (asks questions, makes requests/complaints, "
            . "reports their own situation, responds to offers) or \"unknown\" if unclear. "
            . "{$continuityHint}{$nameHintText} Preserve the original wording verbatim per turn - do not "
            . "summarize or translate. This excerpt may start or end mid-sentence; that is expected, keep the "
            . "partial wording as-is rather than completing or dropping it.\n\n"
            . "TRANSCRIPT EXCERPT:\n{$chunk}\n\n"
            . "Return JSON: {\"turns\": [{\"speaker_label\": \"speaker_1\", \"role\": \"employee\", \"text\": \"...\"}, ...]}";

        try {
            // Stronger model than the agent default (flash-lite) - this task has to track who's
            // who with no acoustic signal, the same reasoning that justified a stronger model for
            // compliance checking applies here.
            $resp = OpenRouterClient::chatJson(
                'You are a precise conversation-analysis assistant. Output strict JSON only.',
                $prompt,
                OpenRouterClient::complianceModel()
            );
            $turns = $resp['turns'] ?? [];
        } catch (Throwable $e) {
            file_put_contents(
                LOG_DIR . '/turn_split_failure.log',
                date('Y-m-d H:i:s') . " conversation_id={$conversationId} chunk_chars=" . mb_strlen($chunk)
                    . " " . get_class($e) . ": " . $e->getMessage() . "\n",
                FILE_APPEND
            );
            $turns = [];
        }

        if (empty($turns)) {
            // Fallback: no turn split available for this chunk - keep it as one unattributed turn
            // rather than silently dropping it. Scoped to the chunk, not the whole call, so one
            // slow/stuck excerpt no longer degrades an otherwise-clean transcript to a single block.
            return [['speaker_label' => 'speaker_1', 'role' => 'unknown', 'text' => $chunk]];
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
