-- DO NOT run this through mysql.exe's CLI on Windows (`mysql ... < this_file.sql`) -- confirmed
-- three times tonight that it silently mangles Thai text regardless of --default-character-set,
-- with no error raised. The rows actually in production were inserted via PHP+PDO prepared
-- statements instead (see the fix script this migration describes); this file documents intent
-- and is safe to apply from a client that handles UTF-8 stdin correctly (Linux mysql client,
-- phpMyAdmin, or PDO).
--
-- Company 2 (พรีออนิค) had zero compliance rules — 101 completed conversations went through the
-- pipeline with no compliance checking at all, because compliance_rules only ever had rows for
-- company 1.
--
-- Product catalog confirms company 2 sells the same category of product as company 1 (organic
-- fertilizer, brand "แสนราชสีห์" — สิงห์ทอง, สิงห์ชมพู, สารปรับปรุงดิน, ...), so the same three rules
-- apply as-is; only the accepted brand name for "Company identification" differs. Confirmed against
-- a real company 2 transcript (conversation 14): "หนูติดต่อจากปุ๋ยแสนราชสีห์นะจ๊ะ" — the agent said
-- "แสนราชสีห์" exactly.
INSERT INTO compliance_rules
    (company_id, rule_name, category, description, rule_type, severity_default, prohibited_words, required_phrases, active)
VALUES
    (2, 'No guaranteed-result claims', 'misleading_claims',
     'Agent must not promise a guaranteed outcome from a product (e.g. guaranteed yield increase, guaranteed cure for crop disease). Agricultural/pesticide results legitimately vary by conditions.',
     'prohibited_words', 'critical',
     '["รับประกันผล 100%", "การันตีผลผลิต", "การันตีหายแน่นอน", "รับประกันได้ผลแน่นอน"]', NULL, 1),

    (2, 'Price and shipping cost disclosure', 'missing_disclosure',
     'When a product, order, or price is discussed, the agent must clearly state the price and whether shipping cost is included.',
     'required_phrase', 'medium',
     NULL, '["ราคา", "ค่าส่ง"]', 1),

    (2, 'Company identification at call start', 'missing_script',
     'Agent must identify the company name within the opening greeting of the call.',
     'required_phrase', 'low',
     NULL, '["แสนราชสีห์"]', 1);
