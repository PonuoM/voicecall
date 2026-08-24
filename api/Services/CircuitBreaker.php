<?php

/**
 * "This dependency just told us to go away — stop asking for a while", shared by every dependency
 * that can say it, keyed by name so each keeps its own independent cooldown.
 *
 * This exists because the same outage happened twice in one day, 19 Aug 2026, with two different
 * dependencies and identical consequences. Google Drive blocked the server's IP; the backlog drain
 * discovered that fact one recording at a time and burned 148 of them. Once that was fixed
 * throughput went up roughly fortyfold, which walked the pipeline straight into MiniMax's token
 * plan ceiling four hours later — and the drain discovered *that* one recording at a time too, for
 * two hours, 1,463 of them, each having already paid for a Drive download and a full transcription
 * before dying on the last call. The second outage cost far more than the first precisely because
 * the breaker built for the first was welded to AudioFetcher and could not be reused.
 *
 * So the mechanism lives here on its own. The rule it encodes: the first caller to hit a wall
 * records it, and every other caller reads that record instead of re-proving it.
 */
class CircuitBreaker
{
    private static function path(string $name): string
    {
        return LOG_DIR . '/circuit_' . preg_replace('/[^a-z0-9_]/i', '', $name) . '.json';
    }

    private static function read(string $name): ?array
    {
        $path = self::path($name);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /** @return int|null Unix timestamp the cooldown ends, or null if this dependency is usable. */
    public static function openUntil(string $name): ?int
    {
        $state = self::read($name);
        if ($state && (int) ($state['blocked_until'] ?? 0) > time()) {
            return (int) $state['blocked_until'];
        }
        return null;
    }

    /**
     * Why the breaker is open, for the operator reading a worker log at 2am.
     *
     * "Stopped early" is not actionable on its own: out of quota means wait, while a rejected key
     * means go and fix something, and those two look identical from the outside.
     */
    public static function reasonFor(string $name): string
    {
        $state = self::read($name);
        return (string) ($state['reason'] ?? 'unavailable');
    }

    /**
     * Records a block and returns when it lifts.
     *
     * Pass $maxCooldownSeconds larger than $baseCooldownSeconds to double the wait on each repeat
     * trip — right for a block whose length is unknown and possibly provoked by the retrying
     * itself, as Drive's is. Pass them equal for a flat wait, right for a quota that resets on
     * someone else's clock: escalating there just means sitting idle long after it came back.
     */
    public static function trip(string $name, int $baseCooldownSeconds, int $maxCooldownSeconds, string $reason = 'unavailable'): int
    {
        $state = self::read($name) ?: ['consecutive_blocks' => 0];
        $blocks = ((int) ($state['consecutive_blocks'] ?? 0)) + 1;
        $cooldown = min($maxCooldownSeconds, $baseCooldownSeconds * (2 ** ($blocks - 1)));
        $until = time() + $cooldown;
        file_put_contents(self::path($name), json_encode([
            'consecutive_blocks' => $blocks,
            'blocked_until' => $until,
            'tripped_at' => date('Y-m-d H:i:s'),
            'reason' => $reason,
        ]));
        return $until;
    }

    /** A real success means the block has lifted — the next trip starts the ladder over. */
    public static function reset(string $name): void
    {
        $path = self::path($name);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
