<?php
/**
 * Audio Proxy — serves Google Drive call recordings as browser-playable PCM WAV.
 * Usage: audio_proxy.php?id=GOOGLE_DRIVE_FILE_ID[&dl=1]
 *
 * Pure PHP GSM 6.10 -> PCM, no ffmpeg/shell_exec. Decoding costs ~0.03s CPU per second of audio,
 * so decoding a whole recording up front only works for short calls — a 2-hour call needs ~4
 * minutes and simply timed out. Playback therefore streams: MS-GSM is a fixed-size block codec,
 * so the requested byte range maps arithmetically onto block indexes and only those blocks get
 * decoded (see AudioDecoder::decodeGsmBlockRange). The source file is cached on disk so seeking
 * doesn't re-download it from Drive every time.
 *
 * ?dl=1 (the dashboard's download button) still returns the complete file in one response, since
 * a saved .wav has to be whole.
 */
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/Services/AudioDecoder.php';

/**
 * Max bytes decoded+returned per playback request: 768 KB ≈ 24s of audio, ≈3s to decode on the
 * production host. Sized for time-to-first-sound — the player keeps requesting the next chunk
 * while it plays, and decoding runs ~8x faster than realtime so it never starves.
 */
const PROXY_CHUNK_BYTES = 786432;
const PROXY_CACHE_DIR = AUDIO_CACHE_DIR . '/proxy_src';
const PROXY_CACHE_MAX_BYTES = 1073741824; // 1 GB of cached source files
const PROXY_CACHE_MAX_AGE = 86400;

header('Access-Control-Allow-Origin: *');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_time_limit(0);

$fileId = $_GET['id'] ?? '';
// Drive file ids are [A-Za-z0-9_-]; validating here also keeps the id safe as a cache filename.
if ($fileId === '' || !preg_match('/^[A-Za-z0-9_-]{10,128}$/', $fileId)) {
    http_response_code(400);
    die('Missing or invalid id');
}

$isDownload = !empty($_GET['dl']);

try {
    $wavData = proxy_load_source($fileId);
    $meta = AudioDecoder::parseWavHeader($wavData);
} catch (Throwable $e) {
    http_response_code(502);
    die('Audio unavailable: ' . $e->getMessage());
}

if ($meta['fmtTag'] === 1) {
    // Already PCM (OneCall recordings) — no decoding needed, serve the bytes as they are.
    proxy_serve($wavData, strlen($wavData), function ($start, $length) use ($wavData) {
        return substr($wavData, $start, $length);
    }, $isDownload, $fileId);
    exit;
}

if ($meta['fmtTag'] !== 0x31) {
    http_response_code(415);
    die('Unsupported WAV codec tag: ' . $meta['fmtTag']);
}

$layout = AudioDecoder::gsmPcmLayout($meta);
if ($layout['numBlocks'] < 1) {
    http_response_code(502);
    die('Recording contains no audio data');
}

// Produces any byte range of the decoded PCM WAV (44-byte header + samples) by decoding only the
// blocks that range covers.
$producer = function ($start, $length) use ($wavData, $meta, $layout) {
    $out = '';
    $end = $start + $length - 1;

    if ($start < AudioDecoder::PCM_HEADER_SIZE) {
        $header = AudioDecoder::pcmWavHeader($meta['sampleRate'], $layout['pcmDataSize']);
        $out .= substr($header, $start, min(AudioDecoder::PCM_HEADER_SIZE - 1, $end) - $start + 1);
    }

    $dataStart = max(0, $start - AudioDecoder::PCM_HEADER_SIZE);
    $dataEnd = $end - AudioDecoder::PCM_HEADER_SIZE;
    if ($dataEnd >= $dataStart) {
        $blockStart = intdiv($dataStart, AudioDecoder::PCM_BYTES_PER_BLOCK);
        $blockEnd = intdiv($dataEnd, AudioDecoder::PCM_BYTES_PER_BLOCK);
        $pcm = AudioDecoder::decodeGsmBlockRange($wavData, $meta, $blockStart, $blockEnd - $blockStart + 1);
        $out .= substr($pcm, $dataStart - $blockStart * AudioDecoder::PCM_BYTES_PER_BLOCK, $dataEnd - $dataStart + 1);
    }

    return $out;
};

proxy_serve(null, $layout['totalSize'], $producer, $isDownload, $fileId);

// ---------------------------------------------------------------------------

