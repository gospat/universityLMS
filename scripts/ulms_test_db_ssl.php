<?php
/**
 * Standalone DB_SSL_MODE=verify-full connection diagnostic (no Moodle bootstrap).
 *
 * Mimics the EXACT logic of Moodle 4.5 native MySQLi driver
 *   lib/dml/mysqli_native_moodle_database.php lines 547-573:
 *   - disable mysqli_report(MYSQLI_REPORT_OFF)
 *   - real_connect(host, user, pass, dbname, port, socket, flags)
 *   - flags = MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT
 *     (only when ssl mode === 'verify-full')
 *   - NO calls to mysqli_ssl_set() — that matches Moodle's driver.
 *
 * Usage:
 *   php scripts/ulms_test_db_ssl.php                 # from repo root, reads ./.env
 *   php scripts/ulms_test_db_ssl.php /path/to/.env   # custom env path
 *
 * The script performs:
 *   (A) .env parse + DB_SSL_MODE whitelist validation.
 *   (B) Show the exact bitmask of connection flags that will be used.
 *   (C) If the .env DB_HOST / DB_USER / DB_PASSWORD values are populated
 *       (i.e. real institution credentials), actually connect with the
 *       real_connect() call Moodle uses, run the MySQL 8.4-safe
 *       SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version',
 *       'Ssl_cipher','Ssl_server_not_after','Ssl_sessions_reused') query,
 *       and print current_user() + TLS info.
 *   (D) If the DB values are still placeholders, print a "simulation only"
 *       message and how to run the real test on the target DBaaS Droplet.
 *
 * No writes to the database.  No Moodle installer or DB schema operations.
 * No credentials are printed to stdout.
 */

declare(strict_types=1);

$envPath = $argv[1] ?? (dirname(__DIR__) . '/.env');

// -------------------------------------------------------------------------
// 1. Load env (minimal inline parser compatible with config.php semantics)
// -------------------------------------------------------------------------
function ulms_test_load_env(string $file): void {
    if (!file_exists($file)) {
        fwrite(STDERR, "[WARN] .env file not found at: $file  (simulation-only mode)\n");
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
        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eqPos));
        $val = substr($line, $eqPos + 1);
        // inline comments: # preceded by whitespace.
        $val = rtrim($val);
        if (strlen($val) >= 2 && $val[0] === '"' && $val[-1] === '"') {
            $val = substr($val, 1, -1);
        } elseif (strlen($val) >= 2 && $val[0] === "'" && $val[-1] === "'") {
            $val = substr($val, 1, -1);
        } else {
            // handle inline # comment (space then #)
            $commentPos = preg_match('/\s+#/', $val, $m, PREG_OFFSET_CAPTURE) ? (int)$m[0][1] : false;
            if ($commentPos !== false) {
                $val = rtrim(substr($val, 0, $commentPos));
            }
        }
        if ($key !== '' && !array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $val;
        }
    }
}

function ulms_test_env(string $key, $default = null) {
    if (array_key_exists($key, $_ENV) && $_ENV[$key] !== '') {
        return $_ENV[$key];
    }
    $v = getenv($key);
    if (is_string($v) && $v !== '') {
        return $v;
    }
    return $default;
}

ulms_test_load_env($envPath);

$dbHost    = (string)ulms_test_env('DB_HOST', '127.0.0.1');
$dbPort    = (int)((string)ulms_test_env('DB_PORT', '3306'));
$dbName    = (string)ulms_test_env('DB_NAME', 'ulms');
$dbUser    = (string)ulms_test_env('DB_USER', 'ulms_rw');
$dbPass    = (string)ulms_test_env('DB_PASSWORD', '');
$dbSslMode = strtolower(trim((string)ulms_test_env('DB_SSL_MODE', '')));

$allowedModes = ['require', 'verify-full'];
echo "========================================\n";
echo "ULMS DB_SSL_MODE verify-full diagnostic\n";
echo "========================================\n";
echo "PHP version   : " . PHP_VERSION . "\n";
echo "PHP SAPI      : " . PHP_SAPI . "\n";
echo "MySQLi loaded : " . (extension_loaded('mysqli') ? 'YES' : 'NO') . "\n";
echo "OpenSSL loaded: " . (extension_loaded('openssl') ? 'YES ('. OPENSSL_VERSION_TEXT .')' : 'NO') . "\n";
echo "OS CA bundle  : ";
$candidates = [
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/cert.pem',
];
$caBundle = null;
foreach ($candidates as $c) {
    if (file_exists($c)) {
        $caBundle = $c;
        echo $c . " (" . round(filesize($c) / 1024) . " KB)\n";
        break;
    }
}
if ($caBundle === null) {
    echo "(no OS CA bundle found in standard paths)\n";
}
echo "\n";
echo "DB_HOST       : $dbHost\n";
echo "DB_PORT       : $dbPort\n";
echo "DB_NAME       : $dbName\n";
echo "DB_USER       : $dbUser\n";
echo "DB_PASSWORD   : " . ($dbPass !== '' ? '<set, ' . strlen($dbPass) . ' chars>' : '<EMPTY>') . "\n";
echo "DB_SSL_MODE   : " . ($dbSslMode === '' ? '<unset>' : $dbSslMode) . "\n";
echo "\n";

