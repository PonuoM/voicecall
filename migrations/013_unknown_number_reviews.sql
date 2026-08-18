-- Numbers that keep showing up in a company's call log but match nothing in the ERP — not an
-- employee, not a customer, in any company. Verified real case: 0945554066 placed 201 outbound
-- calls to 105 different numbers over seven months under company 1's Google Drive folder, and is
-- registered nowhere — a completely separate business (rubber flooring) whose calls got filed
-- alongside an agriculture company's because they share whatever phone infrastructure produced the
-- recordings.
--
-- This is not auto-skipped. A brand-new employee who has not been entered into the ERP yet would
-- look identical for their first calls — same "matches nothing" signature — and silently dropping
-- their real sales calls would be worse than the problem this solves. Instead a repeated pattern
-- (>=2 outbound calls to distinct unregistered numbers) becomes a row here for a person to decide,
-- once, and the decision then applies going forward.
CREATE TABLE IF NOT EXISTS unknown_number_reviews (
    id                      INT NOT NULL AUTO_INCREMENT,
    company_id              INT NOT NULL,
    -- Local format (0XXXXXXXXX), matching ErpLookupService::candidateFormats()'s normalized form.
    phone_number            VARCHAR(32) NOT NULL,
    call_count              INT NOT NULL DEFAULT 0,
    distinct_destinations   INT NOT NULL DEFAULT 0,
    first_seen              DATE NULL,
    last_seen               DATE NULL,
    total_seconds           INT NOT NULL DEFAULT 0,
    -- A handful of {date, other_number, duration_seconds} examples, so a reviewer can judge the
    -- pattern without leaving the page — the number alone proves nothing.
    sample_json             TEXT NULL,
    decision                ENUM('pending','skip','allow') NOT NULL DEFAULT 'pending',
    decided_by              INT NULL,
    decided_by_name         VARCHAR(255) NULL,
    decided_at              DATETIME NULL,
    note                    TEXT NULL,
    created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    -- Re-scanning must update one row per number, never duplicate it, and never touch a decision
    -- that has already been made.
    UNIQUE KEY uq_company_number (company_id, phone_number),
    KEY idx_decision (company_id, decision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
