<?php

require_once __DIR__ . '/WorkDeferred.php';
require_once __DIR__ . '/CircuitBreaker.php';

/**
 * Client for every AI capability the pipeline needs: chat completions (all text-reasoning agents),
 * speech-to-text, and embeddings.
 *
 * These used to share one base URL and one key, on the assumption that OpenRouter would serve all
 * three forever. That assumption broke on both ends at once. Transcription is moving to a Typhoon
 * ASR service we run ourselves, because no hosted model would stop inventing dialogue over silent
 * recordings. Analysis is moving to MiniMax, because the subscription already covers it and the
 * per-token bill did not. Embeddings have no reason to move — they cost almost nothing and the
 * MiniMax equivalent speaks a different request shape.
 *
 * So each capability now resolves its own endpoint, and every one of them falls back to the
 * OPENROUTER_* values when its own are unset. A deployment that configures nothing keeps behaving
 * exactly as it does today, and the three can be migrated one at a time rather than in a single
 * cutover.
 */
class OpenRouterClient
{
    /**
     * Endpoint for one capability: ['url' => ..., 'key' => ..., 'model' => ..., 'label' => ...].
     *
     * $prefix is LLM, STT or EMBEDDING. The label is carried through to error messages — with three
     * possible providers behind one client, "API error (401)" is not worth much unless it says
     * which one refused.
     */
    private static function endpoint(string $prefix, string $fallbackModel): array
    {
        $url = getenv($prefix . '_BASE_URL') ?: OPENROUTER_BASE_URL;
        $key = getenv($prefix . '_API_KEY');
        if ($key === false || $key === '') {
            $key = OPENROUTER_API_KEY;
        }
        $model = getenv($prefix . '_MODEL') ?: $fallbackModel;

        if (!$key) {
            throw new RuntimeException("{$prefix}_API_KEY (or OPENROUTER_API_KEY) is not configured");
        }

        return [
            'url' => rtrim($url, '/'),
            'key' => $key,
            'model' => $model,
            'label' => parse_url($url, PHP_URL_HOST) ?: $prefix,
        ];
    }

    /**
     * How long to leave the analysis provider alone once it says the quota is gone.
     *
     * Flat rather than escalating, unlike Drive's. A token plan refills on the provider's clock,
     * not on ours, so doubling the wait after each refusal only guarantees sitting idle long after
     * it came back — the 19 Aug 2026 exhaustion cleared itself in about two hours, and an
     * escalating ladder would still have been waiting. Fifteen minutes costs at most one wasted
     * transcription per quarter hour while the quota is out, and notices the refill within one.
     */
    private const QUOTA_COOLDOWN_SECONDS = 900;

    /**
     * @return int|null Unix timestamp the analysis provider's cooldown ends, or null if it is
     *   usable. Read by the cron workers before they claim a recording — the download and the
     *   transcription are wasted if the analysis call at the end of the pipeline cannot run.
     */
    public static function analysisCircuitOpen(): ?int
    {
        return CircuitBreaker::openUntil(self::endpoint('LLM', OPENROUTER_MODEL)['label']);
    }

    /** Why it is closed — "out of quota" and "key rejected" need different reactions from a human. */
    public static function analysisCircuitReason(): string
    {
        return CircuitBreaker::reasonFor(self::endpoint('LLM', OPENROUTER_MODEL)['label']);
    }
    /**
     * The model to use for the calls that need more care than the default one — compliance
     * judgements and the analysis pass, where flash-lite produced a real false positive.
     *
     * Callers used to name OPENROUTER_COMPLIANCE_MODEL directly. That silently became a bug the
     * moment chat could point somewhere other than OpenRouter: the explicit argument wins over the
     * endpoint's own model, so every analysis went to MiniMax asking for "google/gemini-2.5-flash"
     * and came back "unknown model (2013)". Resolving it here keeps the choice of a stronger model
     * expressed as a role rather than as a provider-specific string.
     */
    public static function complianceModel(): string
    {
        $explicit = getenv('LLM_COMPLIANCE_MODEL');
        if ($explicit !== false && $explicit !== '') {
            return $explicit;
        }
        // If the LLM endpoint has been pointed away from OpenRouter, its own model is the only one
        // guaranteed to exist there — a "stronger" OpenRouter model id would just 400.
        $llmModel = getenv('LLM_MODEL');
        if ($llmModel !== false && $llmModel !== '') {
            return $llmModel;
        }
        return OPENROUTER_COMPLIANCE_MODEL;
    }

