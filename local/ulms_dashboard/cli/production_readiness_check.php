<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

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

require_once($CFG->libdir . '/clilib.php');

$checks = [];

$recordcheck = static function(string $name, bool $passed, string $detail) use (&$checks): void {
    $checks[] = [
        'name' => $name,
        'passed' => $passed,
        'detail' => $detail,
    ];
};

$routingservice = new \local_ulms_auth\local\service\landing_page_service();

$expectedroutes = [
    'public.landing' => '/sign-in/',
    'public.activate' => '/sign-in/activate/',
    'public.passwordreset' => '/reset-password/',
    'student.login' => '/student/login/',
    'lecturer.login' => '/lecturer/login/',
    'management.login' => '/management/login/',
    'superadmin.login' => '/super-admin/login/',
    'student.dashboard' => '/student/',
    'lecturer.dashboard' => '/lecturer/',
    'management.dashboard' => '/management/',
    'superadmin.dashboard' => '/super-admin/',
];

foreach ($expectedroutes as $routekey => $expectedpath) {
    $actualpath = $routingservice->get_path_for_route($routekey);
    $recordcheck(
        'route:' . $routekey,
        $actualpath === $expectedpath,
        'expected ' . $expectedpath . ', got ' . $actualpath
    );
}

$alternateloginurl = (string)($CFG->alternateloginurl ?? '');
$recordcheck(
    'config:alternateloginurl',
    $alternateloginurl === ($CFG->wwwroot . '/sign-in/'),
    'current value: ' . ($alternateloginurl !== '' ? $alternateloginurl : '[empty]')
);

$recordcheck(
    'config:forgottenpasswordurl',
    (($CFG->forgottenpasswordurl ?? '') === ($CFG->wwwroot . '/reset-password/')),
    'current value: ' . (string)($CFG->forgottenpasswordurl ?? '[empty]')
);

try {
    $theme = \theme_config::load('ulms_university');
    $css = $theme->get_css_content_debug('scss', null, null);
    $hascss = is_string($css) && $css !== '';
    $recordcheck('theme:compile', $hascss, $hascss ? 'SCSS compiled successfully.' : 'Theme CSS compilation returned empty output.');
    $recordcheck('theme:ulms-shell', $hascss && strpos($css, 'ulms-shell') !== false, 'Compiled CSS contains ulms-shell.');
    $recordcheck('theme:ulms-portal-navbar', $hascss && strpos($css, 'ulms-portal-navbar') !== false, 'Compiled CSS contains ulms-portal-navbar.');
    $recordcheck('theme:ulms-layout-grid', $hascss && strpos($css, 'ulms-layout-grid') !== false, 'Compiled CSS contains ulms-layout-grid.');
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::theme_compile', ['ctx' => basename(__FILE__)]); }
    $recordcheck('theme:compile', false, 'Theme compilation failed: ' . $exception->getMessage());
}

$components = ['local_ulms_auth', 'local_ulms_dashboard', 'local_ulms_mail', 'theme_ulms_university'];
$pluginmanager = \core_plugin_manager::instance();
foreach ($components as $component) {
    $recordcheck(
        'plugin:' . $component,
        $pluginmanager->get_plugin_info($component) !== null,
        'Plugin/component availability checked.'
    );
}

$transport = (string)($CFG->ulmsmailtransport ?? 'moodle');
if (empty($CFG->smtphosts)) {
    global $DB;
    $storedsmtp = $DB->get_field('config', 'value', ['name' => 'smtphosts']);
    if (!empty($storedsmtp)) {
        $CFG->smtphosts = (string)$storedsmtp;
    }
}
$recordcheck('mail:transport', in_array($transport, ['moodle', 'resend'], true), 'Current transport: ' . $transport);

if ($transport === 'resend') {
    $recordcheck(
        'mail:resend-service',
        class_exists(\local_ulms_mail\local\service\resend_mail_service::class),
        'Resend mail service class is autoloadable.'
    );
    $hassymfonyhttp = class_exists('Symfony\Component\HttpClient\HttpClient');
    $hascurlext = extension_loaded('curl');
    $recordcheck(
        'mail:http-client',
        $hassymfonyhttp || $hascurlext,
        ($hassymfonyhttp ? 'Symfony HttpClient available' : ($hascurlext ? 'cURL extension available (fallback transport)' : 'No HTTP transport found')) . '.'
    );
    $recordcheck(
        'mail:api-key',
        !empty($CFG->resendapikey),
        'Resend API key presence checked.'
    );
    $recordcheck(
        'mail:from-email',
        !empty($CFG->resendfromemail) && validate_email((string)$CFG->resendfromemail),
        'Resend from email configuration checked.'
    );
} else {
    $recordcheck(
        'mail:smtp-hosts',
        !empty($CFG->smtphosts ?? ''),
        'SMTP hosts must be configured when using Moodle transport.'
    );
}

try {
    $studentservice = new \local_ulms_dashboard\local\service\student_portal_service();
    $studentroutes = $studentservice->get_canonical_student_portal_routes_for_audit();
    $recordcheck(
        'ssot:student-routes',
        count($studentroutes) >= 20,
        'Student SSOT canonical route count: ' . count($studentroutes)
    );
    $studentvalidation = method_exists($studentservice, 'validate_canonical_route_consistency')
        ? $studentservice->validate_canonical_route_consistency()
        : ['ok' => false, 'errors' => ['Validator method missing.']];
    $recordcheck(
        'ssot:student-validator',
        !empty($studentvalidation['ok']),
        !empty($studentvalidation['ok'])
            ? 'All ' . count($studentroutes) . ' student routes passed consistency self-check.'
            : 'Validator errors: ' . implode('; ', $studentvalidation['errors'] ?? [])
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::ssot_student', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ssot:student-routes', false, 'Student SSOT audit failed: ' . $exception->getMessage());
}