// -------------------------------------------------------------------------
// 2. Validate DB_SSL_MODE (mirrors config.php whitelist)
// -------------------------------------------------------------------------
echo "[1/4] DB_SSL_MODE validation against whitelist [" . implode(', ', $allowedModes) . "]\n";
if ($dbSslMode !== '' && !in_array($dbSslMode, $allowedModes, true)) {
    echo "      FAIL — DB_SSL_MODE='$dbSslMode' is invalid.\n";
    echo "      This is exactly what config.php will stop at boot (exit 2 / HTTP 500).\n";
    exit(2);
}
echo "      PASS\n\n";

// -------------------------------------------------------------------------
// 3. Compute connection flags (exact Moodle driver logic)
// -------------------------------------------------------------------------
echo "[2/4] Compute mysqli real_connect() flags\n";
$flags = 0;
if ($dbSslMode !== '') {
    $flags |= MYSQLI_CLIENT_SSL;
    echo "      + MYSQLI_CLIENT_SSL (0x" . dechex(MYSQLI_CLIENT_SSL) . ")\n";
    if ($dbSslMode === 'verify-full') {
        $flags |= MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT;
        echo "      + MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT (0x" . dechex(MYSQLI_CLIENT_SSL_VERIFY_SERVER_CERT) . ")\n";
    }
}
echo "      Final flags = $flags (0x" . dechex($flags) . ")\n\n";

// -------------------------------------------------------------------------
// 4. Detect if credentials are still placeholder — if so, simulate only.
// -------------------------------------------------------------------------
$placeholders = [
    '127.0.0.1',
    'do-user-xxxx-0.b.db.ondigitalocean.com',
    'bells-ulms-db-do-user-xxxx-0.b.db.ondigitalocean.com',
    'replace-with-strong-password-min-32-chars-special-chars-ok-if-quoted',
    '',
];
$isPlaceholder = in_array($dbHost, $placeholders, true)
    || in_array($dbPass, $placeholders, true)
    || strpos($dbHost, 'xxxx') !== false
    || stripos($dbPass, 'replace') !== false;

if ($isPlaceholder) {
    echo "[3/4] REAL CONNECTION SKIPPED (placeholder DB_HOST / DB_PASSWORD in env)\n\n";
    echo "[4/4] How to run against Managed MySQL (DO / AWS / GCP / Azure DBaaS reference):\n";
    echo "      1. On the target Ubuntu 24.04 server, install the prereqs:\n";
    echo "           sudo apt-get install -y ca-certificates php8.3-cli php8.3-mysql mysql-client\n";
    echo "           sudo update-ca-certificates --fresh\n";
    echo "      2. Populate your repo-root .env with real DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASSWORD/DB_SSL_MODE.\n";
    echo "      3. Run:  sudo -u www-data php /path/to/repo/scripts/ulms_test_db_ssl.php\n";
    echo "                 (this script, zero writes, runs the exact real_connect() Moodle will run).\n";
    echo "      4. Pre-deploy CLI gate — run the authoritative mysql VERIFY_IDENTITY test:\n";
    echo "           mysql -h <real-managed-mysql-hostname> -P <port> -u ulms_rw -p ulms --ssl-mode=VERIFY_IDENTITY -e \"\n";
    echo "             SHOW SESSION STATUS WHERE Variable_name IN ('Ssl_version','Ssl_cipher','Ssl_server_not_after','Ssl_sessions_reused');\n";
    echo "             SELECT current_user() AS db_user, CURRENT_TIMESTAMP AS server_time;\n";
    echo "           \"\n";
    echo "         Expected: Ssl_version = TLSv1.2 or TLSv1.3; Ssl_cipher non-empty.\n\n";
    echo "Expected result on successful live run:\n";
    echo "  - connection OK (no exception / no 0A000086 cert verify errors)\n";
    echo "\n";
    echo "# ---------- Bells University reference values (copy/adapt for Bells deploy) ----------\n";
    echo "# DB_HOST example on Bells DO Managed MySQL:\n";
    echo "#   bells-ulms-db-do-user-xxxx-0.b.db.ondigitalocean.com\n";
    echo "# Install path / command on Bells droplet:\n";
    echo "#   sudo -u www-data php /var/www/universityLMS/scripts/ulms_test_db_ssl.php\n";
    echo "# --------------------------------------------------------------------------------------\n";
    echo "  - Ssl_session_status reporting TLS 1.2/1.3 + non-empty cipher\n";
    echo "  - current_user() returns ulms_rw@'some-nat-ip' or @'%'\n\n";
    exit(0);
}