    /**
     * How long to wait on a chat completion.
     *
     * Deliberately one number rather than something scaled per request: unlike transcription, where
     * cost tracks audio length, a reasoning model's thinking time is not predictable from the input.
     * Overridable so a slower model does not need a code change.
     */
    private static function chatTimeoutSeconds(): int
    {
        return max(60, (int) (getenv('LLM_TIMEOUT_SECONDS') ?: 600));
    }

    /**
     * @return string Raw text content of the model's reply.
     */
    public static function chat(string $systemPrompt, string $userPrompt, bool $jsonMode = false, ?string $model = null): string
    {
        $endpoint = self::endpoint('LLM', OPENROUTER_MODEL);

        $payload = [
            'model' => $model ?: $endpoint['model'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        // 120 seconds — request()'s default — was written for gemini-2.5-flash-lite, which answers
        // in a few seconds. MiniMax M3 reasons before it replies: the analysis call on a real
        // transcript measured about 100 seconds, and eleven of the twenty-two production failures
        // so far are this timeout rather than anything going wrong. The ceiling is now generous
        // enough that hitting it means genuinely stuck, not merely thinking.
        $response = self::request($endpoint, '/chat/completions', $payload, self::chatTimeoutSeconds());
        $content = $response['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            throw new RuntimeException("{$endpoint['label']} response missing message content: " . json_encode($response));
        }
        return $content;
    }

    /**
     * Same as chat() but decodes and returns the JSON body, retrying once with a
     * corrective instruction if the model returns malformed JSON.
     */
    public static function chatJson(string $systemPrompt, string $userPrompt, ?string $model = null): array
    {
        $raw = self::chat($systemPrompt, $userPrompt, true, $model);
        $decoded = self::extractJson($raw);
        if ($decoded !== null && !self::containsReplacementChar($decoded)) {
            return $decoded;
        }

        // Two different reasons to retry, both real. A reasoning model occasionally wraps the
        // object in prose extractJson() cannot recover, or -- confirmed against production, not
        // theoretical -- emits U+FFFD in place of a real character partway through a long Thai
        // string. 32 of ~238 completed conversations had it in executive_summary alone: "บทสนทนา"
        // came back as "�ทสนทนา" mid-response while the same word appeared correctly written
        // elsewhere in that same response, which is the signature of a token-boundary slip during
        // generation rather than anything wrong with the request. A fresh generation is a fresh roll
        // and, empirically, not the same roll.
        $retryReason = $decoded === null
            ? 'Your previous response was not valid JSON.'
            : 'Your previous response contained the Unicode replacement character (U+FFFD, shown as a black diamond with a question mark) in place of one or more real characters. Regenerate the ENTIRE response with no replacement characters anywhere.';
        $retryPrompt = "{$retryReason} Return ONLY a valid JSON object, no markdown fences, no commentary.\n\nPrevious response:\n{$raw}";
        $raw2 = self::chat($systemPrompt, $retryPrompt, true, $model);
        $decoded2 = self::extractJson($raw2);
        if ($decoded2 !== null && !self::containsReplacementChar($decoded2)) {
            return $decoded2;
        }

        // Neither attempt came back clean. Prefer whichever one actually parsed -- losing one
        // garbled word inside a long summary is not worth discarding an otherwise-complete
        // analysis over, and the alternative (throwing here) means the conversation gets marked
        // failed for a single mangled character. Logged rather than silently accepted, so these
        // rows can be found and reprocessed later instead of being indistinguishable from clean ones.
        $usable = $decoded2 ?? $decoded;
        if ($usable !== null) {
            file_put_contents(
                LOG_DIR . '/replacement_char.log',
                date('Y-m-d H:i:s') . " model={$model} both attempts " . ($decoded2 === null ? 'first-parsed-second-failed' : 'still contain U+FFFD after retry') . "\n",
                FILE_APPEND
            );
            return $usable;
        }

        throw new RuntimeException('OpenRouter did not return valid JSON after retry: ' . substr($raw2, 0, 500));
    }

    /**
     * True if the Unicode replacement character (U+FFFD) appears anywhere in a decoded value --
     * checked recursively since the corruption can land inside any nested string, not only
     * top-level ones.
     */
    private static function containsReplacementChar($value): bool
    {
        if (is_string($value)) {
            return strpos($value, "\u{FFFD}") !== false;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsReplacementChar($item)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * True when transcription has been pointed somewhere other than OpenRouter.
     *
     * The two paths are not interchangeable. OpenRouter wants base64 audio inside a JSON body, at
     * /chat/completions for the multimodal models; the OpenAI contract everyone else implements —
     * including our own Typhoon service — wants a multipart file upload at /audio/transcriptions.
     * Pointing STT_BASE_URL at the latter without switching request shapes just posts a JSON blob
     * to an endpoint that does not exist.
     */
    public static function sttIsSelfHosted(): bool
    {
        $base = getenv('STT_BASE_URL');
        return $base !== false && $base !== '';
    }

    /**
     * Speech-to-text over the standard OpenAI multipart contract: POST /audio/transcriptions with
     * the audio as a real file part. This is what asr-service/ speaks.
     *
     * Duration comes from the WAV header rather than the response, so the loop guard in SttAgent
     * has a denominator even against a provider that reports no timing.
     *
     * @return array{text:string,duration:?float}
     */
    public static function transcribeMultipart(string $audioFilePath, ?string $language = 'th'): array
    {
        $endpoint = self::endpoint('STT', OPENROUTER_STT_MODEL);
        if (!file_exists($audioFilePath)) {
            throw new RuntimeException("Audio file not found: {$audioFilePath}");
        }

        $duration = self::wavDurationSeconds($audioFilePath);
        // Same reasoning as the chat-audio path: a flat timeout either strangles long calls or lets
        // a stuck one hang the pipeline. Typhoon runs about 5x realtime on the target box, so this
        // leaves a wide margin without being unbounded.
        $timeoutSeconds = $duration !== null
            ? (int) max(180, min(1800, 120 + $duration * 2))
            : 600;

        $post = [
            'file' => new CURLFile($audioFilePath, 'audio/wav', basename($audioFilePath)),
            'model' => $endpoint['model'],
        ];
        if ($language) {
            $post['language'] = $language;
        }

        $ch = curl_init($endpoint['url'] . '/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CAINFO => __DIR__ . '/../certs/cacert.pem',
            // No Content-Type header set by hand: curl has to generate the multipart boundary, and
            // supplying the header ourselves would omit it and produce an unparseable body.
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $endpoint['key']],
            CURLOPT_POSTFIELDS => $post,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("{$endpoint['label']} transcription failed: {$curlError}");
        }
        $decoded = json_decode($body, true);
        if ($httpCode >= 400) {
            $message = $decoded['detail'] ?? ($decoded['error']['message'] ?? substr((string) $body, 0, 300));
            throw new RuntimeException("{$endpoint['label']} transcription error ({$httpCode}): {$message}");
        }
        if (!is_array($decoded) || !array_key_exists('text', $decoded)) {
            throw new RuntimeException("{$endpoint['label']} returned no transcript: " . substr((string) $body, 0, 300));
        }

        return [
            'text' => trim((string) $decoded['text']),
            'duration' => $duration ?? (isset($decoded['duration']) ? (float) $decoded['duration'] : null),
        ];
    }

    /**
     * Speech-to-text via OpenRouter's /audio/transcriptions endpoint (base64 JSON body, not
     * multipart). Note: unlike calling OpenAI's Whisper API directly, OpenRouter's STT endpoint
     * returns only {text, usage} — no per-segment timestamps. usage.seconds gives call duration.
     * @return array{text:string,duration:?float}
     */
    public static function transcribe(string $audioFilePath, ?string $language = null): array
    {
        $endpoint = self::endpoint('STT', OPENROUTER_STT_MODEL);
        if (!file_exists($audioFilePath)) {
            throw new RuntimeException("Audio file not found: {$audioFilePath}");
        }

        $payload = [
            'model' => $endpoint['model'],
            'input_audio' => [
                'data' => base64_encode(file_get_contents($audioFilePath)),
                'format' => 'wav',
            ],
        ];
        if ($language) {
            $payload['language'] = $language;
        }

        $response = self::request($endpoint, '/audio/transcriptions', $payload, 300);

        return [
            'text' => $response['text'] ?? '',
            'duration' => isset($response['usage']['seconds']) ? (float) $response['usage']['seconds'] : null,
        ];
    }

    /**
     * Speech-to-text via a regular chat/completions call with audio as multimodal input, instead
     * of the dedicated /audio/transcriptions endpoint above. Needed for non-OpenAI STT models
     * (e.g. google/gemini-2.5-flash) - OpenRouter's /audio/transcriptions endpoint only proxies
     * OpenAI's own audio models (confirmed: requesting any other model there 400s with "model
     * does not exist"), but those models ARE reachable as multimodal chat input. No usage.seconds
     * here (chat completions doesn't report audio duration), so duration is read from the WAV
     * file's own header instead.
     * @return array{text:string,duration:?float}
     */
    /**
     * $model is optional and normally should be left null: the STT endpoint already knows which
     * model it serves. Passing a provider-specific id here is what broke the analysis path when
     * chat moved to MiniMax, and the same trap exists on this side — once STT_BASE_URL points at
     * the self-hosted Typhoon service, "google/gemini-2.5-flash" is not a model it has.
     */
    public static function transcribeViaChatAudio(string $audioFilePath, ?string $model = null, ?string $language = null): array
    {
        $endpoint = self::endpoint('STT', OPENROUTER_STT_MODEL);
        if (!file_exists($audioFilePath)) {
            throw new RuntimeException("Audio file not found: {$audioFilePath}");
        }

        $langHint = $language === 'th' ? ' The audio is a Thai business phone call.' : '';
        $prompt = 'Transcribe this audio verbatim in the language spoken.' . $langHint
            . ' Put each speaker turn on its own line, in chronological order. Output only the'
            . ' transcription text, no commentary, no speaker labels, no timestamps.';

        $payload = [
            'model' => $model ?: $endpoint['model'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'input_audio', 'input_audio' => [
                        'data' => base64_encode(file_get_contents($audioFilePath)),
                        'format' => 'wav',
                    ]],
                ],
            ]],
        ];

        // A flat timeout doesn't scale with what we're asking the model to do: a 27-minute call
        // timed out at exactly the old flat 300s with only 7KB back (real production failure,
        // conversation id 22, 2026-07-21) - the model needs processing time proportional to audio
        // length, not a one-size-fits-all ceiling tuned for short telesales calls. Floor covers
        // normal latency for short calls; cap keeps a genuinely stuck request from hanging the
        // whole pipeline indefinitely.
        $duration = self::wavDurationSeconds($audioFilePath);
        $timeoutSeconds = $duration !== null
            ? (int) max(180, min(900, 120 + $duration * 1.5))
            : 300;

        $response = self::request($endpoint, '/chat/completions', $payload, $timeoutSeconds);
        $text = $response['choices'][0]['message']['content'] ?? '';

        return [
            'text' => trim($text),
            'duration' => $duration,
        ];
    }

