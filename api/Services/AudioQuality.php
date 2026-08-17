<?php

require_once __DIR__ . '/AudioDecoder.php';

/**
 * Two cheap, local checks that stop the pipeline paying to analyse recordings it cannot learn
 * anything from — and, more importantly, stop it storing invented conversations as fact.
 *
 * Both were written after measuring this corpus. Of 46 production recordings sampled, 30% were
 * effectively silent (-63 to -75 dBFS) and 22% were clipped or distorted; only 48% carried audio a
 * person could transcribe. Feeding the silent ones to the STT model did not fail cleanly, it made
 * things up: conversation 79 is 183 seconds at -74 dBFS and came back with 162,905 characters of
 * "ฮัลโหล ได้ ยิน ครับ" repeating — 889 characters per second, against the ~10 that Thai speech
 * actually runs at. Conversation 48 stored 2,731 lines of which 2 were distinct. Across the 84
 * completed conversations, 11 transcripts look like this and they account for 84% of all transcript
 * text in the database. Each one then fed summaries, entity extraction and — for conversations 73
 * and 76 — fraud checks, so fabricated audio became fabricated evidence about employees.
 *
 * Three independent Whisper models (large-v3 and two Thai fine-tunes) were run over the same
 * recordings and each produced different incoherent looping output, which is what confirmed the
 * audio genuinely has no speech in it rather than the STT provider being at fault. No provider
 * change fixes this; the guard has to live here.
 */
class AudioQuality
{
    /**
     * Below this, treat the recording as having no speech worth sending anywhere. Measured
     * separation in this corpus is unambiguous — usable calls sit at -20 to -30 dBFS and the dead
     * ones cluster at -63 to -75, with nothing in between — so the threshold sits in the empty gap
     * rather than close to either group.
     */
    public static function minDbfs(): float
    {
        return (float) (getenv('STT_MIN_DBFS') ?: -45.0);
    }

    /**
     * Thai speech in the clean recordings here runs at a median of ~10 characters per second. This
     * ceiling is 2.5x that: high enough that no real speaker trips it, low enough to catch every
     * loop observed (the mildest was 27/s, the worst 889/s).
     */
    public static function maxCharsPerSecond(): float
    {
        return (float) (getenv('STT_MAX_CHARS_PER_SECOND') ?: 25.0);
    }

    /**
     * Windows sampled across the file, and samples per window, for the level estimate.
     *
     * Sampling rather than reading every sample keeps memory flat on hour-long recordings, at the
     * cost of some variance against a full-file RMS. At 60x2048 that variance reached 10 dB on
     * recordings with uneven loudness; 120x4096 covers roughly a third of a three-minute call and
     * brings it under a decibel, for a few hundred milliseconds of work against an STT call that
     * takes tens of seconds. The margin matters because a level estimate that reads 10 dB low on a
     * quiet-but-real recording would throw the call away unheard.
     */
    private const PROBE_WINDOWS = 120;
    private const PROBE_SAMPLES = 4096;

