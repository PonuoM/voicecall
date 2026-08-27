-- Sales performance scoring + coaching + negative-talk detection.
--
-- Before this migration the UnifiedPipelineAgent only stored the summary text + entity
-- extraction. The salesperson got back a written paragraph but nothing quantitative - a
-- supervisor scanning 100 conversations had to read them all to find weak calls.
--
-- This adds the columns that turn the prompt's sales_assessment object into queryable data:
--
--   sales_performance_score      - single 1-5 rollup the table can sort by
--   sales_score_breakdown        - the five dimensions (CONNECT/DISCOVER/PRESENT/HANDLE/CLOSE)
--   sales_score_rationale        - one-sentence Thai explanation of the overall score
--   coaching_recommendations     - JSON array of concrete coaching tips for this employee
--   negative_talk_detected       - boolean: did the agent badmouth the customer or a competitor
--   negative_talk_examples       - verbatim Thai quotes that triggered the flag
--   lead_grade                   - hot/warm/cold: how ready this customer actually was
--   forces_present               - which of the 5 buying forces (pain/desire/value/trust/urgency)
--                                  the agent managed to surface during the call
--   resistances_addressed        - which of the 6 resistances (price/risk/doubt/friction/comparison/delay)
--                                  the agent managed to reduce
--
-- Existing rows stay NULL - the column set was always meant to be optional. The pipeline will
-- fill them on the next run for any new (or re-analyzed) conversation.
--
-- ALTER (not CREATE) so this can be applied on a live DB without touching unrelated tables.
USE voicecall_ai;

ALTER TABLE summaries
    ADD COLUMN sales_performance_score      TINYINT       NULL COMMENT 'overall 1-5 rollup',
    ADD COLUMN sales_score_breakdown        JSON          NULL COMMENT '{connect,discover,present,handle,close} each 1-5',
    ADD COLUMN sales_score_rationale        TEXT          NULL COMMENT 'one-sentence Thai reason for the overall score',
    ADD COLUMN coaching_recommendations     JSON          NULL COMMENT 'array of concrete coaching tips in Thai',
    ADD COLUMN negative_talk_detected       TINYINT(1)    NULL COMMENT '1 if agent badmouthed customer/competitor/brand',
    ADD COLUMN negative_talk_examples       JSON          NULL COMMENT 'verbatim Thai quotes that triggered negative_talk_detected',
    ADD COLUMN lead_grade                   ENUM('hot','warm','cold') NULL COMMENT 'how ready this customer was',
    ADD COLUMN sales_forces_present         JSON          NULL COMMENT 'which of pain/desire/value/trust/urgency the agent surfaced',
    ADD COLUMN sales_resistances_addressed  JSON          NULL COMMENT 'which of price/risk/doubt/friction/comparison/delay the agent reduced',
    ADD INDEX idx_sales_perf_score (sales_performance_score),
    ADD INDEX idx_lead_grade (lead_grade),
    ADD INDEX idx_negative_talk (negative_talk_detected);
