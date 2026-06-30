-- voicecall_ai — local DB for the AI Voice Intelligence pipeline.
-- Deliberately has NO companies/users/customers/products tables: those live in the remote
-- primacom_mini_erp DB and are looked up read-only by phone number at the PHP layer
-- (see api/Services/ErpLookupService.php). All "owner" columns below are plain INT/VARCHAR
-- caches of that remote data, never FKs (cross-server FK is not possible anyway).

CREATE DATABASE IF NOT EXISTS voicecall_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE voicecall_ai;

-- ============================================================
-- Auth (bearer tokens minted by login.php, snapshotting ERP identity)
-- ============================================================

CREATE TABLE api_tokens (
  id INT AUTO_INCREMENT PRIMARY KEY,
  erp_user_id INT NOT NULL,
  erp_company_id INT NULL,
  erp_role_id INT NULL,
  erp_is_super_admin TINYINT(1) NOT NULL DEFAULT 0,
  erp_username VARCHAR(64) NULL,
  erp_full_name VARCHAR(255) NULL,
  token VARCHAR(128) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_api_tokens_token (token),
  KEY idx_api_tokens_user (erp_user_id),
  KEY idx_api_tokens_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Core pipeline: Conversations (one row per call recording)
-- ============================================================

CREATE TABLE conversations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL COMMENT 'erp company id (primacom_mini_erp.companies.id)',
  source ENUM('gdrive','local') NOT NULL DEFAULT 'gdrive',
  audio_ref VARCHAR(500) NOT NULL COMMENT 'gdrive fileId when source=gdrive, relative file path when source=local',
  call_code VARCHAR(20) NULL COMMENT 'short code from filename, e.g. EDIB (not globally unique)',
  call_date DATE NULL,
  call_time TIME NULL,
  caller_phone VARCHAR(32) NULL,
  receiver_phone VARCHAR(32) NULL,
  direction ENUM('IN','OUT') NULL,
  size_bytes INT NULL,
  duration_seconds DECIMAL(10,2) NULL COMMENT 'heuristic until STT runs, then overwritten with real Whisper duration',
  erp_customer_id INT NULL COMMENT 'cache: primacom_mini_erp.customers.customer_id',
  erp_customer_name VARCHAR(255) NULL,
  erp_employee_id INT NULL COMMENT 'cache: primacom_mini_erp.users.id',
  erp_employee_name VARCHAR(255) NULL,
  status ENUM('pending','transcribing','analyzing','extracting','checking_compliance','indexing','completed','failed') NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conv_source_ref (source, audio_ref),
  KEY idx_conv_company (company_id),
  KEY idx_conv_status (status),
  KEY idx_conv_call_date (call_date),
  KEY idx_conv_caller (caller_phone),
  KEY idx_conv_receiver (receiver_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transcripts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  full_text LONGTEXT NOT NULL,
  language VARCHAR(10) NULL,
  word_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transcript_conv (conversation_id),
  CONSTRAINT fk_transcript_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE speakers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  speaker_label VARCHAR(50) NOT NULL,
  role ENUM('customer','employee','unknown') NOT NULL DEFAULT 'unknown',
  identified_name VARCHAR(255) NULL,
  KEY idx_speakers_conv (conversation_id),
  CONSTRAINT fk_speakers_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE transcript_segments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transcript_id INT NOT NULL,
  conversation_id INT NOT NULL,
  sequence INT NOT NULL,
  speaker_label VARCHAR(50) NOT NULL,
  start_time DECIMAL(10,2) NULL COMMENT 'NULL: OpenRouter STT has no per-segment timestamps, turns are LLM-inferred from plain text',
  end_time DECIMAL(10,2) NULL,
  text TEXT NOT NULL,
  KEY idx_segments_transcript (transcript_id),
  KEY idx_segments_conv (conversation_id),
  CONSTRAINT fk_segments_transcript FOREIGN KEY (transcript_id) REFERENCES transcripts(id) ON DELETE CASCADE,
  CONSTRAINT fk_segments_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- AI Conversation Analysis (Agent 2)
-- ============================================================

CREATE TABLE summaries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  executive_summary TEXT NOT NULL,
  key_topics JSON NULL,
  action_items JSON NULL,
  decisions_made JSON NULL,
  follow_up_tasks JSON NULL,
  customer_intent VARCHAR(255) NULL,
  customer_sentiment ENUM('positive','neutral','negative','mixed') NULL,
  important_keywords JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_summary_conv (conversation_id),
  CONSTRAINT fk_summary_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE keywords (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  keyword VARCHAR(255) NOT NULL,
  weight DECIMAL(5,2) NOT NULL DEFAULT 1.0,
  KEY idx_keywords_conv (conversation_id),
  KEY idx_keywords_keyword (keyword),
  CONSTRAINT fk_keywords_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE action_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  description TEXT NOT NULL,
  owner VARCHAR(255) NULL,
  due_date DATE NULL,
  status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_action_items_conv (conversation_id),
  CONSTRAINT fk_action_items_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE conversation_tags (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  tag VARCHAR(100) NOT NULL,
  KEY idx_tags_conv (conversation_id),
  KEY idx_tags_tag (tag),
  CONSTRAINT fk_tags_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Structured Data Extraction (Agent 3)
-- ============================================================

CREATE TABLE extracted_entities (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  customer_name VARCHAR(255) NULL,
  employee_name VARCHAR(255) NULL,
  company_name VARCHAR(255) NULL,
  phone VARCHAR(50) NULL,
  email VARCHAR(255) NULL,
  conv_date DATE NULL,
  conv_time TIME NULL,
  product VARCHAR(255) NULL,
  promotion VARCHAR(255) NULL,
  price DECIMAL(12,2) NULL,
  order_info JSON NULL,
  complaint TEXT NULL,
  appointment_info JSON NULL,
  request TEXT NULL,
  issue_category VARCHAR(100) NULL,
  priority ENUM('low','medium','high','urgent') NULL,
  sentiment_score DECIMAL(4,3) NULL,
  raw_json LONGTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_entities_conv (conversation_id),
  KEY idx_entities_phone (phone),
  KEY idx_entities_priority (priority),
  CONSTRAINT fk_entities_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Compliance Monitoring (Agent 4)
-- ============================================================

CREATE TABLE compliance_rules (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL COMMENT 'erp company id',
  rule_name VARCHAR(255) NOT NULL,
  category VARCHAR(100) NOT NULL,
  description TEXT NULL,
  rule_type ENUM('prohibited_words','required_phrase','required_disclosure','custom') NOT NULL DEFAULT 'custom',
  severity_default ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  prohibited_words JSON NULL,
  required_phrases JSON NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_rules_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE compliance_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  overall_status ENUM('compliant','minor_issues','violations_found') NOT NULL DEFAULT 'compliant',
  violation_count INT NOT NULL DEFAULT 0,
  model_used VARCHAR(100) NULL,
  checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report_conv (conversation_id),
  CONSTRAINT fk_report_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE violations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  compliance_report_id INT NOT NULL,
  conversation_id INT NOT NULL,
  rule_id INT NULL,
  rule_name VARCHAR(255) NOT NULL,
  severity ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  evidence TEXT NULL,
  explanation TEXT NULL,
  occurred_at_seconds DECIMAL(10,2) NULL,
  suggested_improvement TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_violations_report (compliance_report_id),
  KEY idx_violations_conv (conversation_id),
  KEY idx_violations_severity (severity),
  CONSTRAINT fk_violations_report FOREIGN KEY (compliance_report_id) REFERENCES compliance_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_violations_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_violations_rule FOREIGN KEY (rule_id) REFERENCES compliance_rules(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Knowledge Base / RAG (Agent 5 + Agent 6)
-- ============================================================

CREATE TABLE knowledge_chunks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT NOT NULL,
  chunk_index INT NOT NULL,
  chunk_text TEXT NOT NULL,
  embedding LONGTEXT NULL COMMENT 'JSON float array; cosine similarity computed in PHP (no native vector type in MySQL)',
  embedding_model VARCHAR(100) NULL,
  token_count INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_chunks_conv (conversation_id),
  CONSTRAINT fk_chunks_conv FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE assistant_queries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NOT NULL COMMENT 'erp company id',
  erp_user_id INT NULL,
  question TEXT NOT NULL,
  answer TEXT NULL,
  sources JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_assistant_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Audit
-- ============================================================

CREATE TABLE audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  company_id INT NULL,
  erp_user_id INT NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id INT NULL,
  details JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_company (company_id),
  KEY idx_audit_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
