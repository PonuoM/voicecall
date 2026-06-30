<?php
/**
 * Minimal .env loader (no Composer dependency, matches plain-PHP project convention).
 * Loads KEY=VALUE pairs from /.env into getenv()-visible process env vars.
 */
function load_env(string $path): void
{
    static $loaded = false;
    if ($loaded || !file_exists($path)) {
        return;
    }
    $loaded = true;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        list($key, $value) = $parts;
        $key = trim($key);
        $value = trim($value);
        // Strip optional surrounding quotes
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[strlen($value) - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}
