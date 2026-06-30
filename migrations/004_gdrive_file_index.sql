-- Server-side cache of the Google Drive recording listing. The dashboard's live recursive Drive
-- scan (index.html) has to walk a folder tree where ~96% of files (100k+) sit in one flat folder,
-- paginated 1000-at-a-time *sequentially* (each page's nextPageToken depends on the previous
-- page's response) — 100+ sequential round-trips cold, which is why the dashboard was taking
-- 60-120+ seconds to load. A cron (api/cron/sync_gdrive_index.php) does that same walk
-- server-side on a schedule; the dashboard then reads this table instead (instant) via
-- GET /api/index.php/drive-index.
USE voicecall_ai;

CREATE TABLE gdrive_file_index (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_folder_id VARCHAR(64) NOT NULL COMMENT 'Drive folder id for the "Company = N" folder this file lives under',
  company_id INT NOT NULL COMMENT 'parsed from the "Company = N" folder name',
  gdrive_file_id VARCHAR(64) NOT NULL,
  filename VARCHAR(500) NOT NULL,
  call_code VARCHAR(20) NULL,
  call_date DATE NULL,
  call_time TIME NULL,
  caller_phone VARCHAR(32) NULL,
  receiver_phone VARCHAR(32) NULL,
  direction ENUM('IN','OUT') NULL,
  size_bytes INT NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'updated every sync run a file is still found in Drive',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_gdrive_file_id (gdrive_file_id),
  KEY idx_company (company_id),
  KEY idx_call_date (call_date),
  KEY idx_caller (caller_phone),
  KEY idx_receiver (receiver_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gdrive_sync_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  files_found INT NULL,
  files_upserted INT NULL,
  status ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  error_message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
