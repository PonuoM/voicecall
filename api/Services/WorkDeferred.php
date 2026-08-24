<?php

/**
 * Thrown when a recording could not be processed for a reason that has nothing to do with the
 * recording itself — the server's IP being blocked, a provider's quota running out, a timeout.
 *
 * The distinction is not cosmetic, because 'failed' is terminal in this system and nothing ever
 * moves a row out of it: backlog_drain only considers files with no conversation row at all,
 * process_pending only reads 'pending', and reap_stale only frees 'transcribing'/'analyzing'.
 * Recording a passing obstacle as a failure destroys the conversation permanently. That cost 148
 * recordings to a Drive IP block and then 1,509 more to a MiniMax quota ceiling, all on 19 Aug
 * 2026, every one of them recoverable and none of them recovered.
 *
 * When classification is uncertain, throw this rather than a plain RuntimeException. The two
 * mistakes are not symmetrical: deferring something that was genuinely broken costs one retry,
 * while failing something that was merely blocked costs the recording for good.
 *
 * ConversationPipeline catches this separately and returns the row to 'pending'. AudioSkipped is
 * the opposite of it: a decision meant to be final, versus an obstacle meant to pass.
 */
class WorkDeferred extends RuntimeException
{
}
