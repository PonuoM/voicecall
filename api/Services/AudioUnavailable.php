<?php

require_once __DIR__ . '/WorkDeferred.php';

/**
 * The audio could not be fetched — Google Drive refusing this server's IP being the case that has
 * actually happened, repeatedly, on 18-19 Aug 2026.
 *
 * See WorkDeferred for why this must not be recorded as a failure. This subclass exists so a
 * reader of the failure list can tell "we could not get the recording" apart from "we got it and
 * the provider fell over", which are fixed in completely different places.
 */
class AudioUnavailable extends WorkDeferred
{
}
