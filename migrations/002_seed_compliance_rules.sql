-- Placeholder compliance rules for company_id=1 — generic sales/telemarketing rules, not yet
-- reviewed against this company's real policy. Edit via the compliance_rules table (or the
-- ComplianceController CRUD endpoints once built) once real rules are confirmed.
USE voicecall_ai;

INSERT INTO compliance_rules (company_id, rule_name, category, description, rule_type, severity_default, prohibited_words, required_phrases) VALUES
(1, 'No guaranteed-result claims', 'misleading_claims',
 'Agent must not promise a guaranteed outcome from a product (e.g. guaranteed yield increase, guaranteed cure for crop disease). Agricultural/pesticide results legitimately vary by conditions.',
 'prohibited_words', 'critical',
 JSON_ARRAY('รับประกันผล 100%', 'การันตีผลผลิต', 'การันตีหายแน่นอน', 'รับประกันได้ผลแน่นอน'),
 NULL),
(1, 'Price and shipping cost disclosure', 'missing_disclosure',
 'When a product, order, or price is discussed, the agent must clearly state the price and whether shipping cost is included.',
 'required_phrase', 'medium',
 NULL,
 JSON_ARRAY('ราคา', 'ค่าส่ง')),
(1, 'Company identification at call start', 'missing_script',
 'Agent must identify the company name within the opening greeting of the call.',
 'required_phrase', 'low',
 NULL,
 JSON_ARRAY('เทพมงคล'));
