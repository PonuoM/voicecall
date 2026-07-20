<?php

/**
 * Reads audio duration straight from a RIFF/WAV header, whatever the codec
 * (works for both the old GSM 6.10 recordings and OneCall's PCM files):
 * duration = data-chunk size / fmt-chunk byte rate.
 */
class WavInfo
{
    /** @return int|null duration in whole seconds, or null if the file is not a parseable WAV */
    public static function durationSeconds(string $path): ?int
    {
        $fh = @fopen($path, 'rb');
        if (!$fh) {
            return null;
        }

        $riff = fread($fh, 12);
        if (strlen($riff) < 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            fclose($fh);
            return null;
        }

        $byteRate = null;
        $dataSize = null;
        while (true) {
            $hdr = fread($fh, 8);
            if ($hdr === false || strlen($hdr) < 8) {
                break;
            }
            $chunkId = substr($hdr, 0, 4);
            $chunkSize = unpack('V', substr($hdr, 4, 4))[1];

            if ($chunkId === 'fmt ') {
                $body = fread($fh, $chunkSize);
                if (strlen($body) >= 12) {
                    $byteRate = unpack('V', substr($body, 8, 4))[1];
                }
            } elseif ($chunkId === 'data') {
                $dataSize = $chunkSize;
                break; // no need to read the audio itself
            } else {
                fseek($fh, $chunkSize, SEEK_CUR);
            }

            if ($chunkSize % 2 === 1) {
                fseek($fh, 1, SEEK_CUR); // RIFF chunks are word-aligned
            }
        }
        fclose($fh);

        if (!$byteRate || $dataSize === null) {
            return null;
        }
        return (int) round($dataSize / $byteRate);
    }
}
