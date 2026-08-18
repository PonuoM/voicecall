<?php

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
        if ($decoded !== null) {
            return $decoded;
        }

        $retryPrompt = "Your previous response was not valid JSON. Return ONLY a valid JSON object, no markdown fences, no commentary.\n\nPrevious response:\n{$raw}";
        $raw2 = self::chat($systemPrompt, $retryPrompt, true, $model);
        $decoded2 = self::extractJson($raw2);
        if ($decoded2 !== null) {
            return $decoded2;
        }

        throw new RuntimeException('OpenRouter did not return valid JSON after retry: ' . substr($raw2, 0, 500));
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

    private static function request(array $endpoint, string $path, array $payload, int $timeoutSeconds = 120): array
    {
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
            throw new RuntimeException("{$endpoint['label']} request failed: {$curlError}");
        }
        $decoded = json_decode($body, true);
        if ($httpCode >= 400) {
            // MiniMax reports some failures as HTTP 200 with base_resp.status_code set, so the
            // status line alone is not the whole story — see the check below.
            $message = $decoded['error']['message'] ?? ($decoded['base_resp']['status_msg'] ?? $body);
            throw new RuntimeException("{$endpoint['label']} API error ({$httpCode}): {$message}");
        }

        $status = $decoded['base_resp']['status_code'] ?? 0;
        if ($status !== 0) {
            $message = $decoded['base_resp']['status_msg'] ?? 'unknown error';
            throw new RuntimeException("{$endpoint['label']} API error (base_resp {$status}): {$message}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