    private static function wavDurationSeconds(string $wavPath): ?float
    {
        $size = filesize($wavPath);
        $fh = fopen($wavPath, 'rb');
        if (!$fh || $size === false || $size < 44) {
            if ($fh) {
                fclose($fh);
            }
            return null;
        }
        fseek($fh, 24);
        $sampleRateBytes = fread($fh, 4);
        fclose($fh);
        $sampleRate = unpack('V', $sampleRateBytes)[1] ?? 8000;
        $byteRate = $sampleRate * 2; // 16-bit mono
        $dataBytes = $size - 44;
        return $byteRate > 0 ? round($dataBytes / $byteRate, 2) : null;
    }

    /**
     * @param string[] $texts
     * @return float[][] One embedding vector per input text, same order.
     */
    public static function embedBatch(array $texts): array
    {
        if (empty($texts)) {
            return [];
        }

        // Deliberately left on OpenRouter while chat moves to MiniMax. Embeddings are a rounding
        // error on the bill, and MiniMax's embo-01 takes `texts`/`type` and returns `vectors`
        // rather than OpenAI's `input`/`data[].embedding` — porting it would be new code with a new
        // failure mode in exchange for nothing.
        $endpoint = self::endpoint('EMBEDDING', OPENROUTER_EMBEDDING_MODEL);

        $response = self::request($endpoint, '/embeddings', [
            'model' => $endpoint['model'],
            'input' => $texts,
        ], 120);

        $vectors = [];
        foreach (($response['data'] ?? []) as $item) {
            $vectors[] = $item['embedding'];
        }
        return $vectors;
    }