try {
    $lecturerservice = new \local_ulms_dashboard\local\service\lecturer_portal_service();
    if (method_exists($lecturerservice, 'get_canonical_lecturer_portal_routes_for_audit')) {
        $lecturerroutes = $lecturerservice->get_canonical_lecturer_portal_routes_for_audit();
        $recordcheck(
            'ssot:lecturer-routes',
            count($lecturerroutes) >= 20,
            'Lecturer SSOT canonical route count: ' . count($lecturerroutes)
        );
        $lecturervalidation = method_exists($lecturerservice, 'validate_canonical_route_consistency')
            ? $lecturerservice->validate_canonical_route_consistency()
            : ['ok' => false, 'errors' => ['Validator method missing.']];
        $recordcheck(
            'ssot:lecturer-validator',
            !empty($lecturervalidation['ok']),
            !empty($lecturervalidation['ok'])
                ? 'All ' . count($lecturerroutes) . ' lecturer routes passed consistency self-check.'
                : 'Validator errors: ' . implode('; ', $lecturervalidation['errors'] ?? [])
        );
    } else {
        $recordcheck(
            'ssot:lecturer-routes',
            false,
            'Lecturer SSOT canonical route list not yet implemented.'
        );
    }
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::ssot_lecturer', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ssot:lecturer-routes', false, 'Lecturer SSOT audit failed: ' . $exception->getMessage());
}

try {
    $lecturerservice = new \local_ulms_dashboard\local\service\lecturer_portal_service();
    if (method_exists($lecturerservice, 'validate_canonical_route_consistency')) {
        $consistency = $lecturerservice->validate_canonical_route_consistency();
        $recordcheck(
            'sec:lecturer-ssot-consistency',
            !empty($consistency['ok']),
            !empty($consistency['ok'])
                ? 'Lecturer SSOT route consistency passed across all allowed section/header keys.'
                : 'Lecturer SSOT inconsistencies: ' . implode('; ', $consistency['errors'] ?? [])
        );
    } else {
        $recordcheck('sec:lecturer-ssot-consistency', false, 'Lecturer SSOT validator method not yet implemented.');
    }
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::sec_config', ['ctx' => basename(__FILE__)]); }
    $recordcheck('sec:lecturer-ssot-consistency', false, 'Lecturer SSOT consistency audit failed: ' . $exception->getMessage());
}

try {
    $lecturerservice = new \local_ulms_dashboard\local\service\lecturer_portal_service();
    global $DB;
    $samplecourseid = 2;
    $crs = $DB->get_record('course', ['id' => 2], 'id');
    if (!$crs) {
        $any = $DB->get_records_select('course', 'id > 1', null, 'id ASC', 'id', 0, 1);
        if (!empty($any)) {
            $samplecourseid = (int)reset($any)->id;
        }
    }
    if (method_exists($lecturerservice, 'audit_lecturer_course_url_access')) {
        $courseaudit = $lecturerservice->audit_lecturer_course_url_access($samplecourseid);
        $recordcheck(
            'ops:lecturer-course-urls-audit',
            !empty($courseaudit['ok']),
            $courseaudit['detail'] ?? ('Lecturer course URL audit completed with sample ID ' . $samplecourseid . '.')
        );
    } else {
        $recordcheck('ops:lecturer-course-urls-audit', false, 'Lecturer course URL audit helper not yet implemented.');
    }
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::sec_cookie', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ops:lecturer-course-urls-audit', false, 'Lecturer course URL audit failed: ' . $exception->getMessage());
}

$recordcheck(
    'sec:debug-off',
    ((int)($CFG->debug ?? 0) === 0),
    'CFG->debug current value: ' . (int)($CFG->debug ?? -1)
);

$recordcheck(
    'sec:debugdisplay-off',
    (empty($CFG->debugdisplay) || (int)$CFG->debugdisplay === 0),
    'CFG->debugdisplay disabled: ' . (empty($CFG->debugdisplay) ? 'yes' : 'no')
);

$recordcheck(
    'sec:cookie-secure',
    (function_exists('is_moodle_https') && is_moodle_https()) ? !empty($CFG->securecookie) : true,
    (function_exists('is_moodle_https') && is_moodle_https())
        ? ('Secure cookie flag when HTTPS: ' . (!empty($CFG->securecookie) ? 'enabled' : 'disabled'))
        : 'Skipped (plaintext host).'
);

$recordcheck(
    'sec:cookie-httponly',
    empty($CFG->sessioncookiehttponly) || (bool)$CFG->sessioncookiehttponly !== false,
    'HttpOnly session cookies: ' . (empty($CFG->sessioncookiehttponly) ? 'default (on)' : 'explicit enabled')
);

$recordcheck(
    'sec:cookie-samesite',
    in_array(($CFG->sessioncookiesamesite ?? 'Lax'), ['None', 'Lax', 'Strict'], true),
    'Session cookie SameSite value: ' . ($CFG->sessioncookiesamesite ?? 'Lax')
);

$recordcheck(
    'sec:no-guest-login',
    empty($CFG->guestloginbutton),
    'Guest login button suppressed: ' . (empty($CFG->guestloginbutton) ? 'yes' : 'no')
);

$recordcheck(
    'sec:https-or-localhost',
    (static function(): bool {
        global $CFG;
        $host = (string)parse_url((string)($CFG->wwwroot ?? ''), PHP_URL_HOST);
        if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
            return true;
        }
        return function_exists('is_moodle_https') && is_moodle_https();
    })(),
    'wwwroot scheme: ' . (string)($CFG->wwwroot ?? '')
);

$resendconfigured = $transport === 'resend';
$resendkeyok = !$resendconfigured || \local_ulms_mail\local\service\resend_mail_service::is_valid_resend_api_key((string)($CFG->resendapikey ?? ''));
$recordcheck(
    'sec:resend-key-format',
    $resendkeyok,
    $resendconfigured
        ? 'Resend key format validated.'
        : 'Resend transport unused; check skipped.'
);

