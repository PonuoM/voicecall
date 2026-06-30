<?php
/**
 * Audio Proxy — Pure PHP GSM 6.10 WAV → PCM WAV converter
 * No ffmpeg or shell_exec needed
 * Usage: audio_proxy.php?id=GOOGLE_DRIVE_FILE_ID
 */
require_once __DIR__ . '/api/Services/AudioDecoder.php';

header('Access-Control-Allow-Origin: *');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$fileId = $_GET['id'] ?? '';
$apiKey = 'AIzaSyCCIywRsoHuBzVTm-B-FA8N7VzAcECIEBE';
if (empty($fileId)) {
    http_response_code(400);
    die('Missing id');
}

// Download from Google Drive
$url = "https://www.googleapis.com/drive/v3/files/{$fileId}?alt=media&key={$apiKey}";
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 30]);
$wavData = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($httpCode !== 200 || strlen($wavData) < 44) {
    http_response_code(502);
    die('Download failed');
}

try {
    $pcmWav = AudioDecoder::toPcmWav($wavData);
} catch (Throwable $e) {
    http_response_code(400);
    die($e->getMessage());
}

// Helper function to serve audio with Accept-Ranges support
function serveAudioWithRanges($data) {
    $length = strlen($data);
    $start = 0;
    $end = $length - 1;

    header('Accept-Ranges: bytes');
    header('Content-Type: audio/wav');
    header('Cache-Control: public, max-age=3600');

    if (isset($_SERVER['HTTP_RANGE'])) {
        list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
        if (strpos($range, ',') !== false) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$length");
            exit;
        }
        if ($range == '-') {
            $c_start = $length - substr($range, 1);
        } else {
            $range = explode('-', $range);
            $c_start = $range[0];
            $c_end = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $length;
        }
        $c_end = ($c_end > $end) ? $end : $c_end;
        if ($c_start > $c_end || $c_start > $length - 1 || $c_end >= $length) {
            header('HTTP/1.1 416 Requested Range Not Satisfiable');
            header("Content-Range: bytes $start-$end/$length");
            exit;
        }
        $start = $c_start;
        $end = $c_end;
        $length = $end - $start + 1;
        header('HTTP/1.1 206 Partial Content');
        header("Content-Length: $length");
        header("Content-Range: bytes $start-$end/" . strlen($data));
    } else {
        header("Content-Length: $length");
    }

    echo substr($data, $start, $length);
}

serveAudioWithRanges($pcmWav);