    /**
     * Level statistics for a PCM WAV, read by sampling windows across the file rather than loading
     * it whole — a 20-minute recording is ~19M samples, and unpacking that into a PHP array costs
     * more memory than the 512M limit allows.
     *
     * @return array{dbfs:float,peak:int,seconds:float,sample_rate:int,channels:int}
     */
    public static function measure(string $pcmWavPath): array
    {
        $handle = fopen($pcmWavPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open audio file for inspection: {$pcmWavPath}");
        }

        try {
            // 64KB is comfortably past the longest header these files carry, and avoids reading a
            // multi-megabyte recording into memory just to find the data chunk.
            $meta = AudioDecoder::parseWavHeader(fread($handle, 65536));
            if ($meta['fmtTag'] !== 1) {
                throw new RuntimeException('AudioQuality expects decoded PCM, got codec tag ' . $meta['fmtTag']);
            }

            // parseWavHeader() clamps dataSize to the length of the buffer it was handed, which is
            // correct for its own callers (they pass whole files) but silently wrong here: fed a
            // 64KB window it reports a 64KB recording. Measuring that would sample only the opening
            // moments of the call, which on a phone line are near-silent while the line connects —
            // every recording then looks like a dead one. Read the chunk's declared size straight
            // from the four bytes ahead of the data offset instead, bounded by the real file size.
            $fileSize = filesize($pcmWavPath);
            fseek($handle, $meta['dataOffset'] - 4);
            $declared = unpack('V', (string) fread($handle, 4))[1] ?? 0;
            $dataSize = min((int) $declared, (int) $fileSize - $meta['dataOffset']);
            if ($dataSize <= 0) {
                $dataSize = max(0, (int) $fileSize - $meta['dataOffset']);
            }

            $bytesPerSample = 2;
            $totalSamples = intdiv($dataSize, $bytesPerSample);
            $frames = max(1, intdiv($totalSamples, max(1, $meta['channels'])));
            $seconds = $frames / max(1, $meta['sampleRate']);

            if ($totalSamples === 0) {
                return [
                    'dbfs' => -999.0,
                    'peak' => 0,
                    'seconds' => 0.0,
                    'sample_rate' => $meta['sampleRate'],
                    'channels' => $meta['channels'],
                ];
            }

            $windows = min(self::PROBE_WINDOWS, max(1, intdiv($totalSamples, self::PROBE_SAMPLES)));
            $stride = intdiv($totalSamples, $windows);
            $sumSquares = 0.0;
            $counted = 0;
            $peak = 0;

            for ($w = 0; $w < $windows; $w++) {
                $sampleOffset = $w * $stride;
                $take = min(self::PROBE_SAMPLES, $totalSamples - $sampleOffset);
                if ($take <= 0) {
                    break;
                }
                fseek($handle, $meta['dataOffset'] + $sampleOffset * $bytesPerSample);
                $chunk = fread($handle, $take * $bytesPerSample);
                if ($chunk === false || $chunk === '') {
                    break;
                }

                // WAV stores signed little-endian 16-bit. PHP 7.3 has no signed-LE unpack code, so
                // read unsigned LE ('v') and fold the top half of the range back to negative — the
                // exact inverse of the pack('v*') AudioDecoder uses when it writes PCM.
                foreach (unpack('v*', substr($chunk, 0, (intdiv(strlen($chunk), 2)) * 2)) as $unsigned) {
                    $value = $unsigned > 32767 ? $unsigned - 65536 : $unsigned;
                    $sumSquares += $value * $value;
                    $counted++;
                    $magnitude = abs($value);
                    if ($magnitude > $peak) {
                        $peak = $magnitude;
                    }
                }
            }

            $rms = $counted > 0 ? sqrt($sumSquares / $counted) : 0.0;
            $dbfs = $rms > 0 ? 20 * log10($rms / 32768) : -999.0;

            return [
                'dbfs' => round($dbfs, 1),
                'peak' => $peak,
                'seconds' => round($seconds, 1),
                'sample_rate' => $meta['sampleRate'],
                'channels' => $meta['channels'],
            ];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Why this recording should not be sent to a paid STT model, or null if it should.
     */
    public static function rejectionReason(array $measurement): ?string
    {
        if ($measurement['seconds'] <= 0) {
            return 'ไฟล์เสียงว่างเปล่า (0 วินาที)';
        }
        if ($measurement['dbfs'] < self::minDbfs()) {
            return sprintf(
                'เสียงเงียบเกินกว่าจะถอดความได้ (%.1f dBFS, เกณฑ์ %.1f dBFS) — ข้ามเพื่อไม่ให้ AI แต่งบทสนทนาขึ้นเอง',
                $measurement['dbfs'],
                self::minDbfs()
            );
        }
        return null;
    }

    /**
     * Why this transcript should be rejected as a runaway repetition, or null if it looks real.
     *
     * Two signals, because the loops take two shapes. Conversation 48 stored thousands of identical
     * short lines, which the line-uniqueness check catches. Conversation 79 stored one enormous
     * unbroken line, which only the rate check catches. Both have to be here.
     */
    public static function loopReason(string $text, float $seconds): ?string
    {
        // mb_strlen, not strlen: Thai is multi-byte, and strlen would report roughly three times the
        // real character count and silently move the rate past any threshold set here.
        $stripped = preg_replace('/\s+/u', '', $text);
        $chars = mb_strlen((string) $stripped, 'UTF-8');
        if ($chars === 0 || $seconds <= 0) {
            return null;
        }

        $rate = $chars / $seconds;
        if ($rate > self::maxCharsPerSecond()) {
            return sprintf(
                'ผลถอดความเร็วผิดธรรมชาติ (%.0f ตัวอักษร/วินาที, เกณฑ์ %.0f) — น่าจะเป็น loop ของโมเดล ไม่ใช่คำพูดจริง',
                $rate,
                self::maxCharsPerSecond()
            );
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));
        if (count($lines) >= 20) {
            $uniqueShare = count(array_unique($lines)) / count($lines);
            if ($uniqueShare < 0.10) {
                return sprintf(
                    'ผลถอดความซ้ำตัวเอง (%d บรรทัด แต่ไม่ซ้ำเพียง %d บรรทัด) — เป็น loop ของโมเดล',
                    count($lines),
                    count(array_unique($lines))
                );
            }
        }

        return null;
    }
}
