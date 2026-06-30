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

    private static function downloadFromDrive(string $fileId): string
    {
        if (!GDRIVE_API_KEY) {
            throw new RuntimeException('GDRIVE_API_KEY is not configured');
        }
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key=" . GDRIVE_API_KEY;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 60,
        ]);
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($data === false) {
            throw new RuntimeException("Drive download failed: {$curlError}");
        }
        if ($httpCode !== 200 || strlen($data) < 44) {
            throw new RuntimeException("Drive download failed (HTTP {$httpCode}) for fileId={$fileId}");
        }
        return $data;
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