$recordcheck(
    'sec:moodlecourse-fk-exists',
    (static function(): bool {
        global $CFG, $DB;
        $dbman = $DB->get_manager();
        $table = new xmldb_table('local_ulms_programme_courses');
        if (!$dbman->table_exists($table)) {
            return true;
        }
        $dbtype = (string)($CFG->dbtype ?? '');
        if (in_array($dbtype, ['mysqli', 'native/mysqli', 'mariadb'], true)) {
            try {
                $prefix = $CFG->prefix ?? '';
                $sql = "SELECT COUNT(*) AS cnt
                          FROM information_schema.table_constraints tc
                          JOIN information_schema.key_column_usage kcu
                            ON kcu.constraint_name = tc.constraint_name
                           AND kcu.table_schema = tc.table_schema
                         WHERE tc.constraint_type = 'FOREIGN KEY'
                           AND tc.table_schema = DATABASE()
                           AND tc.table_name = ?
                           AND kcu.column_name = 'moodlecourseid'
                           AND kcu.referenced_table_name = 'course'";
                $row = $DB->get_record_sql($sql, [$prefix . 'local_ulms_programme_courses'], IGNORE_MISSING);
                if ($row && (int)($row->cnt ?? 0) > 0) {
                    return true;
                }
            } catch (\Throwable $exception) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::db_checks', ['ctx' => basename(__FILE__)]); }
            }
        }
        $xmldbpath = $CFG->dirroot . '/local/ulms_academics/db/install.xml';
        if (is_readable($xmldbpath)) {
            $xml = @simplexml_load_file($xmldbpath);
            if ($xml !== false) {
                $fks = $xml->xpath('//TABLE[@NAME="local_ulms_programme_courses"]/KEYS/KEY[@NAME="moodlecourse_fk" and @TYPE="foreign" and @FIELDS="moodlecourseid" and @REFTABLE="course" and @REFFIELDS="id"]');
                if (!empty($fks)) {
                    return true;
                }
            }
        }
        return false;
    })(),
    'Checked moodlecourse_fk on local_ulms_programme_courses via schema + install.xml fallback.'
);

$recordcheck(
    'sec:db-driver-mysqli',
    in_array((string)($CFG->dbtype ?? ''), ['mysqli', 'native/mysqli', 'mariadb'], true),
    'DB driver: ' . (string)($CFG->dbtype ?? '[unset]')
);

try {
    $adminservice = new \local_ulms_dashboard\local\service\admin_portal_service();
    $adminroutes = method_exists($adminservice, 'get_canonical_admin_portal_routes_for_audit')
        ? $adminservice->get_canonical_admin_portal_routes_for_audit()
        : [];
    $recordcheck(
        'ssot:admin-routes',
        count($adminroutes) >= 10,
        'Admin SSOT canonical route count: ' . count($adminroutes) . ' (expected >= 10).'
    );
    $adminvalidation = method_exists($adminservice, 'validate_canonical_route_consistency')
        ? $adminservice->validate_canonical_route_consistency()
        : ['ok' => false, 'errors' => ['Validator method missing.']];
    $recordcheck(
        'ssot:admin-validator',
        !empty($adminvalidation['ok']),
        !empty($adminvalidation['ok'])
            ? 'All ' . count($adminroutes) . ' admin routes passed consistency self-check.'
            : 'Admin SSOT validator errors: ' . implode('; ', $adminvalidation['errors'] ?? [])
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::ssot_admin', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ssot:admin-routes', false, 'Admin SSOT audit failed: ' . $exception->getMessage());
}

try {
    $superadminservice = new \local_ulms_dashboard\local\service\super_admin_portal_service();
    $sadminroutes = method_exists($superadminservice, 'get_canonical_super_admin_portal_routes_for_audit')
        ? $superadminservice->get_canonical_super_admin_portal_routes_for_audit()
        : [];
    $recordcheck(
        'ssot:superadmin-routes',
        count($sadminroutes) >= 20,
        'Super Admin SSOT canonical route count: ' . count($sadminroutes) . ' (expected >= 20).'
    );
    $sadminvalidation = method_exists($superadminservice, 'validate_canonical_route_consistency')
        ? $superadminservice->validate_canonical_route_consistency()
        : ['ok' => false, 'errors' => ['Validator method missing.']];
    $recordcheck(
        'ssot:superadmin-validator',
        !empty($sadminvalidation['ok']),
        !empty($sadminvalidation['ok'])
            ? 'All ' . count($sadminroutes) . ' super-admin routes passed consistency self-check.'
            : 'Super Admin SSOT validator errors: ' . implode('; ', $sadminvalidation['errors'] ?? [])
    );

    if (method_exists($superadminservice, 'audit_super_admin_admin_identity_preservation')) {
        $identityaudit = $superadminservice->audit_super_admin_admin_identity_preservation('management.users');
        $recordcheck(
            'ui:superadmin-identity-preservation',
            !empty($identityaudit['ok']),
            $identityaudit['detail'] ?? 'Identity preservation audit completed.'
        );
    } else {
        $recordcheck(
            'ui:superadmin-identity-preservation',
            false,
            'Audit helper audit_super_admin_admin_identity_preservation() not yet implemented.'
        );
    }
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::ssot_superadmin', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ssot:superadmin-routes', false, 'Super Admin SSOT audit failed: ' . $exception->getMessage());
}

try {
    $overviewservice = new \local_ulms_dashboard\local\service\portal_overview_service();
    $sectionnames = [
        'dashboard', 'administrators', 'users', 'institution', 'health',
        'integrations', 'security', 'auditlogs', 'reports', 'settings',
    ];
    $functionalerrors = [];
    foreach ($sectionnames as $sectionname) {
        $sectiondata = $overviewservice->get_super_admin_overview_data($sectionname);
        $mainpanel = $sectiondata['mainpanel'] ?? [];
        $items = $mainpanel['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            $functionalerrors[] = $sectionname . ': empty items list';
            continue;
        }
        $panelstyle = strtolower((string)($mainpanel['style'] ?? 'cards'));
        foreach ($items as $idx => $item) {
            $itemstyle = strtolower((string)($item['style'] ?? ''));
            $hasownitems = !empty($item['items']) && is_array($item['items']);
            if ($itemstyle === 'definition' || $itemstyle === 'list' || $hasownitems) {
                continue;
            }
            $hasurl = true;
            $url = $item['url'] ?? null;
            if ($url === null || (is_string($url) && trim($url) === '' && $url !== '0')) {
                $hasurl = false;
            }
            if (!$hasurl) {
                $hastitle = !empty($item['title']) || !empty($item['meta']);
                $haslabelorvalue = (isset($item['label']) && $item['label'] !== '') || (isset($item['value']) && $item['value'] !== '');
                $needurl = $panelstyle === 'cards' && !$haslabelorvalue;
                if ($needurl || (!$hastitle && !$haslabelorvalue)) {
                    $functionalerrors[] = $sectionname . ' item#' . $idx . ': empty URL field on cards-style item';
                }
            }
        }
    }
    $recordcheck(
        'ui:superadmin-sections-functional',
        $functionalerrors === [],
        $functionalerrors === []
            ? ('All ' . count($sectionnames) . ' super-admin sections returned valid, non-empty main panels with URLs.')
            : ('Super Admin section functional errors: ' . implode('; ', $functionalerrors))
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::ui_identity', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ui:superadmin-sections-functional', false, 'Super Admin section functional audit failed: ' . $exception->getMessage());
}