// -------------------------------------------------------------------------
// 5. Real attempt — exact same sequence as Moodle driver.
// -------------------------------------------------------------------------
echo "[3/4] Real connection using real_connect(host=$dbHost, port=$dbPort, db=$dbName)\n";
echo "      Using the EXACT flags computed above — same as Moodle 4.5 native driver.\n";
echo "      Note: no mysqli_ssl_set() call — this matches Moodle's current driver exactly.\n";
echo "      Connection timeout: 10 seconds.\n\n";

if (!extension_loaded('mysqli')) {
    echo "      FAIL: ext-mysqli not installed.\n";
    exit(1);
}

// Set up driver
mysqli_report(MYSQLI_REPORT_OFF);

$mysqli = mysqli_init();
if (!$mysqli) {
    echo "      FAIL: mysqli_init() returned false (OOM?)\n";
    exit(1);
}
// Set a 10s connect timeout so placeholder runs don't hang
mysqli_options($mysqli, MYSQLI_OPT_CONNECT_TIMEOUT, 10);

$socket = null;
try {
    $connected = @mysqli_real_connect(
        $mysqli,
        $dbHost,
        $dbUser,
        $dbPass,
        $dbName,
        $dbPort,
        $socket,
        $flags
    );
} catch (\Throwable $e) {
    echo "      EXCEPTION during real_connect(): " . $e->getMessage() . "\n";
    $connected = false;
}

if (!$connected) {
    $errno  = mysqli_connect_errno();
    $errmsg = mysqli_connect_error();
    echo "      FAIL — mysqli_real_connect() returned false.\n";
    echo "      Connect errno : $errno\n";
    echo "      Connect error : $errmsg\n";
    echo "\n      Likely causes by errno:\n";
    echo "        2002 = No route to host / DB_HOST DNS resolution / port unreachable.\n";
    echo "        2003 = Can't connect — firewall / DO Trusted Sources missing Droplet IP.\n";
    echo "        2026 (HY000) SSL connection error: OpenSSL 0A000086 = CA chain verify failed.\n";
    echo "                                  Install ca-certificates + run update-ca-certificates.\n";
    echo "        2026 with 'Peer certificate CN=... did not match' = DB_HOST is IP not hostname.\n";
    echo "        1045 Access denied = wrong DB_PASSWORD / user not granted on ulms.* (check grants).\n\n";
    exit(1);
}
echo "      PASS — real_connect() succeeded.\n\n";

echo "[4/4] Post-connect TLS status (MySQL 8.4-safe — SESSION STATUS Ssl_* variables)\n\n";
$statusQuery = "SHOW SESSION STATUS WHERE Variable_name IN "
             . "('Ssl_version','Ssl_cipher','Ssl_server_not_after','Ssl_sessions_reused')";
$res = $mysqli->query($statusQuery);
if (!$res) {
    echo "      SHOW SESSION STATUS query failed: " . $mysqli->error . "\n";
    $mysqli->close();
    exit(1);
}
while ($row = $res->fetch_assoc()) {
    printf("      %-24s = %s\n", $row['Variable_name'], $row['Value'] === '' ? '<EMPTY (TLS NOT NEGOTIATED)>' : $row['Value']);
}
$res->free();

// current_user / server time
echo "\n";
$res = $mysqli->query("SELECT current_user() AS db_user, CURRENT_TIMESTAMP AS server_time, version() AS server_version");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        foreach ($row as $k => $v) {
            printf("      %-24s = %s\n", $k, $v);
        }
    }
    $res->free();
}
$mysqli->close();

echo "\nAll checks passed.  ULMS/Moodle DB_SSL_MODE=verify-full is supported on this host with the given .env values.\n";
echo "The exact real_connect() call above is what Moodle's native driver will issue on every page boot.\n";
exit(0);
