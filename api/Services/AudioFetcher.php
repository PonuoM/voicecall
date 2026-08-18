<?php

require_once __DIR__ . '/AudioDecoder.php';

/**
 * Gets a conversation's audio onto local disk as decoded PCM WAV, ready for Whisper (which
 * needs a real file path for CURLFile — and cannot ingest the GSM 6.10 codec these recordings
 * are stored in). Same Drive-download + decode path as audio_proxy.php, just writing to a cache
 * file instead of streaming an HTTP response.
 */
class AudioFetcher
{
    /**
     * @return string Absolute path to a decoded PCM WAV file on local disk.
     */
    public static function fetchToPcmWav(int $conversationId, string $source, string $audioRef): string
    {
        $rawWav = ($source === 'local')
            ? self::readLocalFile($audioRef)
            : self::downloadFromDrive($audioRef);

        $pcmWav = AudioDecoder::toPcmWav($rawWav);

        $destPath = AUDIO_CACHE_DIR . '/' . $conversationId . '.wav';
        if (file_put_contents($destPath, $pcmWav) === false) {
            throw new RuntimeException("Could not write audio cache file: {$destPath}");
        }
        return $destPath;
    }

    /**
     * abuse_interstitial is not a per-file problem - it is Google Drive deciding this IP itself is
     * abusive, and it outlasts the ~6 minutes of in-request backoff below by a wide margin. Without
     * this, the backlog drain kept discovering that fact one file at a time: every candidate in a
     * batch spent its own four retries finding out the whole IP was still blocked, so a single
     * active block turned into dozens of near-simultaneous failures (see the timeline page after
     * this happened for real - 30 conversations, 30 identical abuse_interstitial errors, seconds
     * apart) instead of costing one. Recording the block once and skipping every download attempt
     * until it should have lifted turns that into a single wasted request instead of the whole
     * batch, and escalating the cooldown on repeat trips stops a too-short wait from just
     * re-triggering it immediately.
     */
    private const CIRCUIT_FILE_NAME = 'drive_circuit_breaker.json';
    private const CIRCUIT_BASE_COOLDOWN_SECONDS = 1200; // 20 minutes
    private const CIRCUIT_MAX_COOLDOWN_SECONDS = 14400;  // 4 hours

    /** @return int|null Unix timestamp the block lifts at, or null if Drive is not currently blocked. */
    public static function driveCircuitOpen(): ?int
    {
        $state = self::readCircuitState();
        if ($state && (int) $state['blocked_until'] > time()) {
            return (int) $state['blocked_until'];
        }
        return null;
    }