    /**
     * Pull the JSON object out of a chat response.
     *
     * This used to assume the fence, if any, sat at the very start and end of the message, so it
     * anchored on ^``` and ```$. That holds for gemini-2.5-flash and breaks on reasoning models:
     * MiniMax M3 and M2.7 both open with a <think> block and only then emit ```json … ```, which
     * left the anchors unmatched, the fence in the string, and every analysis failing to parse even
     * though the model had answered correctly.
     *
     * Three passes, cheapest first: strip reasoning, take a fenced block from anywhere in the text,
     * and failing that take the outermost braces. Note that json_decode() on the outermost-brace
     * slice is what rejects a malformed candidate — the brace search itself cannot tell prose
     * containing braces from real JSON.
     */
    private static function extractJson(string $raw): ?array
    {
        $text = trim($raw);

        // Reasoning traces. The closing tag is optional on purpose: a response truncated mid-thought
        // has an opening tag and no close, and dropping everything up to the first fence or brace is
        // still the right move.
        $text = preg_replace('/<think>.*?<\/think>/is', '', $text);
        $text = preg_replace('/^.*?<think>/is', '', $text);
        $text = trim((string) $text);

        $candidates = [$text];

        // ```json … ``` anywhere in the message, not only wrapping the whole of it.
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/is', $text, $fenced)) {
            array_unshift($candidates, $fenced[1]);
        }

