-- Records what each conversation actually costs at the model provider.
--
-- Every MiniMax response already carries a `usage` block — prompt tokens, how many of them were
-- served from cache, completion tokens — and the pipeline has been discarding it on every call.
-- That gap was felt on 19-20 Aug 2026: the provider's own billing export could not answer "what
-- does the voicecall pipeline cost", because a second workload shared the same API key and every
-- hour in that export was a mixture. The best available answer was an estimate built by
-- multiplying a hand-measured 5,432 tokens by a conversation count, and the difference between
-- that estimate and the truth was the whole basis for deciding whether to upgrade a subscription.
--
-- Keeping the numbers we are already being handed removes the guesswork permanently, and costs no
-- extra API calls at all.
--
-- One row per conversation, not per HTTP call: chatJson() retries on malformed JSON, so a single
-- conversation can legitimately spend two completions, and the question worth answering is always
-- "what did this recording cost" rather than "what did this request cost". `calls` records when a
-- retry happened so the retries are still visible.

CREATE TABLE IF NOT EXISTS llm_usage (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id   INT          NULL,
    company_id        INT          NULL,
    endpoint          VARCHAR(64)  NOT NULL,
    model             VARCHAR(64)  NOT NULL DEFAULT '',
    calls             SMALLINT     NOT NULL DEFAULT 1,
    prompt_tokens     INT          NOT NULL DEFAULT 0,
    cached_tokens     INT          NOT NULL DEFAULT 0,
    completion_tokens INT          NOT NULL DEFAULT 0,
    total_tokens      INT          NOT NULL DEFAULT 0,
    seconds           DECIMAL(7,2) NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Answers "how much did we spend in this 5-hour window", which is the shape of every quota
    -- question this table exists for.
    INDEX idx_usage_created (created_at),
    INDEX idx_usage_conv (conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