    private static function readCircuitState(): ?array
    {
        $path = LOG_DIR . '/' . self::CIRCUIT_FILE_NAME;
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private static function tripDriveCircuit(): void
    {
        $state = self::readCircuitState() ?: ['consecutive_blocks' => 0];
        $blocks = ((int) ($state['consecutive_blocks'] ?? 0)) + 1;
        $cooldown = min(self::CIRCUIT_MAX_COOLDOWN_SECONDS, self::CIRCUIT_BASE_COOLDOWN_SECONDS * (2 ** ($blocks - 1)));
        file_put_contents(LOG_DIR . '/' . self::CIRCUIT_FILE_NAME, json_encode([
            'consecutive_blocks' => $blocks,
            'blocked_until' => time() + $cooldown,
            'tripped_at' => date('Y-m-d H:i:s'),
        ]));
    }

    /** A real success means whatever block existed has lifted - future trips restart the backoff ladder. */
    private static function resetDriveCircuit(): void
    {
        $path = LOG_DIR . '/' . self::CIRCUIT_FILE_NAME;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * The circuit breaker above is damage control after the fact - this is the thing meant to stop
     * the block from happening at all. abuse_interstitial tripped once at 45 downloads back to back
     * with no gap between them; a small floor on the gap between any two downloads, shared across
     * every caller (backlog drain, the pending worker, a manual "Analyze" click, audio_proxy.php
     * playback) via one timestamp file, keeps the request rate low enough that a backlog-draining
     * batch never looks like the burst that triggered it in the first place.
     */
    private const MIN_DOWNLOAD_INTERVAL_SECONDS = 2.5;

    private static function throttleBeforeDownload(): void
    {
        $path = LOG_DIR . '/drive_last_download.txt';
        $last = is_file($path) ? (float) file_get_contents($path) : 0.0;
        $wait = self::MIN_DOWNLOAD_INTERVAL_SECONDS - (microtime(true) - $last);
        if ($wait > 0) {
            usleep((int) ($wait * 1000000));
        }
        file_put_contents($path, (string) microtime(true));
    }

    /**
     * Drive answers a throttle two different ways, and only one of them is JSON.
     *
     * Ordinary quota rejections come back as a JSON error body. Once abuse detection trips for the
     * whole IP — which took 45 downloads in quick succession while assembling a test corpus — the
     * response is a plain HTML "Sorry..." page instead, and it outlasts any backoff measured in
     * seconds. A permission failure looks nothing like either and must not be retried at all, since
     * waiting will never grant access.
     *
     * Before this there was no retry of any kind: a single 403 ended the conversation as a failure,
     * and the backlog drain will make far more Drive requests than the sampling worker ever did.
     */
    private static function downloadFromDrive(string $fileId, int $attempts = 4): string
    {
        if (!GDRIVE_API_KEY) {
            throw new RuntimeException('GDRIVE_API_KEY is not configured');
        }

        $blockedUntil = self::driveCircuitOpen();
        if ($blockedUntil !== null) {
            throw new RuntimeException(
                'Drive download skipped (abuse_interstitial circuit breaker open until ' . date('H:i:s', $blockedUntil) . ')'
            );
        }

        self::throttleBeforeDownload();

        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key=" . GDRIVE_API_KEY;
        $lastMessage = 'unknown error';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 180,
            ]);
            $data = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($data !== false && $httpCode === 200 && strlen($data) >= 44) {
                self::resetDriveCircuit();
                return $data;
            }

            $reason = null;
            if ($data === false) {
                $lastMessage = "curl: {$curlError}";
                $retryable = true;
            } else {
                $reason = self::driveErrorReason((string) $data);
                $lastMessage = "HTTP {$httpCode}" . ($reason ? " ({$reason})" : '');
                $retryable = in_array($httpCode, [429, 500, 502, 503, 504], true)
                    || ($httpCode === 403 && $reason !== 'permission_denied');
            }

            if (!$retryable || $attempt === $attempts) {
                if ($reason === 'abuse_interstitial') {
                    self::tripDriveCircuit();
                }
                break;
            }
            // Minutes, not seconds. The interstitial is an IP-level cooldown; retrying at 2s/4s/8s
            // just re-arms it.
            $backoff = [30, 90, 240][min($attempt - 1, 2)];
            sleep($backoff);
        }

        throw new RuntimeException("Drive download failed ({$lastMessage}) for fileId={$fileId}");
    }

    /**
     * An unparseable body means the abuse interstitial rather than a permission problem — that page
     * is HTML, so treating "not JSON" as "not retryable" would give up exactly when waiting works.
     */
    private static function driveErrorReason(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json)) {
            $reason = $json['error']['errors'][0]['reason'] ?? ($json['error']['status'] ?? '');
            if ($reason === '' || stripos($reason, 'Limit') !== false || stripos($reason, 'RESOURCE_EXHAUSTED') !== false) {
                return 'rate_limited';
            }
            return 'permission_denied';
        }
        return stripos($body, 'sorry') !== false ? 'abuse_interstitial' : 'rate_limited';
    }

    // Matches index.html's loadLocalData()/downloadAudio(), which both prefix paths from
    // recordings.json with this same hardcoded folder — local source is the one historical
    // export batch, not a general "any local file" mechanism.
    private const LOCAL_DATA_DIR = '20260120-20260131';

    private static function readLocalFile(string $relativePath): string
    {
        $recordingsRoot = realpath(__DIR__ . '/../../' . self::LOCAL_DATA_DIR);
        $fullPath = realpath($recordingsRoot . '/' . $relativePath);
        if ($recordingsRoot === false || $fullPath === false || strpos($fullPath, $recordingsRoot) !== 0) {
            throw new RuntimeException("Invalid local audio path: {$relativePath}");
        }
        $data = file_get_contents($fullPath);
        if ($data === false) {
            throw new RuntimeException("Could not read local audio file: {$fullPath}");
        }
        return $data;
    }
}
