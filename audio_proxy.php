<?php
/**
 * Audio Proxy — Pure PHP GSM 6.10 WAV → PCM WAV converter
 * No ffmpeg or shell_exec needed
 * Usage: audio_proxy.php?id=GOOGLE_DRIVE_FILE_ID
 */
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

// Parse WAV header
$riff = substr($wavData, 0, 4);
if ($riff !== 'RIFF') {
    http_response_code(400);
    die('Not WAV');
}

$offset = 12;
$fmtTag = 1;
$channels = 1;
$sampleRate = 8000;
$blockAlign = 65;
$dataOffset = 0;
$dataSize = 0;
$samplesPerBlock = 320;
$len = strlen($wavData);

while ($offset < $len - 8) {
    $chunkId = substr($wavData, $offset, 4);
    $chunkSize = unpack('V', substr($wavData, $offset + 4, 4))[1];
    if ($chunkId === 'fmt ') {
        $fmt = unpack('vTag/vCh/VSR/VBR/vBA/vBPS', substr($wavData, $offset + 8, 16));
        $fmtTag = $fmt['Tag'];
        $channels = $fmt['Ch'];
        $sampleRate = $fmt['SR'];
        $blockAlign = $fmt['BA'];
        if ($chunkSize >= 20) {
            $extra = unpack('vSPB', substr($wavData, $offset + 26, 2));
            $samplesPerBlock = $extra['SPB'];
        }
    } elseif ($chunkId === 'data') {
        $dataOffset = $offset + 8;
        $dataSize = $chunkSize;
    }
    $offset += 8 + $chunkSize;
    if ($chunkSize % 2 !== 0)
        $offset++;
}
if ($dataOffset === 0) {
    http_response_code(400);
    die('No data chunk');
}
if ($dataSize > $len - $dataOffset)
    $dataSize = $len - $dataOffset;

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

// If already PCM, serve directly
if ($fmtTag === 1) {
    serveAudioWithRanges($wavData);
    exit;
}

// GSM 6.10 decode
if ($fmtTag !== 0x31) {
    http_response_code(400);
    die('Unsupported format: ' . $fmtTag);
}

$gsm = new GsmDecoder();
$rawData = substr($wavData, $dataOffset, $dataSize);
$numBlocks = intdiv(strlen($rawData), $blockAlign);
$allPcm = '';

for ($b = 0; $b < $numBlocks; $b++) {
    $block = substr($rawData, $b * $blockAlign, $blockAlign);
    // MS-GSM: 65 bytes = 2 frames × 160 samples = 320 samples
    $pcm1 = $gsm->decodeMsGsmFrame($block, 0, 0);
    $pcm2 = $gsm->decodeMsGsmFrame($block, 32, 4);
    // Pack as 16-bit signed LE
    foreach ($pcm1 as $s)
        $allPcm .= pack('v', $s & 0xFFFF);
    foreach ($pcm2 as $s)
        $allPcm .= pack('v', $s & 0xFFFF);
}

// Build PCM WAV
$pcmLen = strlen($allPcm);
$wav = 'RIFF' . pack('V', 36 + $pcmLen) . 'WAVE';
$wav .= 'fmt ' . pack('V', 16);
$wav .= pack('vvVVvv', 1, 1, $sampleRate, $sampleRate * 2, 2, 16);
$wav .= 'data' . pack('V', $pcmLen);
$wav .= $allPcm;

serveAudioWithRanges($wav);

// ===== GSM 6.10 Decoder (based on ETSI/libgsm reference) =====
class GsmDecoder
{
    private $dp0;
    private $nrp = 40;
    private $msr = 0;
    private $v;
    private $LARpp_old;

    private const QLB = [3277, 11469, 21299, 32767];
    private const FAC = [18431, 20479, 22527, 24575, 26623, 28671, 30719, 32767];
    private const MIC = [-32, -32, -16, -16, -8, -8, -4, -4];
    private const B_TAB = [0, 0, 2048, -2560, 94, -1792, -341, -1144];
    private const INVA = [13107, 13107, 13107, 13107, 19223, 17476, 31454, 29708];