/**
 * Source WAV bytes for a Drive file, cached on disk. Seeking in a long recording issues many
 * ranged requests; without this each one would re-download the same multi-MB file from Drive.
 */
function proxy_load_source(string $fileId): string
{
    if (!is_dir(PROXY_CACHE_DIR)) {
        @mkdir(PROXY_CACHE_DIR, 0775, true);
    }
    $cacheFile = PROXY_CACHE_DIR . '/' . $fileId . '.wav';

    if (is_file($cacheFile) && filesize($cacheFile) > 44) {
        @touch($cacheFile); // keep recently-played files from being pruned first
        $data = file_get_contents($cacheFile);
        if ($data !== false && strlen($data) > 44) {
            return $data;
        }
    }

    $apiKey = defined('GDRIVE_API_KEY') && GDRIVE_API_KEY ? GDRIVE_API_KEY : 'AIzaSyCCIywRsoHuBzVTm-B-FA8N7VzAcECIEBE';
    $url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key={$apiKey}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 120,
    ]);
    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $data === false || strlen($data) < 44) {
        throw new RuntimeException("download failed (HTTP {$httpCode})");
    }

    // Write via a temp file + rename so a concurrent reader never sees a half-written cache entry.
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $data) !== false) {
        @rename($tmp, $cacheFile);
    } else {
        @unlink($tmp);
    }
    if (mt_rand(1, 20) === 1) {
        proxy_prune_cache();
    }

    return $data;
}

/** Keeps the source cache under a size/age budget, deleting least-recently-used files first. */
function proxy_prune_cache(): void
{
    $files = glob(PROXY_CACHE_DIR . '/*.wav') ?: [];
    $entries = [];
    $total = 0;
    $now = time();
    foreach ($files as $f) {
        $mtime = @filemtime($f);
        $size = @filesize($f);
        if ($mtime === false || $size === false) {
            continue;
        }
        if ($now - $mtime > PROXY_CACHE_MAX_AGE) {
            @unlink($f);
            continue;
        }
        $entries[] = ['path' => $f, 'mtime' => $mtime, 'size' => $size];
        $total += $size;
    }
    if ($total <= PROXY_CACHE_MAX_BYTES) {
        return;
    }
    usort($entries, function ($a, $b) { return $a['mtime'] <=> $b['mtime']; });
    foreach ($entries as $e) {
        if ($total <= PROXY_CACHE_MAX_BYTES) {
            break;
        }
        @unlink($e['path']);
        $total -= $e['size'];
    }
}

/**
 * Emits the response, honouring Range. Playback responses are capped at PROXY_CHUNK_BYTES so no
 * single request can take long enough to time out — returning less than asked for is legal for a
 * 206, and players simply request the next chunk as they go. Downloads (?dl=1) are never capped.
 *
 * @param string|null $whole     full body when already in memory (PCM passthrough), else null
 * @param callable    $producer  fn(int $start, int $length): string
 */
function proxy_serve(?string $whole, int $totalSize, callable $producer, bool $isDownload, string $fileId): void
{
    header('Accept-Ranges: bytes');
    header('Content-Type: audio/wav');
    header('Cache-Control: public, max-age=3600');
    if ($isDownload) {
        header('Content-Disposition: attachment; filename="' . $fileId . '.wav"');
    }

    $hasRange = isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', $_SERVER['HTTP_RANGE'], $m);

    if (!$hasRange && ($isDownload || $totalSize <= PROXY_CHUNK_BYTES)) {
        header('Content-Length: ' . $totalSize);
        echo $whole !== null ? $whole : $producer(0, $totalSize);
        return;
    }

    $start = 0;
    $end = $totalSize - 1;
    if ($hasRange) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $totalSize - (int) $m[2]); // suffix range: bytes=-N
        } else {
            $start = (int) $m[1];
            if ($m[2] !== '') {
                $end = min($end, (int) $m[2]);
            }
        }
        if ($start > $end || $start >= $totalSize) {
            header('Content-Range: bytes */' . $totalSize);
            http_response_code(416);
            return;
        }
    }

    if (!$isDownload) {
        $end = min($end, $start + PROXY_CHUNK_BYTES - 1);
    }

    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Length: ' . $length);
    header("Content-Range: bytes {$start}-{$end}/{$totalSize}");

    echo $whole !== null ? substr($whole, $start, $length) : $producer($start, $length);
}