        // Outermost braces, for models that emit a short preamble and no fence at all.
        $first = strpos($text, '{');
        $last = strrpos($text, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $candidates[] = substr($text, $first, $last - $first + 1);
        }

        foreach ($candidates as $candidate) {
            $decoded = json_decode(trim($candidate), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Account/key usage from GET /key: usage (all-time USD for this key), usage_daily,
     * usage_weekly, usage_monthly. Powers the cost dashboard and the smart-sampling
     * budget guard (usage_daily resets at UTC midnight — ~07:00 Thai time).
     * @return array{usage:float,usage_daily:float,usage_weekly:float,usage_monthly:float}
     */
    public static function keyUsage(): array
    {
        $data = self::requestGet('/key')['data'] ?? [];
        return [
            'usage' => (float) ($data['usage'] ?? 0),
            'usage_daily' => (float) ($data['usage_daily'] ?? 0),
            'usage_weekly' => (float) ($data['usage_weekly'] ?? 0),
            'usage_monthly' => (float) ($data['usage_monthly'] ?? 0),
        ];
    }

    /**
     * Account-wide credit balance from GET /credits (shared across every key on the account).
     * @return array{total_credits:float,total_usage:float,remaining:float}
     */
    public static function credits(): array
    {
        $data = self::requestGet('/credits')['data'] ?? [];
        $total = (float) ($data['total_credits'] ?? 0);
        $used = (float) ($data['total_usage'] ?? 0);
        return ['total_credits' => $total, 'total_usage' => $used, 'remaining' => $total - $used];
    }

    /**
     * Pinned to OPENROUTER_* on purpose, unlike request(). Its only callers are keyUsage() and
     * credits(), which read /key and /credits — OpenRouter account endpoints that exist nowhere
     * else. Once chat moves to MiniMax the cost dashboard stops describing where the money goes;
     * following LLM_BASE_URL here would turn that into a 404 instead, which is worse.
     */
    private static function requestGet(string $path, int $timeoutSeconds = 30): array
    {
        $ch = curl_init(OPENROUTER_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CAINFO => __DIR__ . '/../certs/cacert.pem',
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . OPENROUTER_API_KEY],
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('OpenRouter request failed: ' . $curlError);
        }
        $decoded = json_decode($body, true);
        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? $body;
            throw new RuntimeException("OpenRouter API error ({$httpCode}): {$message}");
        }
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Provider usage accumulated since the last takeUsage(), keyed by endpoint.
     *
     * Every response already carries a `usage` block and the pipeline used to drop it, which is
     * why "what does one conversation cost" had to be answered on 19 Aug 2026 by hand-measuring a
     * single call and multiplying. Keeping what we are already handed costs nothing.
     *
     * @var array<string,array>
     */
    private static $usage = [];

    private static function recordUsage(array $endpoint, $decoded, float $seconds): void
    {
        $u = is_array($decoded) ? ($decoded['usage'] ?? null) : null;
        if (!is_array($u)) {
            return;
        }
        $label = $endpoint['label'];
        if (!isset(self::$usage[$label])) {
            self::$usage[$label] = [
                'endpoint' => $label, 'model' => (string) ($endpoint['model'] ?? ''), 'calls' => 0,
                'prompt_tokens' => 0, 'cached_tokens' => 0, 'completion_tokens' => 0,
                'total_tokens' => 0, 'seconds' => 0.0,
            ];
        }
        self::$usage[$label]['calls']++;
        self::$usage[$label]['prompt_tokens'] += (int) ($u['prompt_tokens'] ?? 0);
        self::$usage[$label]['cached_tokens'] += (int) ($u['prompt_tokens_details']['cached_tokens'] ?? 0);
        self::$usage[$label]['completion_tokens'] += (int) ($u['completion_tokens'] ?? 0);
        self::$usage[$label]['total_tokens'] += (int) ($u['total_tokens'] ?? 0);
        self::$usage[$label]['seconds'] += $seconds;
    }

    /** Drains what has accumulated so far. The caller owns persisting it. */
    public static function takeUsage(): array
    {
        $out = array_values(self::$usage);
        self::$usage = [];
        return $out;
    }

    private static function request(array $endpoint, string $path, array $payload, int $timeoutSeconds = 120): array
    {
        $startedAt = microtime(true);
        $ch = curl_init($endpoint['url'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CAINFO => __DIR__ . '/../certs/cacert.pem',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $endpoint['key'],
                // OpenRouter attribution headers. Harmless everywhere else — MiniMax and the local
                // ASR service ignore unknown headers — so they stay unconditional rather than
                // branching on which provider is being called.
                'HTTP-Referer: https://www.prima49.com/voicecall',
                'X-Title: Voicecall AI Voice Intelligence Platform',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            // Timeout, DNS, connection refused. Nothing here says anything about the recording,
            // and a 'failed' row would be a permanent verdict on a temporary network.
            throw new WorkDeferred("{$endpoint['label']} request failed: {$curlError}");
        }
        $decoded = json_decode($body, true);
        if ($httpCode >= 400) {
            // MiniMax reports some failures as HTTP 200 with base_resp.status_code set, so the
            // status line alone is not the whole story — see the check below.
            $message = $decoded['error']['message'] ?? ($decoded['base_resp']['status_msg'] ?? $body);
            $error = "{$endpoint['label']} API error ({$httpCode}): {$message}";

            if ($httpCode === 429) {
                throw self::quotaExhausted($endpoint, $error);
            }
            // A rejected key is the operator's problem, not this recording's, so it must not burn
            // the queue. "Waiting will not fix it" is true and still the wrong conclusion: at 200
            // conversations an hour, the ten minutes between revoking a key and updating .env
            // would destroy ~35 recordings for a config edit. Stop the run and say what is wrong.
            if ($httpCode === 401 || $httpCode === 403) {
                throw self::authRejected($endpoint, $error);
            }
            // What is left in the 4xx range is this code sending a bad request — a wrong model id,
            // a malformed body. Waiting cannot fix those and retrying would loop forever, so they
            // stay genuine failures. Everything else is worth trying again later: the two
            // misclassifications do not cost the same, since deferring a genuine bug wastes a
            // retry while failing a passing outage loses the recording, and on 19 Aug 2026 that
            // difference was 1,509 recordings.
            if (in_array($httpCode, [400, 404, 422], true)) {
                throw new RuntimeException($error);
            }
            throw new WorkDeferred($error);
        }

        $status = $decoded['base_resp']['status_code'] ?? 0;
        if ($status !== 0) {
            $message = $decoded['base_resp']['status_msg'] ?? 'unknown error';
            $error = "{$endpoint['label']} API error (base_resp {$status}): {$message}";
            // MiniMax's in-band spellings of "slow down" (1002) and "you are out of quota" (1008).
            // The same conditions HTTP 429 reports, on the endpoints that answer 200 instead.
            if (in_array((int) $status, [1002, 1008], true)) {
                throw self::quotaExhausted($endpoint, $error);
            }
            throw new RuntimeException($error);
        }

        // Reaching here means this provider is answering normally, so whatever cooldown it was
        // under has served its purpose.
        CircuitBreaker::reset($endpoint['label']);
        self::recordUsage($endpoint, $decoded, microtime(true) - $startedAt);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Records that this provider is out of quota and returns the exception to throw.
     *
     * The breaker matters more here than it does for Drive. By the time the analysis call is made
     * the recording has already been downloaded and fully transcribed, so every conversation that
     * walks into an exhausted quota throws away all of that work — 1,463 of them in the two hours
     * of 19 Aug 2026 before anything noticed. Stopping the batch at the first refusal turns that
     * into one wasted transcription per cooldown.
     */
    private static function quotaExhausted(array $endpoint, string $error): WorkDeferred
    {
        $until = CircuitBreaker::trip(
            $endpoint['label'], self::QUOTA_COOLDOWN_SECONDS, self::QUOTA_COOLDOWN_SECONDS, 'โควตาเต็ม'
        );
        return new WorkDeferred($error . ' — พักการเรียกจนถึง ' . date('H:i:s', $until));
    }

    /**
     * Auth failures get a much shorter cooldown than quota ones, for the opposite reason. A quota
     * refills on the provider's schedule, so probing before then is guaranteed waste. A rejected
     * key is fixed the moment a human edits the config, which can be any second — so the pipeline
     * should stay within one probe of resuming. While the breaker is open nothing is claimed at
     * all, so the whole cost of being wrong here is one transcription per five minutes.
     */
    private const AUTH_COOLDOWN_SECONDS = 300;

    private static function authRejected(array $endpoint, string $error): WorkDeferred
    {
        $until = CircuitBreaker::trip(
            $endpoint['label'], self::AUTH_COOLDOWN_SECONDS, self::AUTH_COOLDOWN_SECONDS, 'คีย์ถูกปฏิเสธ — ตรวจ LLM_API_KEY ใน .env'
        );
        return new WorkDeferred($error . ' — คีย์ถูกปฏิเสธ พักการเรียกจนถึง ' . date('H:i:s', $until));
    }
}
