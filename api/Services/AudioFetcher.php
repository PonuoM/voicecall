<?php

require_once __DIR__ . '/AudioDecoder.php';
require_once __DIR__ . '/AudioUnavailable.php';
require_once __DIR__ . '/CircuitBreaker.php';

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
    private const CIRCUIT = 'drive';
    // Escalating: a Drive block announces no length, and the retrying is itself part of what
    // provokes it, so each repeat trip should wait longer than the last.
    private const CIRCUIT_BASE_COOLDOWN_SECONDS = 1200; // 20 minutes
    private const CIRCUIT_MAX_COOLDOWN_SECONDS = 14400;  // 4 hours

    /** @return int|null Unix timestamp the block lifts at, or null if Drive is not currently blocked. */
    public static function driveCircuitOpen(): ?int
    {
        return CircuitBreaker::openUntil(self::CIRCUIT);
    }

    private static function tripDriveCircuit(): void
    {
        CircuitBreaker::trip(self::CIRCUIT, self::CIRCUIT_BASE_COOLDOWN_SECONDS, self::CIRCUIT_MAX_COOLDOWN_SECONDS);
    }

    /** A real success means whatever block existed has lifted - future trips restart the backoff ladder. */
    private static function resetDriveCircuit(): void
    {
        CircuitBreaker::reset(self::CIRCUIT);
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
     * Downloads authenticate as a service account. GDRIVE_API_KEY does not authenticate anything -
     * it names the billing project and nothing else - so "?key=" against a link-shared folder is
     * anonymous public traffic as far as Drive is concerned, and anonymous download traffic is
     * policed per source IP by the abuse system that serves the HTML "Sorry" interstitial.
     *
     * That ceiling is low, and it was the entire outage. Measured on 19 Aug 2026, prima49 got
     * 11-36 downloads through, then every subsequent one came back as the interstitial for four
     * hours - four times across one day, starting 03:30, 07:53, 12:12 and 16:35. What proves the IP
     * rather than the key or the files was at fault: the exact file that had just failed downloaded
     * fine from a different machine using the same API key, and files.list from prima49 itself kept
     * succeeding throughout the block (17:01:36, thirteen seconds, clean) because listing is not
     * what the download abuse system counts.
     *
     * A bearer token attributes the request to a real principal, which moves it out of the
     * anonymous per-IP pool and onto the project's own Drive quota. GDRIVE_API_KEY stays as a
     * fallback purely so that a missing credential file degrades to the old behaviour rather than
     * stopping the pipeline dead.
     */
    private const TOKEN_CACHE_FILE = 'drive_access_token.json';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 300;

    /** @var string|null Per-process memo, so a batch of downloads signs one JWT rather than one each. */
    private static $accessToken = null;

    private static function serviceAccountPath(): string
    {
        $configured = getenv('GDRIVE_SERVICE_ACCOUNT_JSON');
        return ($configured !== false && $configured !== '')
            ? $configured
            : __DIR__ . '/../certs/gdrive-service-account.json';
    }

    private static function caBundlePath(): string
    {
        return __DIR__ . '/../certs/cacert.pem';
    }

    private static function base64Url(string $raw): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($raw));
    }

    /** Drops the cached token so the next call signs a fresh assertion. */
    private static function forgetAccessToken(): void
    {
        self::$accessToken = null;
        $path = LOG_DIR . '/' . self::TOKEN_CACHE_FILE;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @return string|null A Drive access token, or null when no service account is configured (the
     *   caller then falls back to GDRIVE_API_KEY).
     */
    private static function driveAccessToken(): ?string
    {
        if (self::$accessToken !== null) {
            return self::$accessToken;
        }

        $keyPath = self::serviceAccountPath();
        if (!is_file($keyPath)) {
            return null;
        }

        // Tokens last an hour and every caller in every process can share one, so the exchange is
        // worth caching on disk rather than per process - a backlog run that downloads 300 files
        // would otherwise sign 300 assertions for no reason. LOG_DIR is already the one directory
        // the web server is told to refuse (api/logs/.htaccess), which is what makes it a safe
        // place to park a bearer token.
        $cachePath = LOG_DIR . '/' . self::TOKEN_CACHE_FILE;
        if (is_file($cachePath)) {
            $cached = json_decode((string) file_get_contents($cachePath), true);
            if (is_array($cached) && !empty($cached['token']) && !empty($cached['expires_at'])
                && (int) $cached['expires_at'] - time() > self::TOKEN_REFRESH_MARGIN_SECONDS) {
                self::$accessToken = (string) $cached['token'];
                return self::$accessToken;
            }
        }

        $json = json_decode((string) file_get_contents($keyPath), true);
        if (!is_array($json) || empty($json['private_key']) || empty($json['client_email'])) {
            throw new RuntimeException("Invalid Drive service account JSON at {$keyPath}");
        }

        $now = time();
        $header = self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        // drive.readonly, not drive: this credential exists to fetch recordings. Nothing in this
        // pipeline writes to Drive, and the folder is shared to the service account as Editor, so
        // the scope is the only thing standing between a bug here and a modified source recording.
        $claim = self::base64Url((string) json_encode([
            'iss' => $json['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $signature = '';
        if (!openssl_sign($header . '.' . $claim, $signature, $json['private_key'], 'sha256')) {
            throw new RuntimeException('Could not sign the Drive service account assertion');
        }
        $assertion = $header . '.' . $claim . '.' . self::base64Url($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => self::caBundlePath(),
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if ($body === false || $httpCode !== 200 || empty($decoded['access_token'])) {
            // Google being unreachable is not this recording's fault, so it must not burn the row.
            throw new AudioUnavailable(
                'Could not get a Drive access token (HTTP ' . $httpCode . ($curlError ? ", curl: {$curlError}" : '') . ')'
            );
        }

        $expiresAt = time() + (int) ($decoded['expires_in'] ?? 3600);
        @file_put_contents($cachePath, (string) json_encode([
            'token' => $decoded['access_token'],
            'expires_at' => $expiresAt,
        ]));
        @chmod($cachePath, 0600);

        self::$accessToken = (string) $decoded['access_token'];
        return self::$accessToken;
    }

    /**
     * Drive answers a throttle two different ways, and only one of them is JSON.
     *
     * Ordinary quota rejections come back as a JSON error body and are worth waiting out. The HTML
     * "Sorry..." interstitial is not: it is an IP-level block lasting hours, and retrying inside it
     * is actively harmful. Across the 148 real failures on 18-19 Aug 2026, not one download ever
     * recovered on a later attempt within the same ladder, while each failure spent 360 seconds and
     * three extra requests telling Google that the same IP was still hammering it. So the
     * interstitial now ends the attempt immediately, trips the breaker on everyone else's behalf,
     * and hands the conversation back as retryable-later rather than failed.
     *
     * A permission failure is the one thing here that waiting will never fix, so it stays a
     * genuine, terminal failure.
     */
    private static function downloadFromDrive(string $fileId, int $attempts = 4): string
    {
        $blockedUntil = self::driveCircuitOpen();
        if ($blockedUntil !== null) {
            throw new AudioUnavailable(
                'Drive download deferred (abuse_interstitial cooldown until ' . date('H:i:s', $blockedUntil) . ')'
            );
        }

        $token = self::driveAccessToken();
        if ($token === null && !GDRIVE_API_KEY) {
            throw new RuntimeException(
                'No Drive credentials: neither ' . self::serviceAccountPath() . ' nor GDRIVE_API_KEY is available'
            );
        }

        self::throttleBeforeDownload();

        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media";
        $headers = [];
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        } else {
            $url .= '&key=' . GDRIVE_API_KEY;
        }

        $lastMessage = 'unknown error';
        $transient = true;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => $headers,
                // Verification was off here while the URL carried nothing but an API key. It now
                // carries a bearer token in a request header, and an unverified TLS peer would hand
                // that token to whoever answered - so this uses the same CA bundle GdriveIndexer
                // has been using against this exact host in production all along.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => self::caBundlePath(),
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

            if ($data === false) {
                $lastMessage = "curl: {$curlError}";
                $transient = true;
                $retryable = true;
            } else {
                $reason = self::driveErrorReason((string) $data);
                $lastMessage = "HTTP {$httpCode}" . ($reason ? " ({$reason})" : '');

                if ($reason === 'abuse_interstitial') {
                    self::tripDriveCircuit();
                    throw new AudioUnavailable("Drive refused this server's IP ({$lastMessage}) for fileId={$fileId}");
                }

                // An expired or revoked token is self-healing, but only once the cached copy goes
                // away - otherwise every caller for the next hour reuses the same dead token.
                if ($httpCode === 401) {
                    self::forgetAccessToken();
                    throw new AudioUnavailable("Drive rejected the access token ({$lastMessage}) - cleared, next run re-authenticates");
                }

                $transient = $reason !== 'permission_denied';
                $retryable = in_array($httpCode, [429, 500, 502, 503, 504], true)
                    || ($httpCode === 403 && $transient);
            }

            if (!$retryable || $attempt === $attempts) {
                break;
            }
            // Minutes, not seconds. A quota rejection is measured in minutes; retrying at 2s/4s/8s
            // just spends the allowance again without ever waiting long enough to get past it.
            $backoff = [30, 90, 240][min($attempt - 1, 2)];
            sleep($backoff);
        }

        if ($transient) {
            throw new AudioUnavailable("Drive download failed ({$lastMessage}) for fileId={$fileId}");
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
