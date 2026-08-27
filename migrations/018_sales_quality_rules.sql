-- DO NOT run this through mysql.exe's CLI on Windows (`mysql ... < this_file.sql`) -- it silently
-- mangles Thai text regardless of --default-character-set, with no error raised (see the same
-- warning on 014). Apply from phpMyAdmin, the Linux mysql client, or PDO.
--
-- Five sales-quality rules x 2 companies (เทพมงคล = 1, แสนราชสีห์ = 2).
--
-- These are the compliance-side counterpart to migration 017. 017 gives the supervisor a *score*;
-- these give the pipeline specific, checkable things to flag, drawn from patterns seen in real
-- outbound follow-up calls plus the "The New Buying Game" course framework (source material is kept out of git, see .gitignore):
--
--   negative_talk         - the agent badmouthing a competitor or the customer themselves. Feeds
--                           summaries.negative_talk_detected from 017.
--   objection_handling    - the two anti-patterns that cost the most revenue: killing a "แพง"
--                           objection with an instant discount instead of diagnosing it, and
--                           answering "ใช้ของเจ้าอื่นอยู่" passively instead of asking a follow-up.
--   communication_quality - Anti-Dead Air: an important answer that ends without a next step
--                           leaves the call to die on its own.
--
-- `description` is the literal instruction handed to the model, so it is written to be unambiguous
-- about *when* to flag rather than to read well.
--
-- Idempotent by DELETE-then-INSERT, not by ON DUPLICATE KEY UPDATE. compliance_rules has no
-- unique key on (company_id, rule_name) -- only PRIMARY(id) -- so an upsert here would never
-- match and every re-run would append another ten rows. That is not hypothetical: the one-shot
-- bootstrap this migration replaces claimed to be idempotent on exactly that basis, so any host
-- it was triggered on more than once already has duplicates. The DELETE below clears them out.
--
-- Deleting by rule_name is safe because these ten rows are seeded, never hand-edited: the
-- supervisor UI edits severity and active, which this migration re-asserts anyway, and a rule
-- someone renamed is a different rule that this will not touch.

DELETE FROM compliance_rules
WHERE company_id IN (1, 2)
  AND rule_name IN (
      'ห้ามก่นด่าคู่แข่ง',
      'ห้ามด่า/เหยียดลูกค้า',
      'ห้ามลดราคาทันทีเมื่อลูกค้าว่าแพง',
      'ห้าม passive เมื่อลูกค้าบอกใช้ของคู่แข่ง',
      'คำตอบสำคัญต้องมี Value + Question (Anti-Dead Air)'
  );

INSERT INTO compliance_rules
    (company_id, rule_name, category, description, rule_type, severity_default, active)