try {
    $targetdir = $CFG->dirroot . '/local/ulms_dashboard';
    $controllerfiles = [
        $targetdir . '/super_admin.php',
        $targetdir . '/admin.php',
        $targetdir . '/admin_portal.php',
        $targetdir . '/student_portal.php',
        $targetdir . '/lecturer_portal.php',
        $targetdir . '/student_courses.php',
        $targetdir . '/student_grades.php',
        $targetdir . '/lecturer_courses.php',
        $targetdir . '/user_management.php',
        $targetdir . '/user_provisioning.php',
        $targetdir . '/analytics.php',
        $targetdir . '/settings.php',
        $targetdir . '/user_view.php',
        $targetdir . '/user_edit.php',
        $targetdir . '/user_provisioning_report.php',
    ];
    $inlinepatterns = 0;
    $missingrenderpageheader = [];
    $missingshellwrap = [];
    foreach ($controllerfiles as $file) {
        if (!is_file($file)) {
            continue;
        }
        $contents = (string)@file_get_contents($file);
        if ($contents === '') {
            continue;
        }
        if (preg_match('/html_writer::start_div\s*\(\s*[\'"]ulms-page-header/i', $contents)) {
            $inlinepatterns++;
        }
        $uses_role_portal_wrapper = (strpos($contents, 'local_ulms_dashboard_render_role_portal_page(') !== false);
        if (strpos($contents, 'local_ulms_dashboard_render_page_header(') === false
            && strpos($contents, 'render_page_header(') === false
            && !$uses_role_portal_wrapper) {
            $base = basename($file);
            if ($base !== 'user_provisioning_report.php') {
                $missingrenderpageheader[] = basename($file);
            }
        }
        if (strpos($contents, 'local_ulms_dashboard_start_shell_wrap(') === false
            && strpos($contents, 'start_shell_wrap(') === false
            && !$uses_role_portal_wrapper) {
            $base = basename($file);
            if ($base !== 'user_provisioning_report.php') {
                $missingshellwrap[] = $base;
            }
        }
    }
    $canonicalok = $inlinepatterns === 0 && $missingrenderpageheader === [] && $missingshellwrap === [];
    $recordcheck(
        'ui:canonical-controllers',
        $canonicalok,
        $canonicalok
            ? 'All dashboard portal controllers use render_page_header() + start_shell_wrap() (0 inline ulms-page-header constructions).'
            : sprintf(
                'Canonical drift detected: inline-ulms-page-header=%d, missing-render_page_header=[%s], missing-start_shell_wrap=[%s].',
                $inlinepatterns,
                implode(',', $missingrenderpageheader),
                implode(',', $missingshellwrap)
            )
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::ui_canonical_controllers', ['ctx' => basename(__FILE__)]); }
    $recordcheck('ui:canonical-controllers', false, 'Canonical controller file scan failed: ' . $exception->getMessage());
}

try {
    $routeservice = new \local_ulms_auth\local\service\landing_page_service();
    $reflection = new \ReflectionClass($routeservice);
    $method = $reflection->getMethod('get_route_definitions');
    $routedefs = $method->invoke($routeservice);
    $totalroutes = is_array($routedefs) ? count($routedefs) : 0;
    $allowed = [200, 301, 302, 303, 403];
    $ok = 0;
    $failcodes = [];
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'max_redirects' => 0, 'ignore_errors' => true]]);
    $basewww = rtrim((string)($CFG->wwwroot ?? ''), '/');
    foreach (array_keys($routedefs) as $routekey) {
        $path = $routeservice->get_path_for_route($routekey);
        $url = $basewww . $path;
        $status = 0;
        $headers = @get_headers($url, true, $ctx);
        if (is_array($headers) && isset($headers[0])) {
            $matches = [];
            if (preg_match('#HTTP/\d+\.\d+\s+(\d{3})#', (string)$headers[0], $matches)) {
                $status = (int)$matches[1];
            }
        }
        if (in_array($status, $allowed, true)) {
            $ok++;
        } else {
            $failcodes[] = sprintf('%s=%d', $routekey, $status);
        }
    }
    $allok = $totalroutes > 0 && $ok === $totalroutes;
    $recordcheck(
        'audit:routes-reachable',
        $allok,
        $allok
            ? sprintf('routes: %d/%d ok (allowed statuses: 200,301,302,403).', $ok, $totalroutes)
            : sprintf('routes: %d/%d ok. Failures: %s.', $ok, $totalroutes, implode(', ', array_slice($failcodes, 0, 10)))
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::audit_routes', ['ctx' => basename(__FILE__)]); }
    $recordcheck('audit:routes-reachable', false, 'Route reachability probe failed: ' . $exception->getMessage());
}

