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

namespace local_ulms_dashboard\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * Single-source-of-truth shared helpers for all ULMS dashboard portal
 * services.  Eliminates duplicated language-string guards, inline SVG
 * icon strings, header context maps, and Admin-portal shortcut
 * definitions that were previously copy-pasted across two or more
 * service classes.
 *
 * Every public method in this class is intentionally static — no
 * mutable instance state is ever held here.
 */
final class dashboard_commons {

    /**
     * Labels that must receive the "Admin: " prefix when they are
     * rendered inside the Super Admin sidebar to disambiguate
     * collisions with native Super Admin navigation labels that use
     * the same language identifier.
     */
    private const ADMIN_PREFIX_LABEL_KEYS = [
        'adminnavreports',
        'adminnavauditlogs',
        'adminnavsettings',
    ];

    /**
     * Safe language string resolver (D6 dedup).  Returns the resolved
     * string when available; otherwise returns the caller-supplied
     * fallback.  The explicit `[[` detection prevents Moodle
     * placeholder leaks from surfacing in the UI when caches are
     * stale or strings are missing.
     *
     * @param string $identifier String identifier for local_ulms_dashboard.
     * @param string $fallback   Literal fallback text (identical to the
     *                           language file value).
     * @param mixed  $a          Optional string substitution value.
     * @return string
     */
    public static function safe_lang_string(string $identifier, string $fallback, $a = null): string {
        try {
            if ($a === null) {
                $value = @get_string($identifier, 'local_ulms_dashboard');
            } else {
                $value = @get_string($identifier, 'local_ulms_dashboard', $a);
            }
        } catch (\Throwable) {
            $value = '';
        }
        if (!is_string($value) || $value === '' || strpos($value, '[[') !== false) {
            if ($a !== null && is_scalar($a)) {
                $str = (string)$a;
                if (str_contains($fallback, '{$a}')) {
                    return strtr($fallback, ['{$a}' => $str]);
                }
                return trim($fallback . ' ' . $str);
            }
            return $fallback;
        }
        return $value;
    }

    /**
     * Shared safe-eyebrow helper used inside header maps when a
     * language string for an eyebrow label might not be installed yet.
     *
     * @param string $identifier
     * @param string $fallback
     * @return string
     */
    public static function safe_eyebrow(string $identifier, string $fallback): string {
        return self::safe_lang_string($identifier, $fallback);
    }

    /**
     * Inline SVG icon registry (D3 dedup).  Returns the canonical
     * stroke-icon markup for the given key, or an empty string for
     * unknown keys.  All icons are size-agnostic via `viewBox` and
     * inherit `currentColor`.
     *
     * @param string $key
     * @return string
     */
    public static function icon_svg(string $key): string {
        static $registry = null;
        if ($registry === null) {
            $registry = self::build_icon_registry();
        }
        return $registry[$key] ?? '';
    }

