<?php
/**
 * Non-destructive DB state probe — determines:
 *   - Is the Moodle mdl_config table present?
 *   - Is the DB EMPTY (no mdl_ tables)?
 *   - If installed, what is the stored release/version/branch?
 *   - Are there any user/course rows (i.e. real data)?
 *
 * Prints a 1-line decision string at the end that deployment scripts
 * can branch on:
 *     STATE=EMPTY | STATE=INSTALLED_MATCH | STATE=INSTALLED_UPGRADE_REQUIRED
 *     | STATE=INSTALLED_DOWNGRADE_UNSAFE | STATE=ERROR
 *     BACKUP_REQUIRED_BEFORE_NEXT_STEP=YES|NO
 *
 * Uses the same .env parser + DB connection flags as scripts/ulms_test_db_ssl.php
 * (TLS verify-full via OS CA bundle when DB_SSL_MODE=verify-full).
 *
 * 100% pure-read operations.  No writes, no DDL, no TRUNCATE/DROP, no ALTER.
 * Safe to run at any point in the deployment pipeline.
 *
 * Usage:
 *   php scripts/ulms_db_state_probe.php                 # reads ./..env or ../.env
 *   php scripts/ulms_db_state_probe.php /var/www/.env    # production path
 *
 * Exit codes:
 *   0  DB reachable and STATE derived correctly
 *   1  Connection/extension error
 *   2  DB_SSL_MODE invalid value (matches test harness)
 */

declare(strict_types=1);

$envPath = $argv[1] ?? null;
if ($envPath === null) {
    $candidates = [
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__) . '/.env',
        __DIR__ . '/.env',
    ];
    foreach ($candidates as $c) {
        if (is_file($c)) {
            $envPath = $c;
            break;
        }
    }
}

function ulms_db_probe_load_env(string $file): void {
    if (!is_file($file)) {
        return;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = ltrim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false || $eq === 0) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $val = rtrim(substr($line, $eq + 1));
        if (strlen($val) >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[-1] === $val[0]) {
            $val = substr($val, 1, -1);
        } else {
            $comment = preg_match('/\s+#/', $val, $m, PREG_OFFSET_CAPTURE) ? (int)$m[0][1] : false;
            if ($comment !== false) {
                $val = rtrim(substr($val, 0, $comment));
            }
        }
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
        }
    }
}

function ulms_db_probe_env(string $k, $d = null) {
    if (array_key_exists($k, $_ENV) && $_ENV[$k] !== '') {
        return $_ENV[$k];
    }
    $v = getenv($k);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    return $d;
}

if ($envPath !== null) {
    ulms_db_probe_load_env($envPath);
}

echo "=================================================\n";
echo "ULMS DB Install-vs-Upgrade state probe (read-only)\n";
echo "=================================================\n";
echo "PHP version   : " . PHP_VERSION . "\n";
echo "MySQLi loaded : " . (extension_loaded('mysqli') ? 'YES' : 'NO') . "\n";
echo "Env file      : " . ($envPath ?? '(none found — using runtime $_ENV)') . "\n";
if (!extension_loaded('mysqli')) {
    fwrite(STDERR, "[FATAL] mysqli extension missing\n");
    exit(1);
}

$dbHost    = (string)ulms_db_probe_env('DB_HOST', '127.0.0.1');
$dbPort    = (int)((string)ulms_db_probe_env('DB_PORT', '3306'));
$dbName    = (string)ulms_db_probe_env('DB_NAME', 'ulms');
$dbUser    = (string)ulms_db_probe_env('DB_USER', 'ulms_rw');
$dbPass    = (string)ulms_db_probe_env('DB_PASSWORD', '');
$dbPrefix  = (string)ulms_db_probe_env('DB_PREFIX', (string)ulms_db_probe_env('MDL_PREFIX', 'mdl_'));
$dbSslMode = strtolower(trim((string)ulms_db_probe_env('DB_SSL_MODE', '')));