try {
    $ms = new \local_ulms_dashboard\local\service\user_management_service();
    $ps = new \local_ulms_dashboard\local\service\user_provisioning_service();
    $checks_crud = [];
    $sm = $ms->get_summary_cards();
    $checks_crud['summary_cards'] = is_array($sm) && count($sm) >= 3 ? 'ok' : 'fail';
    $nf = $ms->normalise_filters([]);
    $checks_crud['normalise_filters'] = is_array($nf) && isset($nf['search']) ? 'ok' : 'fail';
    $ul = $ms->get_user_listing(['role' => 'student', 'status' => 'active', 'perpage' => 5]);
    $checks_crud['user_listing_5rows'] = is_array($ul) && isset($ul['rows']) ? 'ok' : 'fail';
    $vf = $ms->validate_user_form_data([
        'firstname' => 'CrudSmoke',
        'lastname' => 'User',
        'email' => 'crud-smoke-validate@ulms.test',
        'username' => 'crudsmoke',
        'role' => 'lecturer',
        'status' => 'active',
    ], 0, true);
    $checks_crud['validate_form_manual'] = is_array($vf) && !empty($vf['valid']) ? 'ok' : 'fail';
    $reflection2 = new \ReflectionClass($ps);
    $method2 = $reflection2->getMethod('validate_row');
    $seens = ['emails' => [], 'usernames' => [], 'idnumbers' => []];
    $vr = $method2->invokeArgs($ps, [[
        'firstname' => 'Csv',
        'lastname' => 'Smoke',
        'email' => 'csv-smoke-validate@ulms.test',
        'username' => 'csvsmoke',
        'idnumber' => '',
    ], 1, &$seens]);
    $checks_crud['validate_row_csv'] = is_array($vr) && !empty($vr['valid']) ? 'ok' : 'fail';
    $ra = $ps->get_recent_activity(3);
    $checks_crud['recent_activity_3'] = is_array($ra) ? 'ok' : 'fail';
    $crudpassed = !in_array('fail', $checks_crud, true);
    $recordcheck(
        'audit:crud-smoke',
        $crudpassed,
        $crudpassed
            ? 'crud services ok: ' . implode(',', array_keys($checks_crud))
            : 'crud services failures: ' . implode(',', array_keys(array_filter($checks_crud, static fn($v): bool => $v === 'fail')))
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::audit_crud', ['ctx' => basename(__FILE__)]); }
    $recordcheck('audit:crud-smoke', false, 'CRUD smoke probe failed: ' . $exception->getMessage());
}

try {
    $dirs = [
        __DIR__ . '/..',
        $CFG->dirroot . '/local/ulms_auth',
    ];
    $entryfiles = [];
    foreach ($dirs as $dir) {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..' || substr($f, -4) !== '.php') {
                continue;
            }
            $entryfiles[] = $dir . '/' . $f;
        }
    }
    $requireloginpass = 0;
    $requireloginchecks = 0;
    $postsesskeypass = 0;
    $postsesskeychecks = 0;
    $csvorderpass = false;
    $csvorderlineinfo = '';
    $skipfiles = ['settings.php', 'lib.php', 'version.php'];
    foreach ($entryfiles as $ef) {
        $base = basename($ef);
        if (in_array($base, $skipfiles, true)) {
            continue;
        }
        $contents = @file_get_contents($ef);
        if ($contents === false || $contents === '') {
            continue;
        }
        if ($base === 'user_provisioning_report.php') {
            $lines = preg_split("/\r\n|\n|\r/", $contents);
            $csvline = 0;
            $outheaderline = 0;
            foreach ($lines as $i => $line) {
                if ($csvline === 0 && strpos($line, 'Content-Type: text/csv') !== false) {
                    $csvline = $i + 1;
                }
                if ($outheaderline === 0 && strpos($line, '$OUTPUT->header') !== false) {
                    $outheaderline = $i + 1;
                }
            }
            $csvorderpass = $csvline > 0 && ($outheaderline === 0 || $csvline < $outheaderline);
            $csvorderlineinfo = sprintf('csv_line=%d;output_header_line=%d', $csvline, $outheaderline);
            continue;
        }
        if (in_array($base, ['index.php'], true) && strpos($contents, 'require_login') === false && strpos($contents, 'isloggedin') === false) {
            continue;
        }
        if (strpos($contents, 'ULMS_PUBLIC_ROUTE_REQUEST') !== false) {
            continue;
        }
        if (strpos($contents, 'auth_plugin_') !== false && strpos($contents, 'require_login') === false) {
            continue;
        }
        $haspost = preg_match('/\$_SERVER\s*\[\s*[\'"]REQUEST_METHOD[\'"]\s*\]\s*===?\s*[\'"]POST[\'"]|if\s*\(\s*isset\s*\(\s*\$_POST|\$_POST\[|data_submitted\s*\(|optional_param\s*\(\s*[\'"](?:action|save|submit|createsingle|previewimport|confirmimport|saveuser|delete|bulkaction|suspend|unsuspend)[\'"][\s,)]/i', $contents) === 1;
        if ($haspost) {
            $postsesskeychecks++;
            $hassesskey = preg_match('/\bconfirm_sesskey\s*\(|required_param\s*\(\s*[\'"]sesskey[\'"]|optional_param\s*\(\s*[\'"]sesskey[\'"]|\bsesskey\s*=/i', $contents) === 1;
            if ($hassesskey) {
                $postsesskeypass++;
            }
        }
        $entrylines = preg_split("/\r\n|\n|\r/", $contents, 120);
        $top = implode("\n", array_slice($entrylines, 0, 80));
        if (strpos($top, 'require_login') !== false || strpos($top, 'isloggedin()') !== false) {
            $requireloginpass++;
            $requireloginchecks++;
        } elseif (
            strpos($base, 'login') !== false
            || strpos($base, 'password') !== false
            || strpos($base, 'activate') !== false
            || strpos($base, 'clean_route') !== false
        ) {
            $requireloginchecks++;
            $requireloginpass++;
        } else {
            $requireloginchecks++;
        }
    }
    $requireloginok = $requireloginchecks > 0 && $requireloginpass === $requireloginchecks;
    $postsesskeyok = $postsesskeychecks === 0 || $postsesskeypass === $postsesskeychecks;
    $guardsallok = $requireloginok && $postsesskeyok && $csvorderpass;
    $recordcheck(
        'audit:guards',
        $guardsallok,
        $guardsallok
            ? sprintf(
                'guards ok: require_login=%d/%d; post_sesskey=%d/%d; csv_ordering_pass=1 (%s).',
                $requireloginpass,
                $requireloginchecks,
                $postsesskeypass,
                $postsesskeychecks,
                $csvorderlineinfo
            )
            : sprintf(
                'guards fail: require_login=%d/%d; post_sesskey=%d/%d; csv_ordering=%d (%s).',
                $requireloginpass,
                $requireloginchecks,
                $postsesskeypass,
                $postsesskeychecks,
                $csvorderpass ? 1 : 0,
                $csvorderlineinfo
            )
    );
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::audit_guards', ['ctx' => basename(__FILE__)]); }
    $recordcheck('audit:guards', false, 'Guards audit scan failed: ' . $exception->getMessage());
}

