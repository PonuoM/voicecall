-- 005: Real audio duration per file.
--
-- The dashboard used to estimate duration from size_bytes assuming every file is GSM 6.10
-- (1625 bytes/sec). Files synced from OneCall (api/sync/process_single.php) are PCM 16-bit
-- 8kHz mono (16000 bytes/sec), so their durations displayed ~9.85x too long.
--
-- Going forward process_single.php reads the real duration from the WAV header at sync time
-- (api/Services/WavInfo.php). This migration adds the column and backfills existing rows.
--
-- Backfill discriminator: company_folder_id. Rows inserted by the OneCall sync carry the
-- OneCall upload folder id; rows indexed from the "Company = N" Drive trees (GdriveIndexer)
-- carry the company root folder id. Verified against real file headers on 2026-07-18:
-- old-era files (both filename formats) are GSM 6.10 WAV, OneCall files are PCM 16-bit.

ALTER TABLE gdrive_file_index
    ADD COLUMN duration_seconds INT NULL DEFAULT NULL
        COMMENT 'Real audio duration read from the WAV header at sync time; NULL = unknown (frontend falls back to a size-based estimate)'
        AFTER size_bytes;

-- OneCall-synced PCM files: 16-bit 8kHz mono = 16000 bytes/sec, 44-byte canonical header.
UPDATE gdrive_file_index
SET duration_seconds = ROUND(GREATEST(size_bytes - 44, 0) / 16000)
WHERE company_folder_id IN ('1DcjFeLhr4Uq2mBA4WcPLRxbqZgiwHKet', '1fnCee-1l7V8aIne1GXeckEhxT5gJfblq')
  AND duration_seconds IS NULL;

-- Old-era GSM 6.10 files: 1625 bytes/sec, ~60-byte header (fmt + fact chunks).
UPDATE gdrive_file_index
SET duration_seconds = ROUND(GREATEST(size_bytes - 60, 0) / 1625)
WHERE company_folder_id IN ('1xKFvE_La3TFBOsxBFsJfiF2-5fGna6so', '17TIfEFWVZIhzbbeYpDAThyK4-V0VBV-G')
  AND duration_seconds IS NULL;
