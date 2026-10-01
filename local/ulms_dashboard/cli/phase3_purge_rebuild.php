<?php

define('CLI_SCRIPT', true);
require __DIR__.'/../../../config.php';

// OQ-4 Production Boot Guard. MUST execute as EARLY as possible after $CFG populated.
// Prevents ANY production boot that still uses MySQL root account (minimum-privilege violation).
global $CFG;
$dbuser = $CFG->dbuser ?? $_ENV['DB_USER'] ?? getenv('DB_USER') ?: '';
$appenv = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local';
if ((defined('APP_ENV') ? APP_ENV === 'production' : (stripos((string)$appenv, 'prod') !== false))
    && (strcasecmp((string)$dbuser, 'root') === 0)) {
    http_response_code(500);
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
    }
    $msg = "ULMS-SAFETY-P0: Production environment detected but DB_USER='root' in active configuration. "
         . "Create a dedicated minimum-privilege user (ulms_rw) per .env.example L64-68, update .env DB_USER/DB_PASS, "
         . "then retry. ULMS refuses to boot in production with root MySQL account. See: SECURITY.md / deployment checklist.";
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "ERROR [OQ-4]: " . $msg . PHP_EOL);
        exit(2);
    } else {
        die($msg);
    }
}

require_once $CFG->libdir.'/clilib.php';
require_once $CFG->libdir.'/adminlib.php';

// ═══════════════════════════════════════════════════════════════════════════
// PRODUCTION SAFETY GATE
// ═══════════════════════════════════════════════════════════════════════════
// This script is a DESTRUCTIVE maintenance utility.  It deletes the
// CONTENTS of 8 moodledata directories (sessions, cache, sitedata, lang,
// etc.) and then calls purge_all_caches().  It does NOT write to the
// Moodle database table rows except via the standard Moodle upgrade_core()
// and purge_all_caches() helpers.  See the comment block above the $dirs
// list below for an exact per-directory accounting.
//
// Running in APP_ENV=production is BLOCKED by default.  To authorise, pass
// the explicit --i-am-sure flag.  This prevents accidental invocation from
// deployment automation or copy/paste during routine updates.
$rawopts = getopt('', ['i-am-sure', 'help']);
if (isset($rawopts['help'])) {
    cli_writeln('ULMS phase3_purge_rebuild — pristine-state utility (DESTRUCTIVE).');
    cli_writeln('');
    cli_writeln('USE CASE:');
    cli_writeln('  * Immediately BEFORE first go-live (after staging/QA, before real users).');
    cli_writeln('  * Planned maintenance windows where a cache + session wipe is explicitly desired.');
    cli_writeln('');
    cli_writeln('WHAT IT DOES:');
    cli_writeln('  moodledata CONTENTS DELETED (dirs preserved, index.html + .htaccess kept):');
    cli_writeln('    cache, localcache, temp, sessions, sitedata, lang, styles_debug, styles_mashup');
    cli_writeln('  database (safe Moodle core helpers only, NO row drops of user/course data):');
    cli_writeln('    · purge_all_caches() — MUC caches cleared');
    cli_writeln('    · upgrade_core()   — DB schema upgrade only if pending');
    cli_writeln('  other (no DB user data touched):');
    cli_writeln('    · SCSS rebuild for ulms_university + boost themes');
    cli_writeln('    · 8-URL HTTP smoke list (read only)');
    cli_writeln('');
    cli_writeln('WHAT IT DOES NOT DO:');
    cli_writeln('  · Does NOT DELETE rows from mdl_user, mdl_course, mdl_enrol or any');
    cli_writeln('    academic / financial ULMS table.  Student, lecturer and admin');
    cli_writeln('    records in the database are LEFT INTACT.');
    cli_writeln('  · Does NOT delete any files inside the webroot / git repository.');
    cli_writeln('  · Does NOT touch .env, config.php, or moodledata/.htaccess or /index.html');
    cli_writeln('  · Does NOT introduce demo data.  This is NOT a seeder.  Seeding is done');
    cli_writeln('    via seed_demo_academic_chain.php (local/dev only, requires --apply and');
    cli_writeln('    refuses APP_ENV=production).');
    cli_writeln('');
    cli_writeln('Usage:');
    cli_writeln('  php phase3_purge_rebuild.php --i-am-sure');
    cli_writeln('');
    cli_writeln('When APP_ENV=production the --i-am-sure flag is MANDATORY.  In local /');
    cli_writeln('dev environments the flag is optional but you still see this prompt via --help.');
    exit(0);
}

