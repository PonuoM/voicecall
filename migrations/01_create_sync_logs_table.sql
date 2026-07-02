CREATE TABLE IF NOT EXISTS `sync_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `call_id` varchar(100) NOT NULL COMMENT 'OneCall Recording ID or Start Time',
  `drive_file_id` varchar(255) DEFAULT NULL,
  `status` enum('synced','failed') NOT NULL DEFAULT 'failed',
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_call_id` (`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
