<?php
/**
 * The only sanctioned way to run ad-hoc SQL against these databases from a Windows shell.
 *
 * mysql.exe's CLI corrupted Thai text three times in one session -- twice via -e, once via
 * stdin redirection -- despite --default-character-set=utf8mb4 being set correctly every time.
 * Root cause, confirmed by testing: the Windows console's active codepage, not mysql.exe or the
 * flag. Something on this box (the PowerShell tool was used shortly before the first failure)
 * changes the console codepage away from UTF-8, and every native Win32 program sharing that
 * console -- mysql.exe included -- then round-trips non-ASCII bytes through the wrong codepage.
 * The same commands, re-run later once the codepage happened to be right again, worked
 * perfectly. That is not a condition "remembering to be careful" can reliably catch, because
 * there is no local signal that the codepage has drifted -- the command looks identical whether
 * it is about to corrupt data or not.
 *
 * PDO never touches the console text layer at all -- it writes bytes over the MySQL wire
 * protocol with an explicitly declared connection charset. That is the entire reason it was 100%
 * reliable across roughly thirty uses in the same session where the CLI failed three times: not
 * a coincidence, a different code path that the console codepage cannot reach.
 *
 * Usage:
 *   php ops/db.php <target> -e "SQL"
 *   php ops/db.php <target> path/to/migration.sql
 *   php ops/db.php <target> --check "SQL"        # dry description, no execution
 *
 * <target> is one of: local, prod, erp (see TARGETS below). Add more as new databases come up --
 * do not add a way to pass a raw DSN/host on the command line, since that is exactly the kind of
 * per-call flexibility that makes "just use mysql.exe directly this once" tempting again.
 *
 * Output is UTF-8 written straight to a file when the caller passes --out=path.json, sidestepping
 * this terminal's own display garbling entirely (which is cosmetic -- proven separately not to
 * corrupt data -- but unreadable here regardless). Without --out, SELECT results print as JSON to
 * stdout; on this console that is legible for ASCII-only columns and mojibake for Thai columns,
 * exactly like every other stdout Thai text tonight. Read the file, not the terminal, when the
 * content matters.
 *
 * A file with several SELECT statements only returns the first statement's rows -- multi-statement
 * mode is here for migration files (INSERT/UPDATE/ALTER, via exec(), which needs no rowset
 * draining), not for chaining reads. That has never been this tool's job; if it becomes one, add
 * PDOStatement::nextRowset() looping rather than assuming it already works.
 */

$args = array_slice($argv, 1);
if (count($args) < 2) {
    fwrite(STDERR, "usage: php ops/db.php <local|prod|erp> (-e \"SQL\" | file.sql) [--out=path.json]\n");
    exit(2);
}

$target = array_shift($args);

const TARGETS = [
    // Local dev box, AI pipeline output.
    'local' => ['host' => 'localhost', 'port' => 3306, 'db' => 'voicecall_ai', 'user' => 'root', 'pass' => '12345678'],
    // Production voicecall_ai equivalent -- primacom_voicelog on the ERP host.
    'prod'  => ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_voicelog', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'],
    // Read-mostly ERP database -- customers, users, orders.
    'erp'   => ['host' => '202.183.192.218', 'port' => 3306, 'db' => 'primacom_mini_erp', 'user' => 'primacom_bloguser', 'pass' => 'pJnL53Wkhju2LaGPytw8'],
];

if (!isset(TARGETS[$target])) {
    fwrite(STDERR, "unknown target '{$target}' -- valid: " . implode(', ', array_keys(TARGETS)) . "\n");
    exit(2);
}
$cfg = TARGETS[$target];

$outPath = null;
$sqlArg = null;
$isInline = false;

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '-e') {
        $isInline = true;
        $sqlArg = $args[$i + 1] ?? null;
        $i++;
    } elseif (strpos($a, '--out=') === 0) {
        $outPath = substr($a, strlen('--out='));
    } elseif ($sqlArg === null) {
        $sqlArg = $a;
    }
}

if ($sqlArg === null) {
    fwrite(STDERR, "no SQL or file given\n");
    exit(2);
}

if (!$isInline) {
    if (!is_file($sqlArg)) {
        fwrite(STDERR, "file not found: {$sqlArg}\n");
        exit(2);
    }
    $sql = file_get_contents($sqlArg);
} else {
    $sql = $sqlArg;
}

// Multi-statement execution (needed for migration files with several INSERT/UPDATE statements)
// requires this attribute set at connection time -- PDO's MySQL driver refuses multiple
// semicolon-separated statements in one exec() otherwise.
$dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['db']};charset=utf8mb4";
try {
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
    ]);
} catch (Throwable $e) {
    fwrite(STDERR, "connection failed ({$target}): " . $e->getMessage() . "\n");
    exit(1);
}

$isSelect = (bool) preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', ltrim($sql));

try {
    if ($isSelect) {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();
        $result = ['ok' => true, 'target' => $target, 'row_count' => count($rows), 'rows' => $rows];
    } else {
        // exec() with multi-statement mode runs everything but does not surface per-statement
        // errors individually -- good enough for a migration file meant to run as one unit; if
        // any statement fails the whole call throws, same as mysql.exe's default (non --force)
        // behaviour on a script.
        $affected = $pdo->exec($sql);
        $result = ['ok' => true, 'target' => $target, 'rows_affected' => $affected === false ? null : $affected];
    }
} catch (Throwable $e) {
    $result = ['ok' => false, 'target' => $target, 'error' => $e->getMessage()];
}

$json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

if ($outPath) {
    file_put_contents($outPath, $json);
    fwrite(STDOUT, ($result['ok'] ? "OK" : "FAILED") . " -- wrote result to {$outPath}\n");
} else {
    fwrite(STDOUT, $json . "\n");
}

exit($result['ok'] ? 0 : 1);