$allowed = ['require', 'verify-full'];
if ($dbSslMode !== '' && !in_array($dbSslMode, $allowed, true)) {
    fwrite(STDERR, "[FATAL] Invalid DB_SSL_MODE='$dbSslMode'. Allowed: " . implode(', ', $allowed) . "\n");
    exit(2);
}

$flags = 0;
if ($dbSslMode !== '') {
    $flags |= 0x800; // MYSQLI_CLIENT_SSL
    if ($dbSslMode === 'verify-full') {
        $flags |= 0x40000000; // MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT
    }
}

echo "DB_HOST       : $dbHost\n";
echo "DB_PORT       : $dbPort\n";
echo "DB_NAME       : $dbName\n";
echo "DB_USER       : $dbUser\n";
echo "DB_PREFIX     : $dbPrefix\n";
echo "DB_SSL_MODE   : " . ($dbSslMode === '' ? '(off — plain TCP)' : $dbSslMode) . "\n";
echo "Final flags   : 0x" . dechex($flags) . "\n";

// Connect without any writes or schema operations.
mysqli_report(MYSQLI_REPORT_OFF);
$mysql = mysqli_init();
if ($mysql === false) {
    fwrite(STDERR, "[FATAL] mysqli_init() failed\n");
    exit(1);
}
mysqli_options($mysql, MYSQLI_OPT_CONNECT_TIMEOUT, 10);
mysqli_options($mysql, MYSQLI_OPT_READ_TIMEOUT, 15);
$connected = @mysqli_real_connect($mysql, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, null, $flags);
if (!$connected) {
    fwrite(STDERR, "[FATAL] mysqli_real_connect() failed: " . mysqli_connect_error() . "\n");
    exit(1);
}
echo "\n[CONNECT OK] TLS info:\n";
$sslStatus = run_query_map($mysql, "SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version','Ssl_cipher','Ssl_server_not_after','Ssl_sessions_reused')");
foreach ($sslStatus as $row) {
    echo "  " . $row['Variable_name'] . " = " . $row['Value'] . "\n";
}

$versionInfo = run_query_row($mysql, "SELECT current_user() AS current_user, version() AS server_version, CURRENT_TIMESTAMP AS server_time");
foreach ($versionInfo as $k => $v) {
    echo "  $k = $v\n";
}

// Only read statements below this line.
$mdlTables = run_query_map($mysql, "SHOW TABLES LIKE '" . like_escape($dbPrefix) . "%'");
echo "\nTable count matching prefix '$dbPrefix': " . count($mdlTables) . "\n";

$state = 'EMPTY';
$backupRequired = 'NO';
$codeVersion = '2024100712.02'; // matches version.php $version in current checkout
$codeRelease = '4.5.12+ (Build: 20260624)';
$codeBranch  = '405';
$dbVersion = null;
$dbRelease = null;
$dbBranch  = null;
$usersCount = 0;
$coursesCount = 0;