    public function __construct()
    {
        $this->dp0 = array_fill(0, 280, 0);
        $this->v = array_fill(0, 9, 0);
        $this->LARpp_old = array_fill(0, 8, 0);
    }

    // Read bits LSB-first from data
    private function readBits(string $data, int &$bytePos, int &$bitPos, int $n): int
    {
        $val = 0;
        for ($i = 0; $i < $n; $i++) {
            if ($bytePos < strlen($data)) {
                $byte = ord($data[$bytePos]);
                $val |= (($byte >> $bitPos) & 1) << $i;
                $bitPos++;
                if ($bitPos >= 8) {
                    $bitPos = 0;
                    $bytePos++;
                }
            }
        }
        return $val;
    }

    public function decodeMsGsmFrame(string $block, int $startByte, int $startBit = 0): array
    {
        $bp = $startByte;
        $bi = $startBit;
        $widths = [6, 6, 5, 5, 4, 4, 3, 3];
        $LARc = [];
        for ($i = 0; $i < 8; $i++)
            $LARc[] = $this->readBits($block, $bp, $bi, $widths[$i]);
        $Nc = [];
        $bc = [];
        $Mc = [];
        $xmaxc = [];
        $xMc = [];
        for ($j = 0; $j < 4; $j++) {
            $Nc[] = $this->readBits($block, $bp, $bi, 7);
            $bc[] = $this->readBits($block, $bp, $bi, 2);
            $Mc[] = $this->readBits($block, $bp, $bi, 2);
            $xmaxc[] = $this->readBits($block, $bp, $bi, 6);
            $sub = [];
            for ($i = 0; $i < 13; $i++)
                $sub[] = $this->readBits($block, $bp, $bi, 3);
            $xMc[] = $sub;
        }
        return $this->decodeFrame($LARc, $Nc, $bc, $Mc, $xmaxc, $xMc);
    }

    private function decodeFrame(array $LARc, array $Nc, array $bc, array $Mc, array $xmaxc, array $xMc): array
    {
        $wt = array_fill(0, 160, 0);
        $drp = &$this->dp0;

        for ($j = 0; $j < 4; $j++) {
            $erp = $this->rpeDecode($xmaxc[$j], $Mc[$j], $xMc[$j]);
            $this->longTermSynthesis($Nc[$j], $bc[$j], $erp);
            for ($k = 0; $k < 40; $k++)
                $wt[$j * 40 + $k] = $drp[120 + $k];
        }

        $LARpp = $this->decodeLAR($LARc);
        $s = $wt;
        // Interpolated short-term synthesis
        $this->stSynthesis($this->interp($this->LARpp_old, $LARpp, 0), $s, 0, 12);
        $this->stSynthesis($this->interp($this->LARpp_old, $LARpp, 1), $s, 13, 26);
        $this->stSynthesis($this->interp($this->LARpp_old, $LARpp, 2), $s, 27, 39);
        $this->stSynthesis($this->interp($this->LARpp_old, $LARpp, 3), $s, 40, 159);
        $this->LARpp_old = $LARpp;

        // De-emphasis
        for ($k = 0; $k < 160; $k++) {
            $tmp = intdiv($this->msr * 28180 + 16384, 32768);
            $this->msr = $this->sat($s[$k] + $tmp);
            // Use ~7 instead of 0xFFF8 — works correctly on 64-bit PHP
            $s[$k] = $this->sat($this->msr + $this->msr) & ~7;
            // Ensure 16-bit signed range after masking
            if ($s[$k] > 32767)
                $s[$k] -= 65536;
        }
        return $s;
    }