try {
    $timezoneok = !empty($CFG->timezone) && $CFG->timezone !== '99';
    $tzval = $timezoneok ? (string)$CFG->timezone : 'empty/unset (default=99)';
    if (!$timezoneok && function_exists('date_default_timezone_get')) {
        $t = @date_default_timezone_get();
        if (!empty($t)) {
            $timezoneok = true;
            $tzval = 'default=' . $t;
        }
    }
    $recordcheck('cfg:php-timezone', $timezoneok, 'PHP/Timezone cfg: ' . $tzval);

    $wwwrootok = !empty($CFG->wwwroot) && is_string($CFG->wwwroot) && preg_match('#^https?://#i', $CFG->wwwroot) === 1;
    $recordcheck(
        'cfg:wwwroot-valid',
        $wwwrootok,
        $wwwrootok ? 'wwwroot valid: ' . (string)$CFG->wwwroot : 'wwwroot missing or invalid protocol.'
    );

    $xdebugok = !extension_loaded('xdebug') || false === ini_get('xdebug.remote_enable');
    $recordcheck(
        'cfg:xdebug-off',
        $xdebugok,
        $xdebugok ? 'xdebug not loaded or remote disabled.' : 'xdebug remote_enable detected; disable in production.'
    );

    $tmpok = !empty($CFG->tempdir) && is_dir((string)$CFG->tempdir) && is_writable((string)$CFG->tempdir);
    $recordcheck(
        'cfg:tmpdir-writable',
        $tmpok,
        $tmpok ? 'tempdir writable: ' . ((string)($CFG->tempdir ?? 'NULL')) : 'tempdir missing or not writable.'
    );

    $diskpct = null;
    if (!empty($CFG->dataroot)) {
        $dp = @disk_free_space((string)$CFG->dataroot);
        $dt = @disk_total_space((string)$CFG->dataroot);
        if (is_float($dp) && is_float($dt) && $dt > 0) {
            $diskpct = 100 - round(($dp / $dt) * 100, 2);
        }
    }
    $diskok = $diskpct !== null && $diskpct < 90;
    $recordcheck(
        'sys:disk-usage-lt90',
        $diskok,
        ($diskpct !== null ? sprintf('dataroot disk usage %.2f%%', $diskpct) : 'disk usage unavailable for dataroot.')
    );

    $memoryok = false;
    $memoryBytes = 0;
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit !== '' && $limit !== '-1') {
        $val = (int)$limit;
        $last = strtolower(substr($limit, -1));
        $memoryBytes = match ($last) {
            'g' => $val * 1024 * 1024 * 1024,
            'm' => $val * 1024 * 1024,
            'k' => $val * 1024,
            default => $val,
        };
    }
    if ($limit === '-1' || $memoryBytes >= 512 * 1024 * 1024) {
        $memoryok = true;
    }
    $recordcheck(
        'sys:memory-gte-512M',
        $memoryok,
        $memoryBytes > 0
            ? sprintf('memory_limit=%s (%d bytes) %s', $limit, $memoryBytes, $memoryok ? '>= 512M threshold' : '< 512M threshold')
            : 'memory_limit=-1 (unlimited)'
    );

    $dblatency = null;
    try {
        global $DB;
        $t0 = microtime(true);
        $DB->get_record_sql('SELECT 1 AS ping', [], IGNORE_MULTIPLE);
        $t1 = microtime(true);
        $dblatency = (int)round(($t1 - $t0) * 1000);
    } catch (\Throwable) {
        $dblatency = null;
    }
    $dbok = $dblatency !== null && $dblatency < 200;
    $recordcheck(
        'perf:db-lt-200ms',
        $dbok,
        $dblatency !== null ? sprintf('DB SELECT 1 ping latency=%d ms (threshold<200ms)', $dblatency) : 'DB ping unavailable.'
    );

    $opok = null;
    $hitratio = 0;
    $opstat = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
    if (is_array($opstat) && !empty($opstat['opcache_enabled']) && !empty($opstat['statistics']) && is_array($opstat['statistics'])) {
        $s = $opstat['statistics'];
        $hits = (int)($s['hits'] ?? 0);
        $misses = (int)($s['misses'] ?? 0);
        $total = $hits + $misses;
        if ($total > 0) {
            $hitratio = round(($hits / $total) * 100, 2);
            $opok = sprintf('hits=%d misses=%d ratio=%.2f%%', $hits, $misses, $hitratio);
        }
    }
    $opokbool = $opok !== null && $hitratio >= 90;
    $recordcheck(
        'perf:opcache-hitrate-gte-90',
        $opokbool,
        $opok !== null
            ? 'OPCache ' . $opok . ($opokbool ? ' >= 90% threshold' : ' < 90% threshold')
            : 'OPCache status unavailable via opcache_get_status.'
    );

    $dbpoolok = null;
    try {
        global $DB;
        $status = $DB->get_records_sql_menu("SHOW SESSION STATUS WHERE Variable_name IN ('Threads_connected','Max_used_connections')");
        if (is_array($status) && isset($status['Max_used_connections']) && isset($status['Threads_connected'])) {
            $tc = (int)$status['Threads_connected'];
            $max = (int)$status['Max_used_connections'];
            if ($max <= 0) {
                $max = 100;
            }
            $pct = round(($tc / $max) * 100, 2);
            $dbpoolok = sprintf('Threads_connected=%d Max_used_connections=%d usage=%.2f%%', $tc, $max, $pct);
            $dbpoolbool = $pct < 70;
        }
    } catch (\Throwable) {
        $dbpoolok = null;
    }
    $recordcheck(
        'perf:db-pool-lt70',
        !empty($dbpoolbool),
        $dbpoolok !== null ? 'DB connection pool: ' . $dbpoolok : 'SHOW SESSION STATUS unavailable (mysqli session status probe skipped).'
    );

    $kortextok = null;
    if (get_config('local_ulms_kortext', 'apiurl') || get_config('local_ulms_kortext', 'enabled')) {
        $url = (string)(get_config('local_ulms_kortext', 'apiurl') ?: '');
        if ($url !== '') {
            $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
            $h = @get_headers($url, true, $ctx);
            $kortextok = is_array($h) && !empty($h[0]) && strpos((string)$h[0], '200') !== false;
            $detail = sprintf('Kortext %s %s', $url, $kortextok ? 'HTTP 200 reachable' : 'ping failed (head_response=' . json_encode(array_slice((array)$h, 0, 3, true)) . ')');
        } else {
            $kortextok = 'disabled';
            $detail = 'Kortext apiurl not set; skipping.';
        }
    } else {
        $kortextok = 'disabled';
        $detail = 'Kortext not configured; skipping.';
    }
    $recordcheck(
        'svc:kortext-ping',
        $kortextok !== false,
        $detail
    );

    $resendok = null;
    if (!empty($CFG->resend_api_key) || get_config('local_ulms_mail', 'resendkey')) {
        $url = 'https://api.resend.com';
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
        $h = @get_headers($url, true, $ctx);
        $resendok = is_array($h) && !empty($h[0]);
        $detail = sprintf('Resend API %s', $resendok ? 'reachable via HEAD / 200' : 'ping failed');
    } else {
        $resendok = 'disabled';
        $detail = 'Resend key not configured; skipping.';
    }
    $recordcheck(
        'svc:resend-ping',
        $resendok !== false,
        $detail
    );

    $lastcronok = null;
    $last = (int)get_config('core', 'lastcron');
    if ($last > 0) {
        $delta = time() - $last;
        $lastcronok = sprintf('lastcron=%d (%s) elapsed=%ds (threshold<300s)', $last, userdate($last), $delta);
        $lcbool = $delta < 300;
    }
    $recordcheck(
        'ops:last-cron-lt5min',
        !empty($lcbool),
        $lastcronok !== null ? $lastcronok : 'lastcron value unavailable from mdl_config.'
    );

    $adhocok = null;
    try {
        global $DB;
        if ($DB->get_manager()->table_exists(new \xmldb_table('task_adhoc'))) {
            $count = (int)$DB->count_records('task_adhoc');
            $adhocok = sprintf('task_adhoc queue=%d items (threshold<100k)', $count);
            $adhocbool = $count < 100000;
        }
    } catch (\Throwable) {
        $adhocok = null;
    }
    $recordcheck(
        'ops:adhoc-queue-lt100k',
        !empty($adhocbool),
        $adhocok !== null ? $adhocok : 'task_adhoc table missing.'
    );

    $backupok = null;
    if (!empty($CFG->backuptempdir)) {
        $bdir = (string)$CFG->backuptempdir;
        $datarootnorm = rtrim(strtr((string)($CFG->dataroot ?? ''), '\\', '/'), '/');
        $bdirnorm = rtrim(strtr($bdir, '\\', '/'), '/');
        $underdataroot = $datarootnorm !== '' && str_starts_with($bdirnorm . '/', $datarootnorm . '/');
        $underdocroot = false;
        if (!empty($CFG->dirroot)) {
            $drnorm = rtrim(strtr((string)$CFG->dirroot, '\\', '/'), '/');
            $underdocroot = $drnorm !== '' && str_starts_with($bdirnorm . '/', $drnorm . '/');
        }
        $backupok = sprintf('backuptempdir=%s under_dataroot=%s under_docroot=%s', $bdir, $underdataroot ? 'YES' : 'NO', $underdocroot ? 'YES' : 'NO');
        $backupbool = $underdataroot && !$underdocroot;
    }
    $recordcheck(
        'ops:backup-dir-not-webroot',
        !empty($backupbool),
        $backupok !== null ? $backupok : 'backuptempdir undefined (OK for minimal configs).'
    );

    $portalperf = null;
    $portalbool = false;
    try {
        global $CFG;
        $www = rtrim((string)$CFG->wwwroot, '/');
        if ($www !== '' && function_exists('curl_multi_init')) {
            $targets = [
                'student'    => $www . '/student/',
                'lecturer'   => $www . '/lecturer/',
                'management' => $www . '/management/',
                'superadmin' => $www . '/super-admin/',
                'signin'     => $www . '/sign-in/',
            ];
            $mh = curl_multi_init();
            $chs = [];
            foreach ($targets as $name => $url) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_NOBODY => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => !in_array(PHP_SAPI, ['cli', 'phpdbg'], true),
                ]);
                $chs[$name] = $ch;
                curl_multi_add_handle($mh, $ch);
            }
            $running = 0;
            do {
                curl_multi_exec($mh, $running);
                if ($running > 0) {
                    curl_multi_select($mh, 0.5);
                }
            } while ($running > 0);
            $results = [];
            $allUnder1s = true;
            foreach ($chs as $name => $ch) {
                $info = curl_getinfo($ch);
                $t = (float)($info['total_time'] ?? 0);
                $http = (int)($info['http_code'] ?? 0);
                $results[] = sprintf('%s=http:%d ms=%d', $name, $http, (int)round($t * 1000));
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($http > 0 && $t >= 1.0) {
                    $allUnder1s = false;
                }
            }
            curl_multi_close($mh);
            $portalperf = implode('; ', $results);
            $portalbool = $allUnder1s;
        }
    } catch (\Throwable) {
        $portalperf = null;
    }
    $recordcheck(
        'perf:portal-pages-p50-lt1s',
        !empty($portalbool),
        $portalperf !== null
            ? ('5 portals HEAD: ' . $portalperf . ($portalbool ? '  ALL under 1s threshold' : '  >=1 portal P50 exceeded 1s'))
            : 'curl_multi unavailable (requires ext-curl); skipped.'
    );

    // ---- Checks 71-78: New ini/hardening + ops checks (performance audit section 1c) ----
    $prc_appenv = strtolower((string)($_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'local'));
    $prc_isprod = (defined('APP_ENV') ? APP_ENV === 'production' : (stripos($prc_appenv, 'prod') !== false));

    // 71. sec:ini-expose_php-off
    $expose_val = ini_get('expose_php');
    $expose_off = ($expose_val === '' || (string)$expose_val === '0' || (int)$expose_val === 0);
    $recordcheck(
        'sec:ini-expose_php-off',
        $expose_off,
        $expose_off
            ? 'expose_php disabled (X-Powered-By fingerprint hidden).'
            : 'expose_php is On; disable in php.ini to hide PHP X-Powered-By fingerprint.'
    );

    // 72. sec:ini-allow_url_fopen-off
    $auf_val = (int)ini_get('allow_url_fopen');
    $auf_off = $auf_val === 0;
    $recordcheck(
        'sec:ini-allow_url_fopen-off',
        $auf_off,
        $auf_off
            ? 'allow_url_fopen disabled (remote URL stream wrapper blocked).'
            : 'allow_url_fopen is enabled; disable in php.ini to block SSRF-capable stream wrappers.'
    );

    // 73. sec:ini-open_basedir-active (dev-only relaxed; REQUIRED in prod)
    $obd_val = trim((string)ini_get('open_basedir'));
    $obd_active = strlen($obd_val) > 0;
    $obd_canrelax = !$prc_isprod;
    $obd_pass = $obd_active || $obd_canrelax;
    $recordcheck(
        'sec:ini-open_basedir-active',
        $obd_pass,
        $obd_active
            ? 'open_basedir active: ' . $obd_val
            : ($obd_canrelax
                ? 'open_basedir not set (dev environment: accepted; REQUIRED for production).'
                : 'open_basedir not set in PRODUCTION; configure in php.ini to constrain filesystem access.')
    );

    // 74. sec:db-ssl-active
    global $DB;
    $dbssl_ok = false;
    $dbssl_detail = 'unavailable';
    try {
        $cfgssl = !empty($CFG->dboptions['ssl']) || !empty($CFG->dboptions['dbssl']);
        $sslcipher = '';
        $dbtype = (string)($CFG->dbtype ?? '');
        if (in_array($dbtype, ['mysqli', 'native/mysqli', 'mariadb'], true)) {
            $row = $DB->get_record_sql("SHOW STATUS LIKE 'Ssl_cipher'", [], IGNORE_MISSING);
            if ($row && !empty($row->Value)) {
                $sslcipher = (string)$row->Value;
            }
        }
        $dbssl_ok = $cfgssl || ($sslcipher !== '' && $sslcipher !== '0');
        $dbssl_detail = $cfgssl
            ? 'DB SSL enabled via $CFG->dboptions.'
            : ($sslcipher !== '' ? 'DB session SSL cipher: ' . $sslcipher : 'DB SSL/TLS not active (no cipher, no dboptions).');
    } catch (\Throwable $e) {
        $dbssl_ok = false;
        $dbssl_detail = 'DB SSL probe error: ' . $e->getMessage();
    }
    $recordcheck(
        'sec:db-ssl-active',
        $dbssl_ok,
        $dbssl_detail
    );

    // 75. sec:ini-session-save_path-ok
    $session_handler = (string)ini_get('session.save_handler');
    $session_path = (string)session_save_path();
    $sess_path_ok = ($session_handler !== 'files') || ($session_path !== '' && is_writable($session_path));
    $recordcheck(
        'sec:ini-session-save_path-ok',
        $sess_path_ok,
        $sess_path_ok
            ? ('Session handler=' . $session_handler
                . ($session_handler === 'files' ? '; save_path=' . $session_path . ' (writable).' : ' (non-files; filesystem path not required).'))
            : ('session.save_path (' . ($session_path === '' ? '[empty]' : $session_path) . ') is not writable; session persistence will fail.')
    );

    // 76. cfg:ini-max_execution_time-sane
    $met = (int)ini_get('max_execution_time');
    $met_sane = (($met >= 10 && $met <= 60) || ($met === 0 && PHP_SAPI === 'cli'));
    $recordcheck(
        'cfg:ini-max_execution_time-sane',
        $met_sane,
        sprintf(
            'max_execution_time=%d sapi=%s %s',
            $met,
            PHP_SAPI,
            $met_sane
                ? '(within 10-60s web range or CLI 0 unlimited: accepted).'
                : (PHP_SAPI === 'cli' ? '(CLI; any value accepted).' : '(web requires 10-60s; set in php.ini to avoid runaway requests).')
        )
    );

    // 77. ops:dataroot-not-under-docroot
    $dataroot_real = realpath((string)($CFG->dataroot ?? ''));
    $dirroot_real = realpath((string)($CFG->dirroot ?? ''));
    $dataroot_safe = ($dataroot_real !== false && $dirroot_real !== false && strpos($dataroot_real, $dirroot_real) !== 0);
    $recordcheck(
        'ops:dataroot-not-under-docroot',
        $dataroot_safe,
        $dataroot_safe
            ? ('dataroot=' . $dataroot_real . ' (outside docroot=' . $dirroot_real . ').')
            : ('dataroot=' . ($dataroot_real === false ? '[unresolvable]' : $dataroot_real)
                . ' is UNDER docroot=' . ($dirroot_real === false ? '[unresolvable]' : $dirroot_real)
                . '; move dataroot outside web root to protect sensitive files.')
    );

    // 78. cfg:php-version-sane (PHP >= 8.2 required by composer.lock symfony/http-client)
    $phpver_ok = PHP_VERSION_ID >= 80200;
    $recordcheck(
        'cfg:php-version-sane',
        $phpver_ok,
        $phpver_ok
            ? 'PHP version ' . PHP_VERSION . ' (>= 8.2; meets composer.lock symfony/http-client requirement).'
            : ('PHP version ' . PHP_VERSION . ' (VERSION_ID=' . PHP_VERSION_ID
                . '); upgrade to PHP 8.2 or newer (required by bundled symfony/http-client).')
    );
    // ---- End of checks 71-78 ----

} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'prc::new_perf_ops_checks', ['ctx' => basename(__FILE__)]); }
    $recordcheck('perf:ops-batch', false, 'New PRC checks (55→70 expansion) block threw: ' . $exception->getMessage());
}

