<?php

/**
 * Single client for every AI capability the pipeline needs: chat completions (all
 * text-reasoning agents), speech-to-text, and embeddings — OpenRouter now offers all three
 * under one API key, so there is no separate direct-OpenAI client.
 */
class OpenRouterClient
{
    /**
     * @return string Raw text content of the model's reply.
     */
    public static function chat(string $systemPrompt, string $userPrompt, bool $jsonMode = false, ?string $model = null): string
    {
        if (!OPENROUTER_API_KEY) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured');
        }

        $payload = [
            'model' => $model ?: OPENROUTER_MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.2,
        ];
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $response = self::request('/chat/completions', $payload);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if ($content === null) {
            throw new RuntimeException('OpenRouter response missing message content: ' . json_encode($response));
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
     * Speech-to-text via OpenRouter's /audio/transcriptions endpoint (base64 JSON body, not
     * multipart). Note: unlike calling OpenAI's Whisper API directly, OpenRouter's STT endpoint
     * returns only {text, usage} — no per-segment timestamps. usage.seconds gives call duration.
     * @return array{text:string,duration:?float}
     */
    public static function transcribe(string $audioFilePath, ?string $language = null): array
    {
        if (!OPENROUTER_API_KEY) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured');
        }
        if (!file_exists($audioFilePath)) {
            throw new RuntimeException("Audio file not found: {$audioFilePath}");
        }

        $payload = [
            'model' => OPENROUTER_STT_MODEL,
            'input_audio' => [
                'data' => base64_encode(file_get_contents($audioFilePath)),
                'format' => 'wav',
            ],
        ];
        if ($language) {
            $payload['language'] = $language;
        }

        $response = self::request('/audio/transcriptions', $payload, 300);

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
    public static function transcribeViaChatAudio(string $audioFilePath, string $model, ?string $language = null): array
    {
        if (!OPENROUTER_API_KEY) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured');
        }
        if (!file_exists($audioFilePath)) {
            throw new RuntimeException("Audio file not found: {$audioFilePath}");
        }

        $langHint = $language === 'th' ? ' The audio is a Thai business phone call.' : '';
        $prompt = 'Transcribe this audio verbatim in the language spoken.' . $langHint
            . ' Put each speaker turn on its own line, in chronological order. Output only the'
            . ' transcription text, no commentary, no speaker labels, no timestamps.';

        $payload = [
            'model' => $model,
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

        $response = self::request('/chat/completions', $payload, $timeoutSeconds);
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
        if (!OPENROUTER_API_KEY) {
            throw new RuntimeException('OPENROUTER_API_KEY is not configured');
        }
        if (empty($texts)) {
            return [];
        }

        $response = self::request('/embeddings', [
            'model' => OPENROUTER_EMBEDDING_MODEL,
            'input' => $texts,
        ], 120);

        $vectors = [];
        foreach (($response['data'] ?? []) as $item) {
            $vectors[] = $item['embedding'];
        }
        return $vectors;
    }

    private static function extractJson(string $raw): ?array
    {
        $trimmed = trim($raw);
        $trimmed = preg_replace('/^```(?:json)?\s*/i', '', $trimmed);
        $trimmed = preg_replace('/\s*```$/', '', $trimmed);
        $decoded = json_decode($trimmed, true);
        return is_array($decoded) ? $decoded : null;
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

    private static function request(string $path, array $payload, int $timeoutSeconds = 120): array
    {
        $ch = curl_init(OPENROUTER_BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CAINFO => __DIR__ . '/../certs/cacert.pem',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . OPENROUTER_API_KEY,
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
            throw new RuntimeException('OpenRouter request failed: ' . $curlError);
        }
        $decoded = json_decode($body, true);
        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? $body;
            throw new RuntimeException("OpenRouter API error ({$httpCode}): {$message}");
        }
        return is_array($decoded) ? $decoded : [];
    }
}