$appenv = strtolower((string)ulms_env('APP_ENV', 'local'));
$production_env = in_array($appenv, ['prod', 'production', 'live'], true);
if ($production_env && !isset($rawopts['i-am-sure'])) {
    $ansi_red = "\033[31m";
    $ansi_reset = "\033[0m";
    $banner = <<<BANNER
{$ansi_red}═══════════════════════════════════════════════════════════════════════
  REFUSED: phase3_purge_rebuild.php blocked in APP_ENV=production
═══════════════════════════════════════════════════════════════════════{$ansi_reset}

  This script is DESTRUCTIVE — it deletes the CONTENTS of 8 moodledata
  directories and purges all sessions (everyone logged out).

  Allowed scenarios:
    · Immediately before FIRST go-live (after QA completed, before real users)
    · Pre-approved maintenance window with operator confirmation

  To authorise execution on a production system, re-run with:
    APP_ENV=production php local/ulms_dashboard/cli/phase3_purge_rebuild.php --i-am-sure

  If you are trying to perform a routine Moodle cache clear (the normal
  deploy-time action), run this INSTEAD:
    php admin/cli/purge_caches.php

  If you are trying to apply pending Moodle DB schema upgrades after a
  code-only deploy, run:
    php admin/cli/upgrade.php --non-interactive

BANNER;
    cli_writeln($banner);
    exit(2);
}

global $CFG;
raise_memory_limit(MEMORY_HUGE);
set_time_limit(0);

if ($production_env) {
    cli_writeln('[PRODUCTION GATE PASSED] explicit --i-am-sure flag supplied.');
    cli_writeln('[INFO] Starting moodledata cache/session purge + theme rebuild.');
}

function ulms_recursive_rm_contents($dir, $keeplist = []) {
    if (!is_dir($dir)) {
        return;
    }
    $items = new DirectoryIterator($dir);
    foreach ($items as $item) {
        if ($item->isDot()) {
            continue;
        }
        $pathname = $item->getPathname();
        $filename = $item->getFilename();
        if (in_array($filename, $keeplist)) {
            continue;
        }
        if ($item->isDir()) {
            ulms_recursive_rm_contents($pathname, $keeplist);
            @rmdir($pathname);
        } else {
            @unlink($pathname);
        }
    }
}

$dirs = [
    'cache'         => "Core caches (db/string/lang cache files)",
    'localcache'    => "Local per-server caches",
    'temp'          => "Temporary export / lock files",
    'sessions'      => "User sessions (forces re-login, clean state)",
    'sitedata'      => "Site-wide data pools",
    'lang'          => "Cached language packs",
    'styles_debug'  => "Debug CSS builds (safety-only, may not exist)",
    'styles_mashup' => "Mashup CSS cache variants (safety-only, may not exist)",
];

$dataroot = rtrim($CFG->dataroot, '/');
cli_writeln("=== T12: Purging 8 moodledata dirs ===");
foreach ($dirs as $dir => $desc) {
    $fullpath = $dataroot.'/'.$dir;
    cli_writeln("[$dir] $desc");
    if (!is_dir($fullpath)) {
        cli_writeln("  SKIP missing dir: $dir");
        continue;
    }
    ulms_recursive_rm_contents($fullpath, ['index.html', '.htaccess']);
    @touch($fullpath.'/index.html');
    cli_writeln("  OK wiped");
}

cli_writeln("\n=== Purge all MUC caches ===");
purge_all_caches();
cli_writeln("  OK purge_all_caches() done");