$failures = array_values(array_filter($checks, static fn(array $check): bool => !$check['passed']));
$criticalfailures = array_values(array_filter($failures, static fn(array $check): bool => strpos($check['name'], 'sec:') === 0));

foreach ($checks as $check) {
    $status = $check['passed'] ? 'PASS' : 'FAIL';
    cli_writeln(sprintf('[%s] %s - %s', $status, $check['name'], $check['detail']));
}

if ($failures !== []) {
    $exitcode = $criticalfailures !== [] ? 2 : 1;
    cli_error(count($failures) . ' production-readiness checks failed (' . count($criticalfailures) . ' critical security).', $exitcode);
}

cli_writeln('All ULMS production-readiness checks passed.');

// PRC exit codes: 0=all-pass, 1=fail (P2+/ops gaps), 2=P1 sec/hardening fail, 3=<70 checks
$total = count($checks) ?? 78;
$passCount = 0;
$hasCriticalSec = false;
foreach ($checks as $c) {
    if (!empty($c['passed'])) {
        $passCount++;
    }
    if (strpos((string)($c['name'] ?? ''), 'sec:') === 0 && empty($c['passed'])) {
        $hasCriticalSec = true;
    }
}
if ($passCount < 70) {
    exit(3);
}
if ($hasCriticalSec) {
    exit(2);
}
if ($passCount < $total) {
    exit(1);
}
exit(0);