    /**
     * Returns header context map definitions for the Admin portal
     * universe (D1 dedup).  Keys are section identifiers exactly as
     * used by admin_portal_service; values are flat [titles, meta,
     * eyebrow] sub-arrays keyed identically.
     *
     * @return array{
     *     titles: array<string,string>,
     *     meta: array<string,string>,
     *     eyebrow: array<string,string>,
     *     navigationaria: string,
     *     default_eyebrow_key: string,
     *     default_title_key: string,
     * }
     */
    public static function get_admin_header_defs(): array {
        $titles = [
            'dashboard' => get_string('admindashboard', 'local_ulms_dashboard'),
            'users' => get_string('adminusermanagementlink', 'local_ulms_dashboard'),
            'provisioning' => get_string('adminuserprovisioning', 'local_ulms_dashboard'),
            'bulkupload' => get_string('adminnavbulkupload', 'local_ulms_dashboard'),
            'analytics' => get_string('analyticsdashboard', 'local_ulms_dashboard'),
            'academics' => get_string('manageacademicstructurelink', 'local_ulms_dashboard'),
            'courses' => get_string('admincoursestitle', 'local_ulms_dashboard'),
            'schedule' => get_string('adminclassscheduletitle', 'local_ulms_dashboard'),
            'attendanceaudit' => get_string('adminattendanceaudittitle', 'local_ulms_dashboard'),
            'lecturers' => self::safe_lang_string(
                'adminlecturerstitle',
                'Lecturer allocations'
            ),
            'reports' => get_string('adminreportstitle', 'local_ulms_dashboard'),
            'auditlogs' => get_string('adminauditlogstitle', 'local_ulms_dashboard'),
            'settings' => get_string('adminsettingsheading', 'local_ulms_dashboard'),
        ];

        $meta = [
            'dashboard' => get_string('admindashboarddesc', 'local_ulms_dashboard'),
            'users' => get_string('adminusermanagementlinkdesc', 'local_ulms_dashboard'),
            'provisioning' => get_string('adminuserprovisioningdesc', 'local_ulms_dashboard'),
            'bulkupload' => get_string('adminbulkuploaddesc', 'local_ulms_dashboard'),
            'analytics' => get_string('analyticsdashboarddesc', 'local_ulms_dashboard'),
            'academics' => get_string('adminacademicstructurelinkdesc', 'local_ulms_dashboard'),
            'courses' => get_string('admincoursesdesc', 'local_ulms_dashboard'),
            'schedule' => get_string('adminclassscheduledesc', 'local_ulms_dashboard'),
            'attendanceaudit' => get_string('adminattendanceauditdesc', 'local_ulms_dashboard'),
            'lecturers' => self::safe_lang_string(
                'adminlecturersdesc',
                'Map teaching staff to their assigned courses and manage bulk lecturer-to-course enrolments.'
            ),
            'reports' => get_string('adminreportsdesc', 'local_ulms_dashboard'),
            'auditlogs' => get_string('adminauditlogsdesc', 'local_ulms_dashboard'),
            'settings' => get_string('adminsettingsdesc', 'local_ulms_dashboard'),
        ];

        $eyebrow = [
            'dashboard' => get_string('management.dashboard.eyebrow', 'local_ulms_dashboard'),
            'users' => get_string('management.users.eyebrow', 'local_ulms_dashboard'),
            'provisioning' => get_string('management.provisioning.eyebrow', 'local_ulms_dashboard'),
            'bulkupload' => get_string('management.provisioning.eyebrow', 'local_ulms_dashboard'),
            'analytics' => get_string('management.analytics.eyebrow', 'local_ulms_dashboard'),
            'academics' => get_string('management.academics.eyebrow', 'local_ulms_dashboard'),
            'courses' => get_string('management.courses.eyebrow', 'local_ulms_dashboard'),
            'schedule' => get_string('admin.schedule.eyebrow', 'local_ulms_dashboard'),
            'attendanceaudit' => get_string('admin.attendanceaudit.eyebrow', 'local_ulms_dashboard'),
            'lecturers' => self::safe_eyebrow('management.lecturers.eyebrow', 'Academics · Lecturer allocations'),
            'reports' => get_string('management.reports.eyebrow', 'local_ulms_dashboard'),
            'auditlogs' => get_string('management.auditlogs.eyebrow', 'local_ulms_dashboard'),
            'settings' => get_string('management.settings.eyebrow', 'local_ulms_dashboard'),
        ];

        return [
            'titles' => $titles,
            'meta' => $meta,
            'eyebrow' => $eyebrow,
            'navigationaria' => get_string('adminportalnavigation', 'local_ulms_dashboard'),
            'default_eyebrow_key' => 'management.users.eyebrow',
            'default_title_key' => 'admindashboard',
        ];
    }

