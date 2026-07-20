<?php

require_once __DIR__ . '/GsmDecoder.php';

/**
 * WAV container parsing + GSM 6.10 -> PCM conversion, shared by audio_proxy.php (playback) and
 * the STT pipeline (Services/AudioFetcher.php). Same decode path either way — Whisper needs PCM
 * (or another format it actually supports), it cannot ingest the GSM 6.10 codec these recordings
 * are stored in.
 *
 * Pure-PHP GSM decoding costs roughly 0.03s of CPU per second of audio, so a 2-hour recording
 * takes ~4 minutes to decode in full — far past any request timeout. That is why the block-range
 * API below exists: MS-GSM is a fixed-size block codec, so any PCM byte offset maps arithmetically
 * back to a block index, and playback can decode just the slice a player actually asked for.
 */
class AudioDecoder
{
    /** MS-GSM block = 2 GSM frames = 320 samples = 640 bytes of 16-bit PCM. */
    const SAMPLES_PER_BLOCK = 320;
    const PCM_BYTES_PER_BLOCK = 640;
    const PCM_HEADER_SIZE = 44;

    /**
     * Blocks decoded and thrown away ahead of a mid-stream seek. The GSM synthesis filter carries
     * state between frames (dp0/v/LARpp_old/msr in GsmDecoder), so decoding from a cold start mid
     * file would ring for a few frames; 25 blocks ≈ 1s of audio is plenty for it to settle.
     */
    const PRIME_BLOCKS = 25;

    /**
     * @return array{fmtTag:int,channels:int,sampleRate:int,blockAlign:int,dataOffset:int,dataSize:int}
     */
    public static function parseWavHeader(string $wavData): array
    {
        if (substr($wavData, 0, 4) !== 'RIFF') {
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
        if ($blockAlign < 1) {
            $blockAlign = 65;
        }

        return [
            'fmtTag' => $fmtTag,
            'channels' => $channels,
            'sampleRate' => $sampleRate,
            'blockAlign' => $blockAlign,
            'dataOffset' => $dataOffset,
            'dataSize' => $dataSize,
        ];
    }

    /**
     * Size of the PCM WAV this GSM input would produce — computed from block arithmetic alone, so
     * a player can be told the full Content-Length without anything being decoded yet.
     * @return array{numBlocks:int,pcmDataSize:int,totalSize:int}
     */
    public static function gsmPcmLayout(array $meta): array
    {
        $numBlocks = intdiv($meta['dataSize'], $meta['blockAlign']);
        $pcmDataSize = $numBlocks * self::PCM_BYTES_PER_BLOCK;
        return [
            'numBlocks' => $numBlocks,
            'pcmDataSize' => $pcmDataSize,
            'totalSize' => self::PCM_HEADER_SIZE + $pcmDataSize,
        ];
    }

    public static function pcmWavHeader(int $sampleRate, int $pcmDataSize): string
    {
        $wav = 'RIFF' . pack('V', 36 + $pcmDataSize) . 'WAVE';
        $wav .= 'fmt ' . pack('V', 16);
        $wav .= pack('vvVVvv', 1, 1, $sampleRate, $sampleRate * 2, 2, 16);
        $wav .= 'data' . pack('V', $pcmDataSize);
        return $wav;
    }

    /**
     * Raw PCM bytes for blocks [$blockStart, $blockStart+$blockCount) — no WAV header.
     * Decodes PRIME_BLOCKS extra blocks beforehand (discarded) so a seek into the middle of a
     * recording doesn't start with filter-warmup noise.
     */
    public static function decodeGsmBlockRange(string $wavData, array $meta, int $blockStart, int $blockCount): string
    {
        $layout = self::gsmPcmLayout($meta);
        $blockStart = max(0, $blockStart);
        $blockCount = min($blockCount, $layout['numBlocks'] - $blockStart);
        if ($blockCount <= 0) {
            return '';
        }

        $primeFrom = max(0, $blockStart - self::PRIME_BLOCKS);
        $gsm = new GsmDecoder();
        $blockAlign = $meta['blockAlign'];
        $base = $meta['dataOffset'];
        $chunks = [];

        for ($b = $primeFrom; $b < $blockStart + $blockCount; $b++) {
            $block = substr($wavData, $base + $b * $blockAlign, $blockAlign);
            if (strlen($block) < $blockAlign) {
                break;
            }
            // MS-GSM: 65 bytes = 2 frames x 160 samples = 320 samples
            $pcm1 = $gsm->decodeMsGsmFrame($block, 0, 0);
            $pcm2 = $gsm->decodeMsGsmFrame($block, 32, 4);
            if ($b < $blockStart) {
                continue; // priming only — keep the decoder state, drop the audio
            }
            // pack('v*', ...) once per frame instead of once per sample: ~20x faster, and
            // pack('v') already wraps negatives to unsigned, matching the old & 0xFFFF.
            $chunks[] = pack('v*', ...$pcm1);
            $chunks[] = pack('v*', ...$pcm2);
        }

        return implode('', $chunks);
    }

    /**
     * @return string PCM WAV bytes, ready to write to disk or send to an STT API. If the input
     * is already PCM, returns it unchanged (no-op passthrough). Decodes the whole recording —
     * callers serving byte ranges should use decodeGsmBlockRange() instead.
     */
    public static function toPcmWav(string $wavData): string
    {
        $meta = self::parseWavHeader($wavData);

        if ($meta['fmtTag'] === 1) {
            return $wavData; // already PCM
        }
        if ($meta['fmtTag'] !== 0x31) {
            throw new RuntimeException('Unsupported WAV codec tag: ' . $meta['fmtTag']);
        }

        $layout = self::gsmPcmLayout($meta);
        $pcm = self::decodeGsmBlockRange($wavData, $meta, 0, $layout['numBlocks']);

        return self::pcmWavHeader($meta['sampleRate'], strlen($pcm)) . $pcm;
    }
}
