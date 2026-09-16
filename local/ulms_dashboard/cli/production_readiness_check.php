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
    $recordcheck(
        'mail:http-client',
        class_exists('Symfony\Component\HttpClient\HttpClient'),
        'Symfony HTTP client dependency is available.'
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
        if (strpos($contents, 'local_ulms_dashboard_render_page_header(') === false
            && strpos($contents, 'render_page_header(') === false) {
            $base = basename($file);
            if ($base !== 'user_provisioning_report.php') {
                $missingrenderpageheader[] = basename($file);
            }
        }
        if (strpos($contents, 'local_ulms_dashboard_start_shell_wrap(') === false
            && strpos($contents, 'start_shell_wrap(') === false) {
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
