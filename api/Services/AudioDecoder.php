<?php

require_once __DIR__ . '/GsmDecoder.php';

/**
 * WAV container parsing + GSM 6.10 -> PCM conversion, shared by audio_proxy.php (playback) and
 * the STT pipeline (Services/AudioFetcher.php). Same decode path either way — Whisper needs PCM
 * (or another format it actually supports), it cannot ingest the GSM 6.10 codec these recordings
 * are stored in.
 */
class AudioDecoder
{
    /**
     * @return string PCM WAV bytes, ready to write to disk or send to an STT API. If the input
     * is already PCM, returns it unchanged (no-op passthrough).
     */
    public static function toPcmWav(string $wavData): string
    {
        $riff = substr($wavData, 0, 4);
        if ($riff !== 'RIFF') {
            throw new RuntimeException('Not a WAV file (missing RIFF header)');
        }

        $offset = 12;
        $fmtTag = 1;
        $channels = 1;
        $sampleRate = 8000;
        $blockAlign = 65;
        $dataOffset = 0;
        $dataSize = 0;
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
            } elseif ($chunkId === 'data') {
                $dataOffset = $offset + 8;
                $dataSize = $chunkSize;
            }
            $offset += 8 + $chunkSize;
            if ($chunkSize % 2 !== 0) {
                $offset++;
            }
        }
        if ($dataOffset === 0) {
            throw new RuntimeException('WAV file has no data chunk');
        }
        if ($dataSize > $len - $dataOffset) {
            $dataSize = $len - $dataOffset;
        }

        if ($fmtTag === 1) {
            return $wavData; // already PCM
        }
        if ($fmtTag !== 0x31) {
            throw new RuntimeException('Unsupported WAV codec tag: ' . $fmtTag);
        }

        $gsm = new GsmDecoder();
        $rawData = substr($wavData, $dataOffset, $dataSize);
        $numBlocks = intdiv(strlen($rawData), $blockAlign);
        $allPcm = '';

        for ($b = 0; $b < $numBlocks; $b++) {
            $block = substr($rawData, $b * $blockAlign, $blockAlign);
            // MS-GSM: 65 bytes = 2 frames x 160 samples = 320 samples
            $pcm1 = $gsm->decodeMsGsmFrame($block, 0, 0);
            $pcm2 = $gsm->decodeMsGsmFrame($block, 32, 4);
            foreach ($pcm1 as $s) {
                $allPcm .= pack('v', $s & 0xFFFF);
            }
            foreach ($pcm2 as $s) {
                $allPcm .= pack('v', $s & 0xFFFF);
            }
        }

        $pcmLen = strlen($allPcm);
        $wav = 'RIFF' . pack('V', 36 + $pcmLen) . 'WAVE';
        $wav .= 'fmt ' . pack('V', 16);
        $wav .= pack('vvVVvv', 1, 1, $sampleRate, $sampleRate * 2, 2, 16);
        $wav .= 'data' . pack('V', $pcmLen);
        $wav .= $allPcm;

        return $wav;
    }
}
