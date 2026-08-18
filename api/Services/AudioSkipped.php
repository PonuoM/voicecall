<?php

/**
 * Thrown when the pipeline declines a recording on purpose, rather than failing at it.
 *
 * The distinction is the whole point. A recording that is 12 seconds long, or silent, or carries no
 * intelligible speech, has been handled correctly when the pipeline refuses it — that is the guard
 * working. Reporting it the same way as a Drive timeout or a provider returning malformed JSON
 * leaves an operator unable to answer the only question that matters when looking at a failure
 * list: is this a bug, or is this the rule?
 *
 * ConversationPipeline catches this separately and records status 'skipped', keeping 'failed' for
 * things that genuinely went wrong and might be worth retrying.
 */
class AudioSkipped extends RuntimeException
{
}
