<?php
/**
 * Minimal .env loader (no Composer dependency, matches plain-PHP project convention).
 * Loads KEY=VALUE pairs from /.env into getenv()-visible process env vars.
 */
function load_env(string $path): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    foreach (parse_env_file($path) as $key => $value) {
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

/**
 * The .env file as a plain KEY => value array.
 *
 * Split out of load_env() because api/sync/* needs the values as an array rather than as process
 * env vars, and was reading them with parse_ini_file() — which fails outright on this .env, since
 * PHP does not accept `#` comments in ini files and the very first line is
 * `# Local voicecall_ai DB (AI pipeline output)`. That threw a syntax-error warning and returned
 * false, so every one of those endpoints had been silently running on its hardcoded fallbacks
 * (localhost / root / empty password) instead of the configured credentials.
 */
function parse_env_file(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    $vars = [];
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
        $vars[$key] = $value;
    }
    return $vars;
}