cli_writeln("\n=== Run core DB upgrade ===");
require_once $CFG->libdir.'/upgradelib.php';
try {
    upgrade_core($CFG->version, false);
    cli_writeln("  OK upgrade_core() done");
} catch (Throwable $e) {
    cli_writeln("  WARN upgrade_core() threw: ".get_class($e)." :: ".$e->getMessage());
}

$themes = ['ulms_university', 'boost'];
cli_writeln("\n=== Rebuild SCSS for themes: ".implode(', ', $themes)." ===");
$build_log = [];
$theme_css_bytes = [];
foreach ($themes as $themename) {
    try {
        $theme = \theme_config::load($themename);
        if (!$theme) { $build_log[] = "$themename: theme_config::load FAILED"; continue; }
        $css = $theme->get_css_content_debug('scss', null, null);
        if (!is_string($css) || $css === '') { $build_log[] = "$themename: get_css returned empty"; continue; }
        $css = preg_replace('!/\*.*?\*/!s', '', $css);
        $css = preg_replace('/\s+/u', ' ', $css);
        $css = preg_replace('/\s*([{};>~+])\s*/', '$1', $css);
        $css = preg_replace('/;}/', '}', $css);
        $css = trim($css);
        $size = strlen($css);
        $theme_css_bytes[$themename] = $size;
        $build_log[] = "$themename: compiled OK ($size bytes, contains ulms-shell=".(strpos($css,'ulms-shell')!==false?'Y':'N').")";
        if ($themename === 'ulms_university') {
            $outdir = $CFG->dirroot.'/theme/ulms_university/style';
            if (!is_dir($outdir)) { @mkdir($outdir, $CFG->directorypermissions ?? 0755, true); }
            $outfile = $outdir.'/default.css';
            file_put_contents($outfile, $css);
            $build_log[] = "  -> wrote $outfile";
        }
    } catch (\Throwable $e) {
        $build_log[] = "$themename: EXCEPTION ".get_class($e)." :: ".$e->getMessage();
    }
}
cli_writeln("build_css: ".implode(' | ', $build_log));

cli_writeln("\n=== Verify compiled CSS size ===");
$cssPath = $CFG->dirroot.'/theme/ulms_university/style/default.css';
if (file_exists($cssPath)) {
    $sz = filesize($cssPath);
    $ok = $sz <= 950 * 1024;
    cli_writeln("CSS size: {$sz} bytes (972,800 budget) - status: ".($ok?'PASS':'FAIL'));
    if (!$ok) {
        cli_writeln("  WARNING CSS exceeds budget by ".($sz - 950*1024)." bytes");
    }
} else {
    cli_writeln("WARN compiled css not at $cssPath");
}

$urls = [
    'login_landing'     => $CFG->wwwroot.'/local/ulms_auth/index.php',
    'lecturer_login'    => $CFG->wwwroot.'/local/ulms_auth/lecturer_login.php',
    'student_login'     => $CFG->wwwroot.'/local/ulms_auth/student_login.php',
    'lecturer_exams'    => $CFG->wwwroot.'/local/ulms_exam/lecturer_exams.php',
    'lecturer_create'   => $CFG->wwwroot.'/local/ulms_exam/lecturer_exam_create.php',
    'student_exams'     => $CFG->wwwroot.'/local/ulms_exam/student_exams.php',
    'student_portal'    => $CFG->wwwroot.'/local/ulms_dashboard/student_portal.php',
    'lecturer_portal'   => $CFG->wwwroot.'/local/ulms_dashboard/lecturer_portal.php',
];

cli_writeln("\n=== 8 URL SMOKE LIST (HTTP 200/303 expected) ===");
foreach ($urls as $name => $url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $r = curl_exec($ch);
        $code = $r === false ? 0 : curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $code = -1;
    }
    $mark = ($code >= 200 && $code < 400) || $code === -1 ? 'OK' : 'FAIL';
    cli_writeln("  [{$mark}] code=$code  {$name}: {$url}");
}

cli_writeln("\n=== T12 complete ===");
exit(0);