    private function decodeLAR(array $LARc): array
    {
        $r = [];
        for ($i = 0; $i < 8; $i++) {
            $t = ($LARc[$i] + self::MIC[$i]) << 10;
            $t -= self::B_TAB[$i] << 1;
            $t = intdiv($t * self::INVA[$i] + 16384, 32768);
            $r[] = $this->sat($t + $t);
        }
        return $r;
    }

    private function interp(array $old, array $new, int $j): array
    {
        $r = [];
        for ($i = 0; $i < 8; $i++) {
            switch ($j) {
                case 0:
                    $r[] = $this->sat(($old[$i] >> 2) + ($old[$i] >> 1) + ($new[$i] >> 2));
                    break;
                case 1:
                    $r[] = $this->sat(($old[$i] >> 1) + ($new[$i] >> 1));
                    break;
                case 2:
                    $r[] = $this->sat(($old[$i] >> 2) + ($new[$i] >> 1) + ($new[$i] >> 2));
                    break;
                case 3:
                    $r[] = $new[$i];
                    break;
            }
        }
        // Convert LARp to reflection coefficients
        for ($i = 0; $i < 8; $i++) {
            $t = abs($r[$i]);
            if ($t < 11059)
                $t <<= 1;
            elseif ($t < 20070)
                $t += 11059;
            else
                $t = ($t >> 2) + 26112;
            $r[$i] = $r[$i] < 0 ? -$t : $t;
        }
        return $r;
    }

    private function rpeDecode(int $xmaxc, int $Mc, array $xMc): array
    {
        $exp = 0;
        $mant = 0;
        if ($xmaxc > 15)
            $exp = ($xmaxc >> 3) - 1;
        $mant = $xmaxc - ($exp << 3);
        if ($mant === 0) {
            $exp = -4;
            $mant = 7;
        } else {
            while ($mant <= 7) {
                $mant = ($mant << 1) | 1;
                $exp--;
            }
            $mant -= 8;
        }

        $temp1 = self::FAC[$mant];
        $temp2 = 6 - $exp;
        $temp3 = 1 << max(0, $temp2 - 1);

        $ep = array_fill(0, 40, 0);
        for ($i = 0; $i < 13; $i++) {
            $t = ($xMc[$i] << 1) - 7;
            $t = intdiv(($t << 12) * $temp1, 32768);
            $t = $this->sat($t + $temp3);
            $t = ($temp2 >= 0) ? ($t >> $temp2) : ($t << -$temp2);
            $ep[$Mc + 3 * $i] = $t;
        }
        return $ep;
    }

    private function longTermSynthesis(int $Ncr, int $bcr, array $erp): void
    {
        $Nr = ($Ncr < 40 || $Ncr > 120) ? $this->nrp : $Ncr;
        $this->nrp = $Nr;
        $brp = self::QLB[$bcr];

        for ($k = 0; $k < 40; $k++) {
            $idx = 120 + $k - $Nr;
            $drpp = ($idx >= 0 && $idx < 280) ? $this->dp0[$idx] : 0;
            $t = intdiv($brp * $drpp + 16384, 32768);
            $this->dp0[120 + $k] = $this->sat($erp[$k] + $t);
        }
        // Shift buffer
        for ($k = 0; $k < 120; $k++)
            $this->dp0[$k] = $this->dp0[$k + 40];
    }

    private function stSynthesis(array $rrp, array &$s, int $from, int $to): void
    {
        for ($k = $from; $k <= $to; $k++) {
            $sri = $s[$k];
            for ($i = 7; $i >= 0; $i--) {
                $tmp = intdiv($rrp[$i] * $this->v[$i] + 16384, 32768);
                $sri = $this->sat($sri - $tmp);
                $this->v[$i + 1] = $this->sat($this->v[$i] + intdiv($rrp[$i] * $sri + 16384, 32768));
            }
            $this->v[0] = $sri;
            $s[$k] = $sri;
        }
    }

    private function sat(int $v): int
    {
        return max(-32768, min(32767, $v));
    }
}