if (count($mdlTables) > 0) {
    $hasConfig = (bool)run_query_one($mysql, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?", [$dbName, $dbPrefix . 'config']);
    if ($hasConfig) {
        $map = run_query_map($mysql, "SELECT name, value FROM `{$dbPrefix}config` WHERE name IN ('release','version','branch')");
        foreach ($map as $row) {
            if ($row['name'] === 'version') {
                $dbVersion = (string)$row['value'];
            } elseif ($row['name'] === 'release') {
                $dbRelease = (string)$row['value'];
            } elseif ($row['name'] === 'branch') {
                $dbBranch = (string)$row['value'];
            }
        }
        if (table_exists($mysql, $dbName, $dbPrefix . 'user')) {
            $usersCount = (int)run_query_one($mysql, "SELECT COUNT(*) FROM `{$dbPrefix}user` WHERE deleted = 0 AND username <> 'guest'");
        }
        if (table_exists($mysql, $dbName, $dbPrefix . 'course')) {
            $coursesCount = (int)run_query_one($mysql, "SELECT COUNT(*) FROM `{$dbPrefix}course` WHERE id <> 1");
        }
        echo "\nInstalled Moodle state (from {$dbPrefix}config):\n";
        echo "  DB version  = " . ($dbVersion ?? '(NULL)') . " (code has $codeVersion)\n";
        echo "  DB release  = " . ($dbRelease ?? '(NULL)') . " (code has $codeRelease)\n";
        echo "  DB branch   = " . ($dbBranch ?? '(NULL)') . " (code has $codeBranch)\n";
        echo "  Users       = $usersCount\n";
        echo "  Courses     = $coursesCount\n";
        $backupRequired = 'YES'; // any install → ALWAYS backup before next step
        if ($dbVersion === null) {
            $state = 'INSTALLED_UPGRADE_REQUIRED';
        } else {
            $cmp = version_compare((string)$dbVersion, (string)$codeVersion);
            if ($cmp === 0) {
                $state = 'INSTALLED_MATCH';
            } elseif ($cmp < 0) {
                $state = 'INSTALLED_UPGRADE_REQUIRED';
            } else {
                $state = 'INSTALLED_DOWNGRADE_UNSAFE';
            }
        }
    } else {
        // Some mdl_* tables exist but no config table — partial or broken install.
        $state = 'INSTALLED_UPGRADE_REQUIRED';
        $backupRequired = 'YES';
        echo "\n[WARN] $dbPrefix tables present but no {$dbPrefix}config. Treat as partial install, upgrade path required.\n";
    }
}

// Exit message + decision line.
echo "\n-----------------------------------------------------\n";
echo "Final decision (copy-paste for deployment scripts):\n";
echo "STATE=$state\n";
echo "BACKUP_REQUIRED_BEFORE_NEXT_STEP=$backupRequired\n";
if ($state === 'INSTALLED_MATCH') {
    echo "NEXT_ACTION=NO_INSTALL_NEEDED → run cache purge + smoke tests\n";
} elseif ($state === 'INSTALLED_UPGRADE_REQUIRED') {
    echo "NEXT_ACTION=BACKUP_FIRST → run admin/cli/upgrade.php --non-interactive --allow-unstable\n";
} elseif ($state === 'INSTALLED_DOWNGRADE_UNSAFE') {
    echo "NEXT_ACTION=BLOCKED → DB newer than code. Refuse to run installer or upgrade; rollback code to a newer checkout matching DB version $dbVersion\n";
} else {
    echo "NEXT_ACTION=BACKUP_OF_EMPTY_DB → CLI install via admin/cli/install_database.php\n";
}
echo "-----------------------------------------------------\n";

mysqli_close($mysql);
exit(0);

// --- helper functions (pure-read, no writes) ---
function run_query_map(mysqli $mysql, string $sql): array {
    $res = @mysqli_query($mysql, $sql);
    if ($res === false) {
        return [];
    }
    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }
    mysqli_free_result($res);
    return $rows;
}

function run_query_row(mysqli $mysql, string $sql): array {
    $rows = run_query_map($mysql, $sql);
    return $rows[0] ?? [];
}

function run_query_one(mysqli $mysql, string $sql, array $params = []) {
    if ($params !== []) {
        $stmt = mysqli_prepare($mysql, $sql);
        if (!$stmt) {
            return 0;
        }
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        $row = null;
        mysqli_stmt_bind_result($stmt, $row);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        return $row;
    }
    $rows = run_query_map($mysql, $sql);
    if ($rows === []) {
        return 0;
    }
    return array_values($rows[0])[0] ?? 0;
}

function table_exists(mysqli $mysql, string $schema, string $table): bool {
    return (bool)run_query_one(
        $mysql,
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
        [$schema, $table]
    );
}

function like_escape(string $s): string {
    return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
}