VALUES
    (1, 'ห้ามก่นด่าคู่แข่ง', 'negative_talk',
     'Agent ห้ามพูดชื่อแบรนด์คู่แข่งเพื่อทำให้สินค้าตัวเองดูดี (เช่น "ยี่ห้อนั้นไม่ดี", "ของเขาผสมสารเคมี", "สู้ของเราไม่ได้") หากลูกค้าเอ่ยถึงแบรนด์อื่น ให้ถามความเห็นลูกค้าแบบเป็นกลาง ไม่ใช่ด่าทอ',
     'custom', 'critical', 1),
    (1, 'ห้ามด่า/เหยียดลูกค้า', 'negative_talk',
     'Agent ห้ามพูดเหยียด ลูกค้า ด่าว่าลูกค้า หรือพูดในแง่ลบเกี่ยวกับสภาพความเป็นอยู่/ไร่/ฐานะ/เพศ/อายุของลูกค้า รวมถึงคำหยาบคายหรือคำพูดที่แสดงว่าลูกค้าโง่/ไม่รู้/ไม่เข้าใจ',
     'custom', 'critical', 1),
    (1, 'ห้ามลดราคาทันทีเมื่อลูกค้าว่าแพง', 'objection_handling',
     'เมื่อลูกค้าบอก "แพง" หรือทุกข้อความที่แสดงว่าราคาเป็นอุปสรรค ห้ามเสนอส่วนลด/ลดราคาทันทีเพื่อตัดปม ต้องถามบริบทก่อนว่าเทียบกับอะไร งบประมาณเท่าไหร่ หรือปัจจัยอะไรทำให้รู้สึกแพง แล้วค่อยตอบ',
     'custom', 'high', 1),
    (1, 'ห้าม passive เมื่อลูกค้าบอกใช้ของคู่แข่ง', 'objection_handling',
     'เมื่อลูกค้าบอกว่าซื้อหรือใช้ปุ๋ย/สินค้าของแบรนด์อื่นอยู่แล้ว ห้ามตอบแบบ passive เช่น "ไม่เป็นไร รอบหน้า" หรือ "เอาไว้ค่อยคุยกัน" ต้องถาม follow-up เช่น "ได้ผลเป็นยังไงบ้างคะ" หรือ "ใช้หมดเมื่อไหร่คะ ถ้าหมดลองเปรียบเทียบดูนะคะ"',
     'custom', 'high', 1),
    (1, 'คำตอบสำคัญต้องมี Value + Question (Anti-Dead Air)', 'communication_quality',
     'ทุกคำตอบที่เป็น "คำตอบสำคัญ" (เรื่องราคา, สินค้า, วันส่ง, โปรโมชั่น) ต้องไม่จบแค่ตอบคำถามอย่างเดียว ต้องมี 3 ส่วน: (1) Value/บริบทเสริม เช่น ขนาด ผลลัพธ์ หรือคุณสมบัติเด่น (2) Continue เชื่อมต่อด้วยประโยคเป็นมิตร (3) Question ปิดด้วยคำถามเชิงรุก เพื่อไม่ให้แชท/สายเงียบตาย ยกเว้น turn สุดท้ายของการสนทนา (closing) ไม่ต้องมี next step',
     'custom', 'medium', 1),

    (2, 'ห้ามก่นด่าคู่แข่ง', 'negative_talk',
     'Agent ห้ามพูดชื่อแบรนด์คู่แข่งเพื่อทำให้สินค้าตัวเองดูดี (เช่น "ยี่ห้อนั้นไม่ดี", "ของเขาผสมสารเคมี", "สู้ของเราไม่ได้") หากลูกค้าเอ่ยถึงแบรนด์อื่น ให้ถามความเห็นลูกค้าแบบเป็นกลาง ไม่ใช่ด่าทอ',
     'custom', 'critical', 1),
    (2, 'ห้ามด่า/เหยียดลูกค้า', 'negative_talk',
     'Agent ห้ามพูดเหยียด ลูกค้า ด่าว่าลูกค้า หรือพูดในแง่ลบเกี่ยวกับสภาพความเป็นอยู่/ไร่/ฐานะ/เพศ/อายุของลูกค้า รวมถึงคำหยาบคายหรือคำพูดที่แสดงว่าลูกค้าโง่/ไม่รู้/ไม่เข้าใจ',
     'custom', 'critical', 1),
    (2, 'ห้ามลดราคาทันทีเมื่อลูกค้าว่าแพง', 'objection_handling',
     'เมื่อลูกค้าบอก "แพง" หรือทุกข้อความที่แสดงว่าราคาเป็นอุปสรรค ห้ามเสนอส่วนลด/ลดราคาทันทีเพื่อตัดปม ต้องถามบริบทก่อนว่าเทียบกับอะไร งบประมาณเท่าไหร่ หรือปัจจัยอะไรทำให้รู้สึกแพง แล้วค่อยตอบ',
     'custom', 'high', 1),
    (2, 'ห้าม passive เมื่อลูกค้าบอกใช้ของคู่แข่ง', 'objection_handling',
     'เมื่อลูกค้าบอกว่าซื้อหรือใช้ปุ๋ย/สินค้าของแบรนด์อื่นอยู่แล้ว ห้ามตอบแบบ passive เช่น "ไม่เป็นไร รอบหน้า" หรือ "เอาไว้ค่อยคุยกัน" ต้องถาม follow-up เช่น "ได้ผลเป็นยังไงบ้างคะ" หรือ "ใช้หมดเมื่อไหร่คะ ถ้าหมดลองเปรียบเทียบดูนะคะ"',
     'custom', 'high', 1),
    (2, 'คำตอบสำคัญต้องมี Value + Question (Anti-Dead Air)', 'communication_quality',
     'ทุกคำตอบที่เป็น "คำตอบสำคัญ" (เรื่องราคา, สินค้า, วันส่ง, โปรโมชั่น) ต้องไม่จบแค่ตอบคำถามอย่างเดียว ต้องมี 3 ส่วน: (1) Value/บริบทเสริม เช่น ขนาด ผลลัพธ์ หรือคุณสมบัติเด่น (2) Continue เชื่อมต่อด้วยประโยคเป็นมิตร (3) Question ปิดด้วยคำถามเชิงรุก เพื่อไม่ให้แชท/สายเงียบตาย ยกเว้น turn สุดท้ายของการสนทนา (closing) ไม่ต้องมี next step',
     'custom', 'medium', 1)
;