    /**
     * Returns Super-Admin-native header context map definitions (10
     * sections; D1 dedup).  Does NOT include the inherited Admin
     * portal `admin_*` prefixed copies — those are generated at
     * render-time by {@see self::prefix_admin_header_defs_into()} to
     * keep a single authoritative source.
     *
     * @return array{
     *     titles: array<string,string>,
     *     meta: array<string,string>,
     *     eyebrow: array<string,string>,
     *     navigationaria: string,
     *     default_eyebrow_key: string,
     *     default_title_key: string,
     * }
     */
    public static function get_superadmin_native_header_defs(): array {
        $titles = [
            'dashboard' => get_string('superadmindashboard', 'local_ulms_dashboard'),
            'administrators' => get_string('superadminadministrators', 'local_ulms_dashboard'),
            'users' => get_string('superadminusers', 'local_ulms_dashboard'),
            'institution' => get_string('superadmininstitution', 'local_ulms_dashboard'),
            'health' => get_string('superadminhealth', 'local_ulms_dashboard'),
            'integrations' => get_string('superadminintegrations', 'local_ulms_dashboard'),
            'security' => get_string('superadminsecurity', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadminauditlogs', 'local_ulms_dashboard'),
            'reports' => get_string('superadminreports', 'local_ulms_dashboard'),
            'settings' => get_string('superadminsettings', 'local_ulms_dashboard'),
        ];
        $meta = [
            'dashboard' => get_string('superadmindashboarddesc', 'local_ulms_dashboard'),
            'administrators' => get_string('superadminadministratorsdesc', 'local_ulms_dashboard'),
            'users' => get_string('superadminusersdesc', 'local_ulms_dashboard'),
            'institution' => get_string('superadmininstitutiondesc', 'local_ulms_dashboard'),
            'health' => get_string('superadminhealthdesc', 'local_ulms_dashboard'),
            'integrations' => get_string('superadminintegrationsdesc', 'local_ulms_dashboard'),
            'security' => get_string('superadminsecuritydesc', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadminauditlogsdesc', 'local_ulms_dashboard'),
            'reports' => get_string('superadminreportsdesc', 'local_ulms_dashboard'),
            'settings' => get_string('superadminsettingsdesc', 'local_ulms_dashboard'),
        ];
        $eyebrow = [
            'dashboard' => get_string('superadmin.dashboard.eyebrow', 'local_ulms_dashboard'),
            'administrators' => get_string('superadmin.administrators.eyebrow', 'local_ulms_dashboard'),
            'users' => get_string('superadmin.users.eyebrow', 'local_ulms_dashboard'),
            'institution' => get_string('superadmin.institution.eyebrow', 'local_ulms_dashboard'),
            'health' => get_string('superadmin.health.eyebrow', 'local_ulms_dashboard'),
            'integrations' => get_string('superadmin.integrations.eyebrow', 'local_ulms_dashboard'),
            'security' => get_string('superadmin.security.eyebrow', 'local_ulms_dashboard'),
            'auditlogs' => get_string('superadmin.auditlogs.eyebrow', 'local_ulms_dashboard'),
            'reports' => get_string('superadmin.reports.eyebrow', 'local_ulms_dashboard'),
            'settings' => get_string('superadmin.settings.eyebrow', 'local_ulms_dashboard'),
        ];

        return [
            'titles' => $titles,
            'meta' => $meta,
            'eyebrow' => $eyebrow,
            'navigationaria' => get_string('superadminportalnavigation', 'local_ulms_dashboard'),
            'default_eyebrow_key' => 'superadmin.dashboard.eyebrow',
            'default_title_key' => 'superadmindashboard',
        ];
    }

    /**
     * Returns the trimmed 10-item Super Admin header map used inside
     * `portal_overview_service` for siteadmin shell rendering (D1
     * dedup).  This intentionally omits meta/navigationaria because
     * the overview service only consumes title + eyebrow.
     *
     * @return array{titles:array<string,string>,eyebrow:array<string,string>,default_eyebrow_key:string,default_title_key:string}
     */
    public static function get_portal_overview_sa_header_defs(): array {
        $def = self::get_superadmin_native_header_defs();
        $def['titles']['dashboard'] = get_string('superadminplatformoverviewheading', 'local_ulms_dashboard');
        $def['default_title_key'] = 'superadminplatformoverviewheading';
        return [
            'titles' => $def['titles'],
            'eyebrow' => $def['eyebrow'],
            'default_eyebrow_key' => $def['default_eyebrow_key'],
            'default_title_key' => $def['default_title_key'],
        ];
    }

    /**
     * Given Super Admin native header defs, merges the inherited
     * Admin-portal 13+1 sections using `admin_` prefixed keys (D1
     * dedup).  The original `$definitions` array is mutated in-place
     * and also returned for chaining.
     *
     * @param array{titles:array,meta:array,eyebrow:array} &$definitions
     * @return array
     */
    public static function merge_admin_prefixed_defs_into(array &$definitions): array {
        $admin = self::get_admin_header_defs();
        foreach (['titles', 'meta', 'eyebrow'] as $bucket) {
            foreach ($admin[$bucket] as $section => $value) {
                $definitions[$bucket]['admin_' . $section] = $value;
            }
        }
        if (isset($definitions['eyebrow'])) {
            $fallback = get_string('management.users.eyebrow', 'local_ulms_dashboard');
            foreach (array_keys($admin['titles']) as $section) {
                if (!isset($definitions['eyebrow']['admin_' . $section])) {
                    $definitions['eyebrow']['admin_' . $section] = $fallback;
                }
            }
        }
        return $definitions;
    }

    /**
     * Returns the canonical list of Admin portal shortcut items used
     * by the Super Admin "Admin Portal" sidebar group (D4 dedup).
     *
     * Every item is a flat record containing:
     *   - `section_key`    — base section key (without admin_ prefix)
     *   - `label_lang_id`  — language identifier for local_ulms_dashboard
     *   - `label_fallback` — literal fallback string
     *   - `route_key`      — routing key for landing_page_service
     *   - `route_params`   — optional extra route parameters
     *   - `icon_key`       — key accepted by {@see self::icon_svg()}
     *   - `prefix_label`   — true if "Admin: " prefix should be applied
     *
     * Callers (super_admin_portal_service) apply the `admin_` key
     * prefix and "Admin: " label prefix at render time so the
     * canonical source here stays free of Super-Admin-specific
     * concerns.
     *
     * @return array<int, array{section_key:string,label_lang_id:string,label_fallback:string,route_key:string,route_params:array<string,mixed>,icon_key:string,prefix_label:bool}>
     */
    public static function get_admin_portal_shortcut_defs(): array {
        return [
            ['section_key' => 'dashboard',        'label_lang_id' => 'adminnavdashboard',        'label_fallback' => 'Dashboard',          'route_key' => 'management.dashboard',                 'route_params' => [],                'icon_key' => 'dashboard',        'prefix_label' => false],
            ['section_key' => 'users',            'label_lang_id' => 'adminnavusermanagement',   'label_fallback' => 'User management',    'route_key' => 'management.users',                     'route_params' => [],                'icon_key' => 'users',            'prefix_label' => false],
            ['section_key' => 'provisioning',     'label_lang_id' => 'adminnavprovisioning',     'label_fallback' => 'User provisioning',  'route_key' => 'management.provisioning',              'route_params' => [],                'icon_key' => 'provisioning',     'prefix_label' => false],
            ['section_key' => 'bulkupload',       'label_lang_id' => 'adminnavbulkupload',       'label_fallback' => 'Bulk upload',        'route_key' => 'management.bulkupload',                'route_params' => [],                'icon_key' => 'bulk',             'prefix_label' => false],
            ['section_key' => 'analytics',        'label_lang_id' => 'adminnavanalytics',        'label_fallback' => 'Analytics',          'route_key' => 'management.analytics',                 'route_params' => [],                'icon_key' => 'analytics',        'prefix_label' => false],
            ['section_key' => 'academics',        'label_lang_id' => 'adminnavacademics',        'label_fallback' => 'Academic structure', 'route_key' => 'management.academics',                 'route_params' => [],                'icon_key' => 'academics',        'prefix_label' => false],
            ['section_key' => 'academics.levels', 'label_lang_id' => 'adminnavlevels',           'label_fallback' => 'Academic levels',    'route_key' => 'management.academicslevels',           'route_params' => [],                'icon_key' => 'level',            'prefix_label' => false],
            ['section_key' => 'academics.mappings','label_lang_id' => 'adminnavmappings',        'label_fallback' => 'Course mappings',    'route_key' => 'management.academicsmappings',         'route_params' => [],                'icon_key' => 'mapping',          'prefix_label' => false],
            ['section_key' => 'academics.import', 'label_lang_id' => 'adminnavimport',           'label_fallback' => 'CSV import',         'route_key' => 'management.academicsimport',           'route_params' => [],                'icon_key' => 'import',           'prefix_label' => false],
            ['section_key' => 'courses',          'label_lang_id' => 'adminnavcourses',          'label_fallback' => 'Courses',            'route_key' => 'management.courses',                   'route_params' => [],                'icon_key' => 'courses',          'prefix_label' => false],
            ['section_key' => 'academics.schedule','label_lang_id' => 'adminnavclassschedule',   'label_fallback' => 'Class schedule',     'route_key' => 'management.academicsschedule',         'route_params' => [],                'icon_key' => 'classschedule',    'prefix_label' => false],
            ['section_key' => 'academics.attendanceaudit','label_lang_id' => 'adminnavattendanceaudit','label_fallback' => 'Attendance audit','route_key' => 'management.academicsattendanceaudit', 'route_params' => [],                'icon_key' => 'attendanceaudit',  'prefix_label' => false],
            ['section_key' => 'lecturers',        'label_lang_id' => 'adminnavlecturers',        'label_fallback' => 'Lecturer allocations','route_key' => 'management.lecturers',                'route_params' => [],                'icon_key' => 'lecturers',        'prefix_label' => false],
            ['section_key' => 'reports',          'label_lang_id' => 'adminnavreports',          'label_fallback' => 'Reports',            'route_key' => 'management.reports',                   'route_params' => [],                'icon_key' => 'reports',          'prefix_label' => true],
            ['section_key' => 'auditlogs',        'label_lang_id' => 'adminnavauditlogs',        'label_fallback' => 'Audit logs',         'route_key' => 'management.auditlogs',                 'route_params' => [],                'icon_key' => 'audit',            'prefix_label' => true],
            ['section_key' => 'settings',         'label_lang_id' => 'adminnavsettings',         'label_fallback' => 'Settings',           'route_key' => 'management.settings',                  'route_params' => [],                'icon_key' => 'settings',         'prefix_label' => true],
        ];
    }

    /**
     * Builds the full SVG icon registry exactly once per request.
     *
     * @return array<string,string>
     */
    private static function build_icon_registry(): array {
        $attrs = 'viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';

        return [
            'dashboard' => '<svg ' . $attrs . '><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
            'health'    => '<svg ' . $attrs . '><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
            'reports'   => '<svg ' . $attrs . '><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><path d="M9 18V12"/><path d="M12 18v-6"/><path d="M15 18v-3"/></svg>',
            'admins'    => '<svg ' . $attrs . '><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-4-6 4 1.5-7.5L2 9h7z"/></svg>',
            'users'     => '<svg ' . $attrs . '><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'institution' => '<svg ' . $attrs . '><path d="M3 21h18"/><path d="M3 10h18"/><path d="M5 6l7-3 7 3"/><path d="M4 10v11"/><path d="M20 10v11"/><path d="M8 14v3"/><path d="M12 14v3"/><path d="M16 14v3"/></svg>',
            'audit'     => '<svg ' . $attrs . '><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg>',
            'integrations' => '<svg ' . $attrs . '><rect x="2" y="3" width="20" height="6" rx="1.5"/><rect x="2" y="15" width="20" height="6" rx="1.5"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>',
            'security'  => '<svg ' . $attrs . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'settings'  => '<svg ' . $attrs . '><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'analytics' => '<svg ' . $attrs . '><path d="M3 3v18h18"/><path d="m7 14 4-3 3 3 5-5"/></svg>',
            'academics' => '<svg ' . $attrs . '><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
            'courses'   => '<svg ' . $attrs . '><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'provisioning' => '<svg ' . $attrs . '><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>',
            'bulk'      => '<svg ' . $attrs . '><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M8 13h8M8 17h5"/></svg>',
            'lecturers' => '<svg ' . $attrs . '><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M12 13.5l1.5 1.5 3-3"/></svg>',
            'college'   => '<svg ' . $attrs . '><path d="M3 21h18"/><path d="M5 21V9l7-5 7 5v12"/><path d="M9 21V12h6v9"/></svg>',
            'department'=> '<svg ' . $attrs . '><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13h12"/><path d="M6 18h6"/><path d="M4 3v18l16-9v18"/></svg>',
            'programme' => '<svg ' . $attrs . '><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
            'session'   => '<svg ' . $attrs . '><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'semester'  => '<svg ' . $attrs . '><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M7 9h10M7 14h10"/></svg>',
            'level'     => '<svg ' . $attrs . '><path d="M3 20L10 13l4 4 7-7"/><polyline points="14 10 17 10 17 7"/></svg>',
            'mapping'   => '<svg ' . $attrs . '><path d="M2 12h20"/><circle cx="6" cy="12" r="2.5"/><circle cx="12" cy="12" r="2.5"/><circle cx="18" cy="12" r="2.5"/></svg>',
            'import'    => '<svg ' . $attrs . '><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>',
            'classschedule' => '<svg ' . $attrs . '><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><rect x="8" y="14" width="3" height="3" rx="0.5"/><rect x="13" y="14" width="3" height="3" rx="0.5"/><rect x="8" y="18" width="3" height="2" rx="0.5"/><rect x="13" y="18" width="3" height="2" rx="0.5"/></svg>',
            'attendanceaudit' => '<svg ' . $attrs . '><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 14h6"/><path d="M9 17h6"/><path d="M12 11h.01"/></svg>',
            'graduation'=> '<svg ' . $attrs . '><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>',
            'shield'    => '<svg ' . $attrs . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'calendar'  => '<svg ' . $attrs . '><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
            'clipboard' => '<svg ' . $attrs . '><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="M9 14l2 2 4-4"/></svg>',
            'userscheck'=> '<svg ' . $attrs . '><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><polyline points="16 11 18 13 22 9"/></svg>',
            'alert'     => '<svg ' . $attrs . '><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
            'course'    => '<svg ' . $attrs . '><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'chart'     => '<svg ' . $attrs . '><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg>',
        ];
    }
}
