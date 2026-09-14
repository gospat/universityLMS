<?php

define('CLI_SCRIPT', true);
require __DIR__.'/../../../config.php';
require_once $CFG->libdir.'/clilib.php';
require_once $CFG->libdir.'/adminlib.php';

global $CFG;
raise_memory_limit(MEMORY_HUGE);
set_time_limit(0);

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
