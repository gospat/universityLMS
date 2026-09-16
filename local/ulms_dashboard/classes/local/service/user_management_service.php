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

require_once($CFG->dirroot . '/local/ulms_auth/lib.php');

require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->libdir . '/accesslib.php');

/**
 * Handles ULMS admin user-management workflows.
 */
class user_management_service {
    /** @var string */
    private const PROFILE_TABLE = 'local_ulms_user_profile';
    private const MANAGEMENT_LOG_TABLE = 'local_ulms_user_management_log';

    /** @var string */
    private const LOG_TABLE = 'local_ulms_user_management_log';

    /** @var int */
    private const DEFAULT_PER_PAGE = 25;

    /** @var user_provisioning_service */
    private user_provisioning_service $provisioningservice;

    /** @var \local_ulms_academics\local\service\academic_structure_service|null */
    private ?\local_ulms_academics\local\service\academic_structure_service $academicservice;

    /** @var \local_ulms_mail\local\service\resend_mail_service|null */
    private ?\local_ulms_mail\local\service\resend_mail_service $mailservice;

    /**
     * Constructor.
     *
     * @param user_provisioning_service|null $provisioningservice
     * @param \local_ulms_academics\local\service\academic_structure_service|null $academicservice
     * @param \local_ulms_mail\local\service\resend_mail_service|null $mailservice
     */
    public function __construct(
        ?user_provisioning_service $provisioningservice = null,
        ?\local_ulms_academics\local\service\academic_structure_service $academicservice = null,
        ?\local_ulms_mail\local\service\resend_mail_service $mailservice = null
    ) {
        $this->provisioningservice = $provisioningservice ?? new user_provisioning_service();
        $this->academicservice = $academicservice;
        $this->mailservice = $mailservice;
    }

    /**
     * Returns management role options for the current admin.
     *
     * @return array<string, string>
     */
    public function get_available_role_options(): array {
        $roles = $this->provisioningservice->get_available_target_roles();
        $labels = [
            'student' => get_string('usermanagementrolestudent', 'local_ulms_dashboard'),
            'lecturer' => get_string('usermanagementrolelecturer', 'local_ulms_dashboard'),
            'admin' => get_string('usermanagementroleadmin', 'local_ulms_dashboard'),
        ];
        if ($this->can_see_superadmin_tab()) {
            $labels['superadmin'] = get_string('usermanagementrolesuperadmin', 'local_ulms_dashboard');
            if (!array_key_exists('superadmin', $roles)) {
                $roles['superadmin'] = get_string('usermanagementrolesuperadmin', 'local_ulms_dashboard');
            }
        }
        foreach ($roles as $key => $label) {
            $roles[$key] = $labels[$key] ?? $label;
        }
        return $roles;
    }

    /**
     * Returns filterable status options.
     *
     * @param bool $includeall
     * @return array<string, string>
     */
    public function get_status_options(bool $includeall = true): array {
        $options = [
            'active' => get_string('usermanagementstatusactive', 'local_ulms_dashboard'),
            'suspended' => get_string('usermanagementstatussuspended', 'local_ulms_dashboard'),
            'deleted' => get_string('usermanagementstatusdeleted', 'local_ulms_dashboard'),
        ];

        if ($includeall) {
            return ['' => get_string('all')] + $options;
        }

        return $options;
    }

    /**
     * Returns page-size options.
     *
     * @return array<int, string>
     */
    public function get_per_page_options(): array {
        return [25 => '25', 50 => '50', 100 => '100'];
    }

    /**
     * Returns sort options.
     *
     * @return array<string, string>
     */
    public function get_sort_options(): array {
        return [
            'name' => get_string('name'),
            'username' => get_string('username'),
            'email' => get_string('email'),
            'role' => get_string('usermanagementrolelabel', 'local_ulms_dashboard'),
            'status' => get_string('usermanagementstatuslabel', 'local_ulms_dashboard'),
            'created' => get_string('usermanagementcreatedlabel', 'local_ulms_dashboard'),
            'lastlogin' => get_string('lastaccess'),
        ];
    }

    /**
     * Normalises raw filter input.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public function normalise_filters(array $raw): array {
        $perpageoptions = array_keys($this->get_per_page_options());
        $sortoptions = array_keys($this->get_sort_options());
        $statusoptions = array_keys($this->get_status_options());

        $filters = [
            'search' => trim((string)($raw['search'] ?? '')),
            'role' => trim((string)($raw['role'] ?? '')),
            'status' => trim((string)($raw['status'] ?? '')),
            'departmentid' => max(0, (int)($raw['departmentid'] ?? 0)),
            'level' => trim((string)($raw['level'] ?? '')),
            'sort' => trim((string)($raw['sort'] ?? 'name')),
            'dir' => strtoupper(trim((string)($raw['dir'] ?? 'ASC'))) === 'DESC' ? 'DESC' : 'ASC',
            'page' => max(0, (int)($raw['page'] ?? 0)),
            'perpage' => (int)($raw['perpage'] ?? self::DEFAULT_PER_PAGE),
        ];

        if (!array_key_exists($filters['role'], $this->get_available_role_options()) && $filters['role'] !== '') {
            $filters['role'] = '';
        }

        if (!in_array($filters['status'], $statusoptions, true)) {
            $filters['status'] = '';
        }

        if (!in_array($filters['sort'], $sortoptions, true)) {
            $filters['sort'] = 'name';
        }

        if (!in_array($filters['perpage'], $perpageoptions, true)) {
            $filters['perpage'] = self::DEFAULT_PER_PAGE;
        }

        if (!$this->department_exists($filters['departmentid'])) {
            $filters['departmentid'] = 0;
        }

        if ($filters['level'] !== '' && !in_array($filters['level'], array_keys($this->get_level_options()), true)) {
            $filters['level'] = '';
        }

        if (!$this->profile_table_exists()) {
            $filters['departmentid'] = 0;
            $filters['level'] = '';
        }

        return $filters;
    }

    /**
     * Returns department filter options.
     *
     * @return array<int, string>
     */
    public function get_department_options(): array {
        return $this->get_department_option_labels(0, true);
    }

    /**
     * Returns level filter options from the master levels table, merged with any legacy values
     * already stored in the user profile table for continuity.
     *
     * @return array<string, string>
     */
    public function get_level_options(): array {
        global $DB;

        $levelsexist = $DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'));
        $masteroptions = [];
        if ($levelsexist) {
            $records = $DB->get_records_sql(
                "SELECT id, code, name
                   FROM {local_ulms_levels}
                  WHERE status = 'active'
               ORDER BY sortorder ASC, code ASC"
            );
            foreach ($records as $record) {
                $code = trim((string)($record->code ?? ''));
                if ($code !== '') {
                    $name = trim((string)($record->name ?? ''));
                    $masteroptions[$code] = $name !== '' ? $name : $code;
                }
            }
        }

        $legacy = [];
        if ($this->profile_table_exists()) {
            $records = $DB->get_records_sql(
                "SELECT DISTINCT studylevel
                   FROM {" . self::PROFILE_TABLE . "}
                  WHERE studylevel <> ''
               ORDER BY studylevel ASC"
            );
            foreach ($records as $record) {
                $level = trim((string)($record->studylevel ?? ''));
                if ($level !== '' && !isset($masteroptions[$level])) {
                    $legacy[$level] = $level;
                }
            }
        }

        $options = $masteroptions;
        foreach ($legacy as $code => $label) {
            $options[$code] = $label;
        }

        return $options;
    }

    /**
     * Resolves a level code to a master level record or returns null when absent / not active.
     *
     * @param string $code
     * @return \stdClass|null
     */
    public function get_level_by_code(string $code): ?\stdClass {
        global $DB;

        $code = trim($code);
        if ($code === '' || !$DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))) {
            return null;
        }

        $record = $DB->get_record('local_ulms_levels', ['code' => $code, 'status' => 'active'], '*', IGNORE_MISSING);

        return $record ?: null;
    }

    /**
     * Returns a level label by code, with graceful fallback to the raw code itself.
     *
     * @param string $code
     * @return string
     */
    public function get_level_label(string $code): string {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        $record = $this->get_level_by_code($code);
        if ($record && trim((string)($record->name ?? '')) !== '') {
            return trim((string)$record->name);
        }

        return $code;
    }

    /**
     * Returns academic form options.
     *
     * @param int $facultyid
     * @param int $departmentid
     * @return array<string, array>
     */
    public function get_academic_form_options(int $facultyid = 0, int $departmentid = 0): array {
        $faculties = [0 => get_string('usermanagementnotset', 'local_ulms_dashboard')];
        $departments = [0 => get_string('usermanagementnotset', 'local_ulms_dashboard')];
        $programmes = [0 => get_string('usermanagementnotset', 'local_ulms_dashboard')];

        if ($this->get_academic_service() !== null) {
            $facultyoptions = [0 => get_string('usermanagementnotset', 'local_ulms_dashboard')];
            foreach ($this->get_academic_service()->get_records_for_entity('faculties') as $faculty) {
                $facultyoptions[(int)$faculty->id] = (string)$faculty->name;
            }
            $faculties = $facultyoptions;
            $departments = $this->get_department_option_labels($facultyid, false);
            $programmes += $this->get_academic_service()->get_programme_options_for_hierarchy($facultyid, $departmentid);
        }

        return [
            'faculties' => $faculties,
            'departments' => $departments,
            'programmes' => $programmes,
        ];
    }

    /**
     * Returns page summary stats.
     *
     * @return array<int, array<string, string>>
     */
    public function get_summary_cards(): array {
        global $DB;

        $deletedcount = (int)$DB->count_records('user', ['deleted' => 1]);
        $activecount = (int)$DB->count_records_select('user', "deleted = 0 AND suspended = 0 AND username <> :guest", ['guest' => 'guest']);
        $suspendedcount = (int)$DB->count_records_select('user', "deleted = 0 AND suspended = 1 AND username <> :guest", ['guest' => 'guest']);

        return [
            [
                'label' => get_string('totaluserssummary', 'local_ulms_dashboard'),
                'value' => (string)$DB->count_records_select('user', "username <> :guest", ['guest' => 'guest']),
                'description' => get_string('usermanagementsummarytotaldesc', 'local_ulms_dashboard'),
            ],
            [
                'label' => get_string('usermanagementstatusactive', 'local_ulms_dashboard'),
                'value' => (string)$activecount,
                'description' => get_string('usermanagementsummaryactivedesc', 'local_ulms_dashboard'),
            ],
            [
                'label' => get_string('usermanagementstatussuspended', 'local_ulms_dashboard'),
                'value' => (string)$suspendedcount,
                'description' => get_string('usermanagementsummarysuspendeddesc', 'local_ulms_dashboard'),
            ],
            [
                'label' => get_string('usermanagementstatusdeleted', 'local_ulms_dashboard'),
                'value' => (string)$deletedcount,
                'description' => get_string('usermanagementsummarydeleteddesc', 'local_ulms_dashboard'),
            ],
        ];
    }

    /**
     * Returns whether the current viewer may see the Super Admins exclusive tab.
     *
     * Server-side only: non-siteadmins must never get Super Admin metadata.
     *
     * @return bool
     */
    public function can_see_superadmin_tab(): bool {
        global $USER;
        return isloggedin() && !isguestuser() && is_siteadmin($USER);
    }

    /**
     * Returns role-separated counts for summary cards (students, lecturers, admins, superadmins).
     *
     * @return array<string, array{label: string, value: string, description: string, role: string}>
     */
    public function get_role_summary_cards(): array {
        $counts = $this->get_role_counts();
        $cards = [
            'student' => [
                'label' => get_string('usermanagementtabstudents', 'local_ulms_dashboard'),
                'value' => (string)($counts['student'] ?? 0),
                'description' => get_string('usermanagementcardstudentsdesc', 'local_ulms_dashboard'),
                'role' => 'student',
            ],
            'lecturer' => [
                'label' => get_string('usermanagementtablecturers', 'local_ulms_dashboard'),
                'value' => (string)($counts['lecturer'] ?? 0),
                'description' => get_string('usermanagementcardlecturersdesc', 'local_ulms_dashboard'),
                'role' => 'lecturer',
            ],
            'admin' => [
                'label' => get_string('usermanagementtabadmins', 'local_ulms_dashboard'),
                'value' => (string)($counts['admin'] ?? 0),
                'description' => get_string('usermanagementcardadminsdesc', 'local_ulms_dashboard'),
                'role' => 'admin',
            ],
        ];
        if ($this->can_see_superadmin_tab()) {
            $cards['superadmin'] = [
                'label' => get_string('usermanagementtabsuperadmins', 'local_ulms_dashboard'),
                'value' => (string)($counts['superadmin'] ?? 0),
                'description' => get_string('usermanagementcardsuperadminsdesc', 'local_ulms_dashboard'),
                'role' => 'superadmin',
            ];
        }
        return $cards;
    }

    /**
     * Computes counts per distinct ULMS role key.
     *
     * @return array<string, int>
     */
    public function get_role_counts(): array {
        global $DB;
        $counts = ['student' => 0, 'lecturer' => 0, 'admin' => 0, 'superadmin' => 0];
        [$roleexpr, $roleparams] = $this->get_role_expression('u', 'cntrole');
        $sql = "SELECT {$roleexpr} AS rk, COUNT(DISTINCT u.id) AS c
                  FROM {user} u
                 WHERE u.username <> :cntguest AND u.deleted = 0
              GROUP BY {$roleexpr}";
        $params = array_merge(['cntguest' => 'guest'], $roleparams);
        try {
            $records = $DB->get_records_sql($sql, $params);
        } catch (\Throwable) {
            return $counts;
        }
        foreach ($records as $rec) {
            $key = (string)($rec->rk ?? 'other');
            if (array_key_exists($key, $counts)) {
                $counts[$key] = (int)($rec->c ?? 0);
            }
        }
        return $counts;
    }

    /**
     * Returns true when the supplied action is permitted on the target user from self-protection rules.
     *
     * @param int $targetuserid
     * @return bool
     */
    public function is_self_target(int $targetuserid): bool {
        global $USER;
        return isloggedin() && (int)$USER->id === $targetuserid;
    }

    /**
     * Throws an exception when the caller tries to mutate their own admin-level account.
     *
     * @param int $targetuserid
     * @param string $verb
     * @return void
     */
    public function assert_no_self_mutation(int $targetuserid, string $verb): void {
        if ($this->is_self_target($targetuserid)) {
            throw new \moodle_exception('usermanagementerrorself' . $verb, 'local_ulms_dashboard');
        }
    }

    /**
     * Returns whether the caller may impersonate the target user (Super Admin exclusive).
     *
     * @param array<string, mixed> $user
     * @return bool
     */
    public function can_impersonate_user(array $user): bool {
        if (!$this->can_see_superadmin_tab()) {
            return false;
        }
        if (!empty($user['deleted'])) {
            return false;
        }
        if ($this->is_self_target((int)$user['id'])) {
            return false;
        }
        if (!function_exists('loginas_get_continue_url_param') && !method_exists(\core\session\manager::class, 'loginas')) {
            return false;
        }
        return true;
    }

    /**
     * Builds the Moodle-supported login-as URL for a target user.
     *
     * @param int $targetuserid
     * @return string|null
     */
    public function get_impersonate_url(int $targetuserid): ?string {
        if (!$this->can_see_superadmin_tab() || $this->is_self_target($targetuserid)) {
            return null;
        }
        try {
            $context = \context_user::instance($targetuserid, IGNORE_MISSING);
            if ($context === false) {
                return null;
            }
            return (new \moodle_url('/course/loginas.php', [
                'id' => SITEID,
                'user' => $targetuserid,
                'sesskey' => sesskey(),
            ]))->out(false);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Performs a bulk action on a list of user ids, returns per-id result summary.
     *
     * @param string $action
     * @param array<int, int> $userids
     * @return array{success: int, failed: int, messages: array<int, string>}
     */
    public function execute_bulk_action(string $action, array $userids): array {
        $result = ['success' => 0, 'failed' => 0, 'messages' => []];
        $allowed = ['bulksuspend', 'bulkunsuspend', 'bulkwelcome', 'bulkdelete'];
        if (!in_array($action, $allowed, true)) {
            return $result;
        }
        foreach ($userids as $uid) {
            $uid = (int)$uid;
            if ($uid <= 0 || $this->is_self_target($uid)) {
                $result['failed']++;
                $result['messages'][$uid] = get_string('usermanagementbulkskippedself', 'local_ulms_dashboard');
                continue;
            }
            try {
                $outcome = match ($action) {
                    'bulksuspend' => $this->suspend_user($uid),
                    'bulkunsuspend' => $this->unsuspend_user($uid),
                    'bulkwelcome' => $this->resend_welcome_email($uid),
                    'bulkdelete' => $this->delete_user_account($uid),
                    default => null,
                };
                if ($outcome !== null && !empty($outcome['success'])) {
                    $result['success']++;
                } else {
                    $result['failed']++;
                    $result['messages'][$uid] = (string)($outcome['message'] ?? get_string('unknownerror'));
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                $result['messages'][$uid] = $e->getMessage();
            }
        }
        return $result;
    }

    /**
     * Looks up which admin provisioned each user via the provisioning log (created_by column).
     *
     * @param array<int, int> $userids
     * @return array<int, string>
     */
    public function get_created_by_for_users(array $userids): array {
        global $DB;
        $map = [];
        if (empty($userids)) {
            return $map;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cbuid');
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ulms_user_provisioning_log'))) {
            return $map;
        }
        $sql = "SELECT l.createduserid, l.actorid, l.createmode, l.identifier,
                       u.firstname, u.lastname, u.username
                  FROM {local_ulms_user_provisioning_log} l
             LEFT JOIN {user} u ON u.id = l.actorid AND u.deleted = 0
                 WHERE l.createduserid {$insql}
              ORDER BY l.id DESC";
        $records = $DB->get_records_sql($sql, $inparams);
        $seen = [];
        foreach ($records as $rec) {
            $uid = (int)$rec->createduserid;
            if (isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $name = trim(trim((string)($rec->firstname ?? '') . ' ' . (string)($rec->lastname ?? '')));
            if ($name === '' && !empty($rec->username)) {
                $name = (string)$rec->username;
            }
            if ($name === '') {
                $mode = (string)($rec->createmode ?? '');
                $ident = (string)($rec->identifier ?? '');
                if ($ident !== '') {
                    $name = rtrim("{$ident} (" . trim($mode) . ")", " ()");
                } elseif ($mode !== '') {
                    $name = $mode;
                } else {
                    $name = get_string('unknown');
                }
            }
            $map[$uid] = $name;
        }
        return $map;
    }

    /**
     * Returns a CSV export payload (header rows + data rows) for a specific role.
     *
     * @param string $role
     * @param array<string, mixed> $filters
     * @return array{headers: array<int, string>, rows: array<int, array<int, string>>}
     */
    public function get_role_export_payload(string $role, array $filters): array {
        $filters = $this->normalise_filters($filters);
        $filters['role'] = $role;
        $filters['page'] = 0;
        $filters['perpage'] = 10000;
        $listing = $this->get_user_listing($filters);
        $heads = [
            get_string('name'),
            get_string('username'),
            get_string('email'),
            get_string('usermanagementidnumberlabel', 'local_ulms_dashboard'),
            get_string('usermanagementrolelabel', 'local_ulms_dashboard'),
            get_string('usermanagementstatuslabel', 'local_ulms_dashboard'),
            get_string('usermanagementcreatedlabel', 'local_ulms_dashboard'),
            get_string('lastaccess'),
        ];
        $roleheads = [
            'student' => [
                get_string('programmesummary', 'local_ulms_dashboard'),
                get_string('assigneddepartmentlabel', 'local_ulms_dashboard'),
                get_string('assignedfacultylabel', 'local_ulms_dashboard'),
                get_string('usermanagementlevellabel', 'local_ulms_dashboard'),
            ],
            'lecturer' => [
                get_string('usermanagementstaffidlabel', 'local_ulms_dashboard'),
                get_string('assigneddepartmentlabel', 'local_ulms_dashboard'),
                get_string('assignedfacultylabel', 'local_ulms_dashboard'),
            ],
            'admin' => [
                get_string('usermanagementstaffidlabel', 'local_ulms_dashboard'),
            ],
            'superadmin' => [],
        ];
        $extraheads = $roleheads[$role] ?? [];
        $headers = array_merge($heads, $extraheads);
        $rows = [];
        foreach ($listing['rows'] as $row) {
            $userid = (int)$row['id'];
            try {
                $detail = $this->get_user_details($userid);
            } catch (\Throwable) {
                $detail = $row;
            }
            $base = [
                $detail['fullname'] ?? ($row['firstname'] . ' ' . $row['lastname']),
                $detail['username'] ?? $row['username'],
                $detail['email'] ?? $row['email'],
                $detail['idnumber'] ?? $row['idnumber'],
                $detail['rolelabel'] ?? $row['rolelabel'],
                $detail['statuslabel'] ?? $row['statuslabel'],
                $detail['timecreatedformatted'] ?? $row['timecreatedformatted'],
                $detail['lastaccessformatted'] ?? $row['lastaccessformatted'],
            ];
            $extradata = [];
            if ($role === 'student') {
                $extradata = [
                    (string)($detail['programmename'] ?? ''),
                    (string)($detail['departmentname'] ?? ''),
                    (string)($detail['facultyname'] ?? ''),
                    (string)($detail['studylevel'] ?? ''),
                ];
            } elseif ($role === 'lecturer') {
                $extradata = [
                    (string)($detail['staffid'] ?? ''),
                    (string)($detail['departmentname'] ?? ''),
                    (string)($detail['facultyname'] ?? ''),
                ];
            } elseif ($role === 'admin') {
                $extradata = [
                    (string)($detail['staffid'] ?? ''),
                ];
            }
            $rows[] = array_merge($base, $extradata);
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Ensures at least one active site-admin remains after a demote/suspend/delete action.
     *
     * @param int $targetuserid
     * @param string $verb one of suspend|delete|demote (reserved for future granular messaging)
     * @return void
     */
    public function assert_last_superadmin_protection(int $targetuserid, string $verb): void {
        $verb;
        if (!is_siteadmin($targetuserid)) {
            return;
        }
        $remaining = $this->count_active_superadmins_excluding($targetuserid);
        if ($remaining <= 0) {
            throw new \moodle_exception('usermanagementerrorlastsuperadmin', 'local_ulms_dashboard');
        }
    }

    /**
     * Counts active non-deleted non-suspended siteadmins excluding a specific user id.
     *
     * @param int $excludeuserid
     * @return int
     */
    public function count_active_superadmins_excluding(int $excludeuserid): int {
        global $DB;
        $ids = $this->get_site_admin_ids();
        if ($ids === []) {
            return 0;
        }
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id !== $excludeuserid));
        if ($ids === []) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'lsa');
        return (int)$DB->count_records_sql(
            "SELECT COUNT(1) FROM {user} WHERE id {$insql} AND deleted = 0 AND suspended = 0 AND username <> :guest",
            array_merge($inparams, ['guest' => 'guest'])
        );
    }

    /**
     * Returns paginated user rows and totals for the management page.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function get_user_listing(array $filters): array {
        global $DB;

        $filters = $this->normalise_filters($filters);
        [$where, $whereparams] = $this->build_user_filter_sql($filters, 'u', 'listing');
        [$roleexpr, $roleparams] = $this->get_role_expression('u', 'listingrole');
        $selectparams = array_merge($whereparams, $roleparams);
        $hasprofiletable = $this->profile_table_exists();
        $profilejoinsql = $hasprofiletable
            ? "LEFT JOIN {" . self::PROFILE_TABLE . "} p ON p.userid = u.id
             LEFT JOIN {local_ulms_departments} d ON d.id = p.departmentid"
            : '';
        $profilefieldssql = $hasprofiletable
            ? "p.staffid, p.studylevel, p.departmentid,
                       d.name AS departmentname,"
            : "'' AS staffid, '' AS studylevel, 0 AS departmentid,
                       '' AS departmentname,";

        $sortsql = $this->get_sort_sql($filters['sort'], $filters['dir']);
        $offset = $filters['page'] * $filters['perpage'];

        $sql = "SELECT u.id, u.firstname, u.middlename, u.lastname, u.username, u.email, u.idnumber,
                       u.deleted, u.suspended, u.timecreated, u.timemodified, u.lastaccess, u.auth,
                       {$profilefieldssql}
                       {$roleexpr} AS rolekey
                  FROM {user} u
             {$profilejoinsql}
                 WHERE {$where}
              ORDER BY {$sortsql}";

        $records = $DB->get_records_sql($sql, $selectparams, $offset, $filters['perpage']);
        $countsql = "SELECT COUNT(1)
                       FROM {user} u
                  " . ($hasprofiletable ? "LEFT JOIN {" . self::PROFILE_TABLE . "} p ON p.userid = u.id" : '') . "
                      WHERE {$where}";
        $total = (int)$DB->count_records_sql($countsql, $whereparams);

        $rows = [];
        foreach ($records as $record) {
            $user = $this->map_list_record($record);
            $user['actions'] = $this->get_user_actions($user);
            $rows[] = $user;
        }

        return [
            'filters' => $filters,
            'total' => $total,
            'rows' => $rows,
            'start' => $total > 0 ? $offset + 1 : 0,
            'end' => $total > 0 ? min($offset + count($rows), $total) : 0,
            'offset' => $offset,
        ];
    }

    /**
     * Returns a full user profile for detail/edit views.
     *
     * @param int $userid
     * @return array<string, mixed>
     */
    public function get_user_details(int $userid): array {
        global $DB;

        if ($userid <= 0) {
            throw new \moodle_exception('invaliduser');
        }

        [$roleexpr, $params] = $this->get_role_expression('u', 'detailrole');
        $params['userid'] = $userid;
        $hasprofiletable = $this->profile_table_exists();
        $profilejoinsql = $hasprofiletable
            ? "LEFT JOIN {" . self::PROFILE_TABLE . "} p ON p.userid = u.id
             LEFT JOIN {local_ulms_faculties} f ON f.id = p.facultyid
             LEFT JOIN {local_ulms_departments} d ON d.id = p.departmentid
             LEFT JOIN {local_ulms_programmes} pr ON pr.id = p.programmeid"
            : '';
        $profilefieldssql = $hasprofiletable
            ? "p.staffid, p.studylevel, p.facultyid, p.departmentid, p.programmeid,
                       f.name AS facultyname, d.name AS departmentname, pr.name AS programmename,"
            : "'' AS staffid, '' AS studylevel, 0 AS facultyid, 0 AS departmentid, 0 AS programmeid,
                       '' AS facultyname, '' AS departmentname, '' AS programmename,";

        $sql = "SELECT u.id, u.firstname, u.middlename, u.lastname, u.username, u.email, u.idnumber,
                       u.deleted, u.suspended, u.timecreated, u.timemodified, u.lastaccess, u.auth,
                       u.institution, u.department,
                       {$profilefieldssql}
                       {$roleexpr} AS rolekey
                  FROM {user} u
             {$profilejoinsql}
                 WHERE u.id = :userid";

        $record = $DB->get_record_sql($sql, $params, MUST_EXIST);
        $user = $this->map_list_record($record);
        $user['middlename'] = (string)($record->middlename ?? '');
        $user['facultyid'] = (int)($record->facultyid ?? 0);
        $user['facultyname'] = (string)($record->facultyname ?? '');
        $user['departmentid'] = (int)($record->departmentid ?? 0);
        $user['departmentname'] = (string)($record->departmentname ?? '');
        $user['programmeid'] = (int)($record->programmeid ?? 0);
        $user['programmename'] = (string)($record->programmename ?? '');
        $user['studylevel'] = (string)($record->studylevel ?? '');
        $user['staffid'] = (string)($record->staffid ?? '');
        $user['auth'] = (string)($record->auth ?? '');
        $user['institution'] = (string)($record->institution ?? '');
        $user['departmenttext'] = (string)($record->department ?? '');
        $user['roleshortnames'] = $this->get_user_role_shortnames($userid);
        $user['activity'] = $this->get_recent_user_activity($userid, 20);
        $user['actions'] = $this->get_user_actions($user);

        return $user;
    }

    /**
     * Returns default form values for add/edit pages.
     *
     * @param array<string, mixed>|null $user
     * @return array<string, mixed>
     */
    public function get_form_defaults(?array $user = null): array {
        return [
            'firstname' => (string)($user['firstname'] ?? ''),
            'middlename' => (string)($user['middlename'] ?? ''),
            'lastname' => (string)($user['lastname'] ?? ''),
            'username' => (string)($user['username'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'idnumber' => (string)($user['idnumber'] ?? ''),
            'role' => (string)($user['rolekey'] ?? 'student'),
            'status' => ($user['deleted'] ?? false)
                ? 'deleted'
                : (($user['suspended'] ?? false) ? 'suspended' : 'active'),
            'facultyid' => (int)($user['facultyid'] ?? 0),
            'departmentid' => (int)($user['departmentid'] ?? 0),
            'programmeid' => (int)($user['programmeid'] ?? 0),
            'studylevel' => (string)($user['studylevel'] ?? ''),
            'staffid' => (string)($user['staffid'] ?? ''),
            'password' => '',
            'confirmpassword' => '',
        ];
    }

    /**
     * Validates add/edit user form input.
     *
     * @param array<string, mixed> $data
     * @param int $userid
     * @param bool $iscreate
     * @return array{valid: bool, errors: array<string, string>, cleaned: array<string, mixed>}
     */
    public function validate_user_form_data(array $data, int $userid = 0, bool $iscreate = true): array {
        $errors = [];
        $current = $userid > 0 ? $this->get_user_details($userid) : null;
        $availableroles = $this->get_available_role_options();

        $cleaned = [
            'firstname' => trim((string)($data['firstname'] ?? '')),
            'middlename' => trim((string)($data['middlename'] ?? '')),
            'lastname' => trim((string)($data['lastname'] ?? '')),
            'username' => trim((string)($data['username'] ?? '')),
            'email' => trim((string)($data['email'] ?? '')),
            'idnumber' => trim((string)($data['idnumber'] ?? '')),
            'role' => trim((string)($data['role'] ?? ($current['rolekey'] ?? 'student'))),
            'status' => trim((string)($data['status'] ?? 'active')),
            'facultyid' => max(0, (int)($data['facultyid'] ?? 0)),
            'departmentid' => max(0, (int)($data['departmentid'] ?? 0)),
            'programmeid' => max(0, (int)($data['programmeid'] ?? 0)),
            'studylevel' => trim((string)($data['studylevel'] ?? '')),
            'staffid' => trim((string)($data['staffid'] ?? '')),
            'password' => (string)($data['password'] ?? ''),
            'confirmpassword' => (string)($data['confirmpassword'] ?? ''),
        ];

        if ($cleaned['firstname'] === '') {
            $errors['firstname'] = get_string('required');
        }

        if ($cleaned['lastname'] === '') {
            $errors['lastname'] = get_string('required');
        }

        if ($cleaned['email'] === '') {
            $errors['email'] = get_string('required');
        } else if (!validate_email($cleaned['email'])) {
            $errors['email'] = get_string('provisioningerrorinvalidemail', 'local_ulms_dashboard', $cleaned['email']);
        } else if ($this->email_exists($cleaned['email'], $userid)) {
            $errors['email'] = get_string('provisioningerrorexistingemail', 'local_ulms_dashboard', $cleaned['email']);
        }

        if ($iscreate && $cleaned['username'] === '') {
            // Leave blank to reuse the provisioning service auto-generation logic.
        } else if ($cleaned['username'] === '') {
            $cleaned['username'] = (string)($current['username'] ?? '');
        } else {
            $username = \core_text::strtolower($cleaned['username']);
            if ($username !== $cleaned['username'] || $username !== \core_user::clean_field($username, 'username')) {
                $errors['username'] = get_string('provisioningerrorinvalidusername', 'local_ulms_dashboard', $cleaned['username']);
            } else if ($this->username_exists($username, $userid)) {
                $errors['username'] = get_string('provisioningerrorexistingusername', 'local_ulms_dashboard', $username);
            } else {
                $cleaned['username'] = $username;
            }
        }

        if ($cleaned['idnumber'] !== '' && $this->idnumber_exists($cleaned['idnumber'], $userid)) {
            $errors['idnumber'] = get_string('provisioningerrorexistingidnumber', 'local_ulms_dashboard', $cleaned['idnumber']);
        }

        if (!array_key_exists($cleaned['role'], $availableroles)) {
            $errors['role'] = get_string('invaliddata');
        }

        if (!array_key_exists($cleaned['status'], $this->get_status_options(false))) {
            $errors['status'] = get_string('invaliddata');
        }

        [$academicerrors, $academiccleaned, $academicwarning] = $this->validate_academic_metadata($cleaned);
        $errors = array_merge($errors, $academicerrors);
        $cleaned = array_merge($cleaned, $academiccleaned);

        // Student programme mandate: role=student or role-switching-to-student MUST have an active programme assigned.
        if ($this->is_student_role((string)$cleaned['role']) && (int)($cleaned['programmeid'] ?? 0) <= 0) {
            $errors['programmeid'] = get_string('usermanagementprogrammerequired', 'local_ulms_dashboard');
        }

        if ($iscreate && $cleaned['password'] !== '') {
            if ($cleaned['confirmpassword'] === '') {
                $errors['confirmpassword'] = get_string('required');
            } else if ($cleaned['password'] !== $cleaned['confirmpassword']) {
                $errors['confirmpassword'] = get_string('usermanagementerrorpasswordmismatch', 'local_ulms_dashboard');
            } else if (!check_password_policy($cleaned['password'], $errmsg, (object)[
                'username' => $cleaned['username'] !== '' ? $cleaned['username'] : 'new.user',
                'firstname' => $cleaned['firstname'],
                'lastname' => $cleaned['lastname'],
                'email' => $cleaned['email'],
            ])) {
                $errors['password'] = $errmsg;
            }
        }

        if (!$iscreate) {
            $cleaned['password'] = '';
            $cleaned['confirmpassword'] = '';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'cleaned' => $cleaned,
            'warning' => (string)$academicwarning,
        ];
    }

    /**
     * Creates a new user through the existing provisioning service and saves ULMS metadata.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create_user(array $data): array {
        global $DB;

        $this->require_create_capability();
        $validation = $this->validate_user_form_data($data, 0, true);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'warning' => false,
                'message' => get_string('usermanagementcreatefixerrors', 'local_ulms_dashboard'),
                'errors' => $validation['errors'],
                'values' => $validation['cleaned'],
            ];
        }

        $cleaned = $validation['cleaned'];
        [$institution, $department] = $this->get_core_academic_labels($cleaned['facultyid'], $cleaned['departmentid']);
        $provisioningdata = [
            'firstname' => $cleaned['firstname'],
            'middlename' => $cleaned['middlename'],
            'lastname' => $cleaned['lastname'],
            'username' => $cleaned['username'],
            'email' => $cleaned['email'],
            'idnumber' => $cleaned['idnumber'],
            'password' => $cleaned['password'],
            'institution' => $institution,
            'department' => $department,
        ];

        try {
            $result = $this->provisioningservice->create_single_user($cleaned['role'], $provisioningdata);
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_management_service::write_audit_log', []); }
            return [
                'success' => false,
                'warning' => false,
                'message' => get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard'),
                'errors' => ['general' => get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard')],
                'values' => $cleaned,
            ];
        }

        if (empty($result['success'])) {
            return [
                'success' => false,
                'warning' => false,
                'message' => (string)($result['message'] ?? get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard')),
                'errors' => ['general' => (string)($result['message'] ?? get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard'))],
                'values' => $cleaned,
            ];
        }

        $user = $DB->get_record('user', ['username' => $result['username']], '*', MUST_EXIST);
        $this->save_profile_record((int)$user->id, $cleaned);

        if ($cleaned['status'] === 'suspended') {
            user_update_user((object)[
                'id' => $user->id,
                'suspended' => 1,
            ], false, true);
        }

        $this->log_user_management_event(
            (int)$user->id,
            'USER_CREATED',
            'success',
            get_string('usermanagementcreateaudit', 'local_ulms_dashboard', $this->format_fullname($user)),
            [
                'role' => $cleaned['role'],
                'email' => $user->email,
                'status' => $cleaned['status'],
            ]
        );

        $this->log_user_management_event(
            (int)$user->id,
            !empty($result['emailsent']) ? 'WELCOME_EMAIL_SENT' : 'WELCOME_EMAIL_FAILED',
            !empty($result['emailsent']) ? 'success' : 'warning',
            (string)$result['message'],
            [
                'role' => $cleaned['role'],
            ]
        );

        if ($cleaned['status'] === 'suspended') {
            $this->log_user_management_event(
                (int)$user->id,
                'USER_SUSPENDED',
                'success',
                get_string('usermanagementsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($user)),
                ['reason' => 'created_suspended']
            );
        }

        return [
            'success' => true,
            'warning' => empty($result['emailsent']) || !empty($validation['warning']),
            'message' => (string)$result['message'] . (!empty($validation['warning']) ? ' ' . $validation['warning'] : ''),
            'userid' => (int)$user->id,
        ];
    }

    /**
     * Updates a managed user and related ULMS metadata.
     *
     * @param int $userid
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update_user(int $userid, array $data): array {
        $this->require_update_capability();
        $current = $this->get_user_details($userid);
        if (!empty($current['deleted'])) {
            throw new \moodle_exception('invaliduser');
        }

        $validation = $this->validate_user_form_data($data, $userid, false);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'warning' => false,
                'message' => get_string('usermanagementupdatefixerrors', 'local_ulms_dashboard'),
                'errors' => $validation['errors'],
                'values' => $validation['cleaned'],
            ];
        }

        $cleaned = $validation['cleaned'];
        $this->assert_admin_protection($current, $cleaned['role'], $cleaned['status']);
        [$institution, $department] = $this->get_core_academic_labels($cleaned['facultyid'], $cleaned['departmentid']);

        $payload = (object)[
            'id' => $userid,
            'firstname' => $cleaned['firstname'],
            'middlename' => $cleaned['middlename'],
            'lastname' => $cleaned['lastname'],
            'username' => $cleaned['username'],
            'email' => $cleaned['email'],
            'idnumber' => $cleaned['idnumber'],
            'institution' => $institution,
            'department' => $department,
            'suspended' => $cleaned['status'] === 'suspended' ? 1 : 0,
        ];

        user_update_user($payload, false, true);
        $this->save_profile_record($userid, $cleaned);

        $rolekey = (string)($cleaned['role'] ?? '');
        $progifforenrol = max(0, (int)($cleaned['programmeid'] ?? 0));
        if ($rolekey === 'student' && $progifforenrol > 0) {
            try {
                if (function_exists('local_ulms_academics_enrol_user_into_programme_courses')) {
                    local_ulms_academics_enrol_user_into_programme_courses($userid, $progifforenrol);
                }
            } catch (\Throwable $enrolex) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($enrolex, 'user_management_service::update_user::programme_enrol', [
                        'userid' => $userid,
                        'programmeid' => $progifforenrol,
                    ]);
                }
            }
        }

        if ($current['rolekey'] !== $cleaned['role']) {
            $this->apply_role_change($userid, $cleaned['role'], $current['rolekey']);
        }

        $updated = $this->get_user_details($userid);
        $this->log_user_management_event(
            $userid,
            'USER_UPDATED',
            'success',
            get_string('usermanagementupdatesuccess', 'local_ulms_dashboard', $this->format_fullname($updated)),
            [
                'previousrole' => $current['rolekey'],
                'newrole' => $updated['rolekey'],
                'status' => $cleaned['status'],
            ]
        );

        if ((int)$current['suspended'] !== (int)$updated['suspended']) {
            $action = !empty($updated['suspended']) ? 'USER_SUSPENDED' : 'USER_UNSUSPENDED';
            $message = !empty($updated['suspended'])
                ? get_string('usermanagementsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($updated))
                : get_string('usermanagementunsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($updated));
            $this->log_user_management_event($userid, $action, 'success', $message);
        }

        $basemessage = get_string('usermanagementupdatesuccess', 'local_ulms_dashboard', $this->format_fullname($updated));
        if (!empty($validation['warning'])) {
            $basemessage .= ' ' . $validation['warning'];
        }
        return [
            'success' => true,
            'warning' => !empty($validation['warning']),
            'message' => $basemessage,
            'userid' => $userid,
        ];
    }

    /**
     * Suspends a user account.
     *
     * @param int $userid
     * @return array<string, mixed>
     */
    public function suspend_user(int $userid): array {
        $this->require_update_capability();
        $this->assert_no_self_mutation($userid, 'suspend');
        $this->assert_last_superadmin_protection($userid, 'suspend');
        $user = $this->get_user_details($userid);
        $this->assert_admin_protection($user, null, 'suspended');
        if (!empty($user['deleted'])) {
            throw new \moodle_exception('invaliduser');
        }

        user_update_user((object)[
            'id' => $userid,
            'suspended' => 1,
        ], false, true);

        $this->log_user_management_event(
            $userid,
            'USER_SUSPENDED',
            'success',
            get_string('usermanagementsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($user))
        );

        return [
            'success' => true,
            'warning' => false,
            'message' => get_string('usermanagementsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($user)),
        ];
    }

    /**
     * Unsuspends a user account.
     *
     * @param int $userid
     * @return array<string, mixed>
     */
    public function unsuspend_user(int $userid): array {
        $this->require_update_capability();
        $this->assert_no_self_mutation($userid, 'unsuspend');
        $user = $this->get_user_details($userid);
        if (!empty($user['deleted'])) {
            throw new \moodle_exception('invaliduser');
        }

        user_update_user((object)[
            'id' => $userid,
            'suspended' => 0,
        ], false, true);

        $this->log_user_management_event(
            $userid,
            'USER_UNSUSPENDED',
            'success',
            get_string('usermanagementunsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($user))
        );

        return [
            'success' => true,
            'warning' => false,
            'message' => get_string('usermanagementunsuspendsuccess', 'local_ulms_dashboard', $this->format_fullname($user)),
        ];
    }

    /**
     * Deletes a user account through Moodle's supported API.
     *
     * @param int $userid
     * @return array<string, mixed>
     */
    public function delete_user_account(int $userid): array {
        global $DB;

        $this->require_delete_capability();
        $this->assert_no_self_mutation($userid, 'delete');
        $this->assert_last_superadmin_protection($userid, 'delete');
        $user = $this->get_user_details($userid);
        $this->assert_admin_protection($user, null, 'deleted');

        $record = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        user_delete_user($record);

        $this->log_user_management_event(
            $userid,
            'USER_DELETED',
            'success',
            get_string('usermanagementdeletesuccess', 'local_ulms_dashboard', $this->format_fullname($user))
        );

        return [
            'success' => true,
            'warning' => false,
            'message' => get_string('usermanagementdeletesuccess', 'local_ulms_dashboard', $this->format_fullname($user)),
        ];
    }

    /**
     * Sends a secure password reset email.
     *
     * @param int $userid
     * @return array<string, mixed>
     */
    public function send_password_reset_email(int $userid): array {
        $this->require_update_capability();
        $user = $this->get_user_details($userid);
        if (!empty($user['deleted'])) {
            throw new \moodle_exception('invaliduser');
        }

        $result = $this->send_access_email($user, 'passwordreset');
        $action = !empty($result['success']) ? 'PASSWORD_RESET_REQUESTED' : 'PASSWORD_RESET_FAILED';
        $status = !empty($result['success']) ? 'success' : 'warning';
        $this->log_user_management_event($userid, $action, $status, $result['message']);

        return $result;
    }

    /**
     * Sends a welcome or re-onboarding email.
     *
     * @param int $userid
     * @return array<string, mixed>
     */
    public function resend_welcome_email(int $userid): array {
        $this->require_update_capability();
        $user = $this->get_user_details($userid);
        if (!empty($user['deleted'])) {
            throw new \moodle_exception('invaliduser');
        }

        $result = $this->send_access_email($user, 'welcome');
        $action = !empty($result['success']) ? 'WELCOME_EMAIL_SENT' : 'WELCOME_EMAIL_FAILED';
        $status = !empty($result['success']) ? 'success' : 'warning';
        $this->log_user_management_event($userid, $action, $status, $result['message']);

        return $result;
    }

    /**
     * Returns recent provisioning and management activity for a user.
     *
     * @param int $userid
     * @param int $limit
     * @return array<int, array<string, string>>
     */
    public function get_recent_user_activity(int $userid, int $limit = 20): array {
        global $DB;

        $items = [];

        if ($DB->get_manager()->table_exists(new \xmldb_table('local_ulms_user_provisioning_log'))) {
            $records = $DB->get_records('local_ulms_user_provisioning_log', ['createduserid' => $userid], 'timecreated DESC, id DESC', '*', 0, $limit);
            foreach ($records as $record) {
                $items[] = [
                    'action' => 'PROVISIONING',
                    'status' => (string)$record->status,
                    'message' => (string)$record->message,
                    'time' => (int)$record->timecreated,
                    'meta' => get_string('provisioningactivitymeta', 'local_ulms_dashboard', (object)[
                        'status' => (string)$record->status,
                        'mode' => (string)$record->createmode,
                        'actor' => get_string('adminportaleyebrow', 'local_ulms_dashboard'),
                    ]),
                ];
            }
        }

        if ($DB->get_manager()->table_exists(new \xmldb_table(self::LOG_TABLE))) {
            $records = $DB->get_records(self::LOG_TABLE, ['targetuserid' => $userid], 'timecreated DESC, id DESC', '*', 0, $limit);
            foreach ($records as $record) {
                $items[] = [
                    'action' => (string)$record->action,
                    'status' => (string)$record->status,
                    'message' => (string)$record->message,
                    'time' => (int)$record->timecreated,
                    'meta' => (string)$record->ipaddress,
                ];
            }
        }

        usort($items, static function(array $a, array $b): int {
            return $b['time'] <=> $a['time'];
        });

        return array_slice(array_map(static function(array $item): array {
            $item['timestring'] = userdate($item['time'], get_string('strftimedatetimeshort'));
            return $item;
        }, $items), 0, $limit);
    }

    /**
     * Returns the row actions available for a user.
     *
     * @param array<string, mixed> $user
     * @return array<string, bool|string>
     */
    public function get_user_actions(array $user): array {
        $systemcontext = \context::instance_by_id(\context_system::instance()->id);
        $canupdate = has_capability('moodle/user:update', $systemcontext);
        $candelete = has_capability('moodle/user:delete', $systemcontext);
        $cancreate = has_capability('moodle/user:create', $systemcontext);
        $deleted = !empty($user['deleted']);
        $isself = (int)$user['id'] === (int)$this->get_current_user_id();
        $rolechangeallowed = $canupdate && !$deleted && !$isself;
        $suspendallowed = $canupdate && !$deleted && empty($user['suspended']);
        $unsuspendallowed = $canupdate && !$deleted && !empty($user['suspended']);

        if ($user['rolekey'] === 'admin') {
            $rolechangeallowed = $rolechangeallowed && $this->can_manage_admin_accounts();
        }

        return [
            'view' => true,
            'edit' => $canupdate && !$deleted,
            'changerole' => $rolechangeallowed,
            'suspend' => $suspendallowed,
            'unsuspend' => $unsuspendallowed,
            'resetpassword' => $canupdate && !$deleted,
            'resendwelcome' => $canupdate && !$deleted,
            'delete' => $candelete && !$deleted && !$isself,
            'adduser' => $cancreate,
        ];
    }

    /**
     * Returns whether the current user may assign admin accounts.
     *
     * @return bool
     */
    public function can_manage_admin_accounts(): bool {
        return $this->provisioningservice->can_create_admin_accounts();
    }

    /**
     * Returns URL-safe filter params.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function get_filter_url_params(array $filters): array {
        $filters = $this->normalise_filters($filters);
        $params = [
            'search' => $filters['search'],
            'role' => $filters['role'],
            'status' => $filters['status'],
            'departmentid' => $filters['departmentid'],
            'level' => $filters['level'],
            'sort' => $filters['sort'],
            'dir' => $filters['dir'],
            'page' => $filters['page'],
            'perpage' => $filters['perpage'],
        ];

        return array_filter($params, static function($value): bool {
            return !($value === '' || $value === 0);
        });
    }

    /**
     * Returns whether the user profile table exists.
     *
     * @return bool
     */
    private function profile_table_exists(): bool {
        global $DB;

        return $DB->get_manager()->table_exists(new \xmldb_table(self::PROFILE_TABLE));
    }

    /**
     * Returns the academic-structure service when available.
     *
     * @return \local_ulms_academics\local\service\academic_structure_service|null
     */
    private function get_academic_service(): ?\local_ulms_academics\local\service\academic_structure_service {
        if ($this->academicservice !== null) {
            return $this->academicservice;
        }

        if (!class_exists(\local_ulms_academics\local\service\academic_structure_service::class)) {
            return null;
        }

        $this->academicservice = new \local_ulms_academics\local\service\academic_structure_service();
        return $this->academicservice;
    }

    /**
     * Returns department options with duplicate names disambiguated by faculty.
     *
     * @param int $facultyid
     * @param bool $includeall
     * @return array<int, string>
     */
    private function get_department_option_labels(int $facultyid = 0, bool $includeall = false): array {
        $service = $this->get_academic_service();
        $alllabel = $includeall
            ? [0 => get_string('all')]
            : [0 => get_string('usermanagementnotset', 'local_ulms_dashboard')];

        if ($service === null) {
            return $alllabel;
        }

        $facultynames = [];
        foreach ($service->get_records_for_entity('faculties') as $faculty) {
            $facultynames[(int)$faculty->id] = (string)$faculty->name;
        }

        $records = [];
        $namecounts = [];
        foreach ($service->get_records_for_entity('departments') as $department) {
            if ($facultyid > 0 && (int)($department->facultyid ?? 0) !== $facultyid) {
                continue;
            }

            $records[] = $department;
            $name = trim((string)$department->name);
            $namecounts[$name] = ($namecounts[$name] ?? 0) + 1;
        }

        $options = $alllabel;
        foreach ($records as $department) {
            $name = trim((string)$department->name);
            $label = $name;

            if (($namecounts[$name] ?? 0) > 1) {
                $facultyname = $facultynames[(int)($department->facultyid ?? 0)] ?? '';
                $label .= $facultyname !== '' ? ' (' . $facultyname . ')' : ' (#' . (int)$department->id . ')';
            }

            $options[(int)$department->id] = $label;
        }

        return $options;
    }

    /**
     * Returns a shared mail service instance.
     *
     * @return \local_ulms_mail\local\service\resend_mail_service
     */
    private function get_mail_service(): \local_ulms_mail\local\service\resend_mail_service {
        if ($this->mailservice === null) {
            $this->mailservice = new \local_ulms_mail\local\service\resend_mail_service();
        }

        return $this->mailservice;
    }

    /**
     * Builds the effective role expression SQL for list/detail queries.
     *
     * @param string $alias
     * @param string $prefix
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function get_role_expression(string $alias, string $prefix): array {
        global $DB;

        $adminroles = $this->get_admin_role_shortnames();
        [$adminrolesql, $adminroleparams] = $DB->get_in_or_equal($adminroles, SQL_PARAMS_NAMED, $prefix . 'adminrole');
        $siteadminids = $this->get_site_admin_ids();
        $siteadminchecksql = '1 = 0';
        $siteadminparams = [];

        if ($siteadminids !== []) {
            [$siteadmininsql, $siteadminparams] = $DB->get_in_or_equal($siteadminids, SQL_PARAMS_NAMED, $prefix . 'siteadmin');
            $siteadminchecksql = "{$alias}.id {$siteadmininsql}";
        }

        $params = [
            $prefix . 'admincontextid' => \context_system::instance()->id,
            $prefix . 'lecturercontextid' => \context_system::instance()->id,
            $prefix . 'editingteacher' => 'editingteacher',
            $prefix . 'teacher' => 'teacher',
            $prefix . 'studentcontextid' => \context_system::instance()->id,
            $prefix . 'student' => 'student',
        ];
        $params = array_merge($params, $adminroleparams, $siteadminparams);

        $sql = "CASE
            WHEN ({$siteadminchecksql}) THEN 'superadmin'
            WHEN EXISTS (
                SELECT 1
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = {$alias}.id
                   AND ra.contextid = :" . $prefix . "admincontextid
                    AND r.shortname {$adminrolesql}
            ) THEN 'admin'
            WHEN EXISTS (
                SELECT 1
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = {$alias}.id
                   AND ra.contextid = :" . $prefix . "lecturercontextid
                   AND (r.shortname = :" . $prefix . "editingteacher
                    OR r.shortname = :" . $prefix . "teacher)
            ) THEN 'lecturer'
            WHEN EXISTS (
                SELECT 1
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE ra.userid = {$alias}.id
                   AND ra.contextid = :" . $prefix . "studentcontextid
                   AND r.shortname = :" . $prefix . "student
            ) THEN 'student'
            ELSE 'other'
        END";

        return [$sql, $params];
    }

    /**
     * Returns a safe ORDER BY clause.
     *
     * @param string $sort
     * @param string $direction
     * @return string
     */
    private function get_sort_sql(string $sort, string $direction): string {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        return match ($sort) {
            'username' => "u.username {$direction}, u.lastname ASC, u.firstname ASC",
            'email' => "u.email {$direction}, u.lastname ASC, u.firstname ASC",
            'role' => "rolekey {$direction}, u.lastname ASC, u.firstname ASC",
            'status' => "u.deleted {$direction}, u.suspended {$direction}, u.lastname ASC, u.firstname ASC",
            'created' => "u.timecreated {$direction}, u.lastname ASC, u.firstname ASC",
            'lastlogin' => "u.lastaccess {$direction}, u.lastname ASC, u.firstname ASC",
            default => "u.lastname {$direction}, u.firstname {$direction}, u.id ASC",
        };
    }

    /**
     * Builds WHERE SQL for user filtering.
     *
     * @param array<string, mixed> $filters
     * @param string $alias
     * @param string $prefix
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function build_user_filter_sql(array $filters, string $alias, string $prefix): array {
        global $DB;

        $where = ["{$alias}.username <> :" . $prefix . "guest"];
        $params = [$prefix . 'guest' => 'guest'];

        if ($filters['status'] === 'deleted') {
            $where[] = "{$alias}.deleted = 1";
        } else {
            $where[] = "{$alias}.deleted = 0";
            if ($filters['status'] === 'active') {
                $where[] = "{$alias}.suspended = 0";
            } else if ($filters['status'] === 'suspended') {
                $where[] = "{$alias}.suspended = 1";
            }
        }

        if ($filters['search'] !== '') {
            $searchgroups = [];
            $tokens = preg_split('/\s+/', trim($filters['search'])) ?: [];
            $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

            foreach ($tokens as $tokenindex => $token) {
                $searchsql = [];
                $paramvalue = '%' . $token . '%';
                foreach (['firstname', 'middlename', 'lastname', 'username', 'email', 'idnumber'] as $fieldindex => $field) {
                    $paramname = $prefix . 'search' . $tokenindex . '_' . $fieldindex;
                    $searchsql[] = $DB->sql_like("{$alias}.{$field}", ':' . $paramname, false, false);
                    $params[$paramname] = $paramvalue;
                }

                if ($searchsql !== []) {
                    $searchgroups[] = '(' . implode(' OR ', $searchsql) . ')';
                }
            }

            if ($searchgroups !== []) {
                $where[] = '(' . implode(' AND ', $searchgroups) . ')';
            }
        }

        if ($filters['departmentid'] > 0) {
            $where[] = "p.departmentid = :" . $prefix . "departmentid";
            $params[$prefix . 'departmentid'] = $filters['departmentid'];
        }

        if ($filters['level'] !== '') {
            $where[] = "p.studylevel = :" . $prefix . "level";
            $params[$prefix . 'level'] = $filters['level'];
        }

        if ($filters['role'] !== '') {
            [$roleexpr, $roleparams] = $this->get_role_expression($alias, $prefix . 'filterrole');
            $where[] = "{$roleexpr} = :" . $prefix . "role";
            $params = array_merge($params, $roleparams);
            $params[$prefix . 'role'] = $filters['role'];
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Maps a list/detail row into a UI-friendly array.
     *
     * @param \stdClass $record
     * @return array<string, mixed>
     */
    private function map_list_record(\stdClass $record): array {
        $rolekey = (string)($record->rolekey ?? 'other');
        if (is_siteadmin((int)$record->id)) {
            $rolekey = 'superadmin';
        }

        $statuskey = !empty($record->deleted)
            ? 'deleted'
            : (!empty($record->suspended) ? 'suspended' : 'active');

        $formattedlastaccess = !empty($record->lastaccess)
            ? userdate((int)$record->lastaccess, get_string('strftimedatetimeshort'))
            : get_string('never');
        $neverloggedin = empty($record->lastaccess);

        return [
            'id' => (int)$record->id,
            'firstname' => (string)($record->firstname ?? ''),
            'lastname' => (string)($record->lastname ?? ''),
            'fullname' => $this->format_fullname($record),
            'username' => (string)($record->username ?? ''),
            'email' => (string)($record->email ?? ''),
            'idnumber' => (string)($record->idnumber ?? ''),
            'rolekey' => $rolekey,
            'rolelabel' => $this->get_role_label($rolekey),
            'deleted' => (int)($record->deleted ?? 0),
            'suspended' => (int)($record->suspended ?? 0),
            'statuskey' => $statuskey,
            'statuslabel' => $this->get_status_options(false)[$statuskey] ?? get_string('unknown'),
            'departmentname' => (string)($record->departmentname ?? ''),
            'studylevel' => (string)($record->studylevel ?? ''),
            'staffid' => (string)($record->staffid ?? ''),
            'timecreated' => (int)($record->timecreated ?? 0),
            'timecreatedformatted' => !empty($record->timecreated)
                ? userdate((int)$record->timecreated, get_string('strftimedatetimeshort'))
                : get_string('never'),
            'lastaccess' => (int)($record->lastaccess ?? 0),
            'lastaccessformatted' => $formattedlastaccess,
            'neverloggedin' => $neverloggedin,
        ];
    }

    /**
     * Returns a Moodle-safe fullname string for partial user records.
     *
     * @param \stdClass|array<string, mixed> $record
     * @return string
     */
    private function format_fullname(\stdClass|array $record): string {
        if (is_array($record)) {
            $record = (object)$record;
        }

        foreach (['firstname', 'lastname', 'middlename', 'firstnamephonetic', 'lastnamephonetic', 'alternatename'] as $field) {
            if (!property_exists($record, $field)) {
                $record->{$field} = '';
            }
        }

        return trim(fullname($record));
    }

    /**
     * Returns a ULMS role label.
     *
     * @param string $rolekey
     * @return string
     */
    private function get_role_label(string $rolekey): string {
        return match ($rolekey) {
            'student' => get_string('usermanagementrolestudent', 'local_ulms_dashboard'),
            'lecturer' => get_string('usermanagementrolelecturer', 'local_ulms_dashboard'),
            'admin' => get_string('usermanagementroleadmin', 'local_ulms_dashboard'),
            'superadmin' => get_string('usermanagementrolesuperadmin', 'local_ulms_dashboard'),
            default => get_string('usermanagementroleother', 'local_ulms_dashboard'),
        };
    }

    /**
     * Returns role shortnames assigned at the system context.
     *
     * @param int $userid
     * @return array<int, string>
     */
    private function get_user_role_shortnames(int $userid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT r.shortname
               FROM {role_assignments} ra
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.userid = :userid
                AND ra.contextid = :contextid
           ORDER BY r.sortorder ASC, r.shortname ASC",
            [
                'userid' => $userid,
                'contextid' => \context_system::instance()->id,
            ]
        );

        $shortnames = array_values(array_map(static fn($record): string => (string)$record->shortname, $records));
        if (is_siteadmin($userid) && !in_array('manager', $shortnames, true)) {
            array_unshift($shortnames, 'manager');
        }

        return $shortnames;
    }

    /**
     * Returns whether the given programme ID corresponds to an student-equivalent role (role map to the student portal family).
     *
     * @param string $rolekey
     * @return bool
     */
    private function is_student_role(string $rolekey): bool {
        return in_array($rolekey, ['student'], true);
    }

    /**
     * Resolves the cascade-correct facultyid and departmentid starting from a given programme id,
     * by walking programme → department → faculty. Returns [facultyid, departmentid, was_needed].
     *
     * If programmeid <= 0 returns [0,0,false]. If programme not found returns [0,0,false].
     *
     * @param int $programmeid
     * @return array{0: int, 1: int, 2: bool}
     */
    public function resolve_academic_fks_from_programme(int $programmeid): array {
        global $DB;
        $programmeid = max(0, $programmeid);
        if ($programmeid <= 0 || $this->get_academic_service() === null) {
            return [0, 0, false];
        }
        $sql = "SELECT p.departmentid, d.facultyid
                  FROM {local_ulms_programmes} p
                  JOIN {local_ulms_departments} d ON d.id = p.departmentid
                 WHERE p.id = :pid
                   AND p.status = :st";
        $row = $DB->get_record_sql($sql, ['pid' => $programmeid, 'st' => 'active']);
        if (!$row) {
            return [0, 0, false];
        }
        return [
            max(0, (int)($row->facultyid ?? 0)),
            max(0, (int)($row->departmentid ?? 0)),
            true,
        ];
    }

    /**
     * Validates academic metadata relationships.
     *
     * If a programme is supplied (programmeid>0, enforce cascade overwrite: facultyid/departmentid
     * are forced to match the programme's own hierarchy. Any manual selection of the wrong
     * faculty or department is normalized silently, a warning is returned in the third element.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, string>, 1: array<string, mixed>, 2: string}
     */
    private function validate_academic_metadata(array $data): array {
        $errors = [];
        $warning = '';
        $cleaned = [
            'facultyid' => max(0, (int)($data['facultyid'] ?? 0)),
            'departmentid' => max(0, (int)($data['departmentid'] ?? 0)),
            'programmeid' => max(0, (int)($data['programmeid'] ?? 0)),
            'studylevel' => trim((string)($data['studylevel'] ?? '')),
            'staffid' => trim((string)($data['staffid'] ?? '')),
        ];

        if ($cleaned['studylevel'] !== '' && \core_text::strlen($cleaned['studylevel']) > 50) {
            $errors['studylevel'] = get_string('maximumchars', '', 50);
        }

        if ($cleaned['staffid'] !== '' && \core_text::strlen($cleaned['staffid']) > 100) {
            $errors['staffid'] = get_string('maximumchars', '', 100);
        }

        if ($this->get_academic_service() === null) {
            $cleaned['facultyid'] = 0;
            $cleaned['departmentid'] = 0;
            $cleaned['programmeid'] = 0;
            return [$errors, $cleaned, $warning];
        }

        // Cascade normalization: overwrite fac/dept from programme when available.
        if ($cleaned['programmeid'] > 0) {
            [$cascadeFac, $cascadeDept, $ok] = $this->resolve_academic_fks_from_programme($cleaned['programmeid']);
            if ($ok && ($cascadeFac !== $cleaned['facultyid'] || $cascadeDept !== $cleaned['departmentid'])) {
                $warning = get_string('usermanagementprogrammenormalized', 'local_ulms_dashboard');
            }
            if ($ok) {
                $cleaned['facultyid'] = $cascadeFac;
                $cleaned['departmentid'] = $cascadeDept;
            } else {
                // Cascade failed -> programme invalid or inactive.
                $errors['programmeid'] = get_string('invaliddata');
                $cleaned['programmeid'] = 0;
            }
        }

        $facultyoptions = $this->get_academic_form_options()['faculties'];
        if ($cleaned['facultyid'] > 0 && !array_key_exists($cleaned['facultyid'], $facultyoptions)) {
            $errors['facultyid'] = get_string('invaliddata');
            $cleaned['facultyid'] = 0;
        }

        $departmentoptions = $this->get_academic_form_options($cleaned['facultyid'])['departments'];
        if ($cleaned['departmentid'] > 0 && !array_key_exists($cleaned['departmentid'], $departmentoptions)) {
            $errors['departmentid'] = get_string('invaliddata');
            $cleaned['departmentid'] = 0;
        }

        // Final structural verify from cascade again after fac/dept overwrite to ensure programme still belongs to this hierarchy.
        if ($cleaned['programmeid'] > 0) {
            $programmeoptions = $this->get_academic_form_options($cleaned['facultyid'], $cleaned['departmentid'])['programmes'];
            if (!array_key_exists($cleaned['programmeid'], $programmeoptions)) {
                $errors['programmeid'] = get_string('invaliddata');
                $cleaned['programmeid'] = 0;
            }
        }

        return [$errors, $cleaned, $warning];
    }

    /**
     * Saves the ULMS user-profile row.
     *
     * @param int $userid
     * @param array<string, mixed> $data
     * @return void
     */
    private function save_profile_record(int $userid, array $data): void {
        global $DB;

        if (!$this->profile_table_exists()) {
            return;
        }

        $facultyid = (int)($data['facultyid'] ?? 0);
        $departmentid = (int)($data['departmentid'] ?? 0);
        $programmeid = (int)($data['programmeid'] ?? 0);
        $studylevel = trim((string)($data['studylevel'] ?? ''));

        if ($facultyid > 0 && !$DB->record_exists('local_ulms_faculties', ['id' => $facultyid])) {
            throw new \InvalidArgumentException('Selected college/faculty does not exist.');
        }
        if ($departmentid > 0 && !$DB->record_exists('local_ulms_departments', ['id' => $departmentid])) {
            throw new \InvalidArgumentException('Selected department does not exist.');
        }
        if ($programmeid > 0 && !$DB->record_exists('local_ulms_programmes', ['id' => $programmeid])) {
            throw new \InvalidArgumentException('Selected programme does not exist.');
        }
        if ($studylevel !== '' && $DB->get_manager()->table_exists('local_ulms_levels')) {
            if (!$DB->record_exists('local_ulms_levels', ['code' => $studylevel])) {
                throw new \InvalidArgumentException('Selected study level code does not exist.');
            }
        }

        $record = (object)[
            'userid' => $userid,
            'facultyid' => $facultyid,
            'departmentid' => $departmentid,
            'programmeid' => $programmeid,
            'studylevel' => $studylevel,
            'staffid' => trim((string)($data['staffid'] ?? '')),
            'timemodified' => time(),
        ];

        $existing = $DB->get_record(self::PROFILE_TABLE, ['userid' => $userid], 'id', IGNORE_MISSING);
        if ($existing) {
            $record->id = (int)$existing->id;
            $DB->update_record(self::PROFILE_TABLE, $record);
            return;
        }

        $record->timecreated = time();
        $DB->insert_record(self::PROFILE_TABLE, $record);
    }

    /**
     * Applies a ULMS role change at the system context.
     *
     * @param int $userid
     * @param string $newrole
     * @param string $oldrole
     * @return void
     */
    private function apply_role_change(int $userid, string $newrole, string $oldrole): void {
        global $DB, $CFG;

        $this->assert_no_self_mutation($userid, 'rolechange');
        if ($oldrole === 'superadmin' && $newrole !== 'superadmin') {
            $this->assert_last_superadmin_protection($userid, 'demote');
        }

        $systemcontext = \context_system::instance();
        $roleids = [];
        foreach ($this->get_managed_role_shortnames() as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id', IGNORE_MISSING);
            if ($role) {
                $roleids[] = (int)$role->id;
            }
        }

        if ($roleids !== []) {
            [$insql, $params] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $params['contextid'] = $systemcontext->id;
            $assignments = $DB->get_records_sql(
                "SELECT id, roleid
                   FROM {role_assignments}
                  WHERE userid = :userid
                    AND contextid = :contextid
                    AND roleid {$insql}",
                $params
            );
            foreach ($assignments as $assignment) {
                role_unassign((int)$assignment->roleid, $userid, $systemcontext->id);
            }
        }

        $config = $this->get_role_assignment_config($newrole);
        role_assign($config['roleid'], $userid, $systemcontext->id);

        if ($newrole === 'superadmin' && $oldrole !== 'superadmin') {
            $siteadmins = array_filter(array_map('intval', explode(',', (string)($CFG->siteadmins ?? ''))));
            if (!in_array($userid, $siteadmins, true)) {
                $siteadmins[] = $userid;
                set_config('siteadmins', implode(',', $siteadmins));
            }
        } elseif ($oldrole === 'superadmin' && $newrole !== 'superadmin') {
            $siteadmins = array_values(array_filter(
                array_map('intval', explode(',', (string)($CFG->siteadmins ?? ''))),
                static fn(int $id): bool => $id !== $userid
            ));
            set_config('siteadmins', implode(',', $siteadmins));
        }

        $target = $this->get_user_details($userid);
        $this->log_user_management_event(
            $userid,
            'ROLE_CHANGED',
            'success',
            get_string('usermanagementrolechangesuccess', 'local_ulms_dashboard', (object)[
                'name' => $this->format_fullname($target),
                'oldrole' => $this->get_role_label($oldrole),
                'newrole' => $this->get_role_label($newrole),
            ]),
            [
                'oldrole' => $oldrole,
                'newrole' => $newrole,
            ]
        );
    }

    /**
     * Returns the assignable role configuration.
     *
     * @param string $rolekey
     * @return array<string, mixed>
     */
    private function get_role_assignment_config(string $rolekey): array {
        global $DB;

        $configs = [
            'student' => ['student'],
            'lecturer' => ['editingteacher', 'teacher'],
            'admin' => ['manager'],
            'superadmin' => ['manager'],
        ];

        if (empty($configs[$rolekey])) {
            throw new \moodle_exception('invaliddata');
        }

        foreach ($configs[$rolekey] as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id,shortname', IGNORE_MISSING);
            if ($role) {
                return [
                    'roleid' => (int)$role->id,
                    'shortname' => (string)$role->shortname,
                    'rolekey' => $rolekey,
                ];
            }
        }

        throw new \moodle_exception('invaliddata');
    }

    /**
     * Sends a secure access email using the configured ULMS mail transport.
     *
     * @param array<string, mixed> $user
     * @param string $mode
     * @return array<string, mixed>
     */
    private function send_access_email(array $user, string $mode): array {
        global $CFG;

        $site = get_site();
        $userrecord = \core_user::get_user((int)$user['id'], '*', MUST_EXIST);
        $resetrecord = local_ulms_auth_issue_password_token($userrecord);
        if (!$resetrecord) {
            $message = $mode === 'passwordreset'
                ? get_string('usermanagementpasswordresetfailed', 'local_ulms_dashboard', $user['fullname'])
                : get_string('usermanagementwelcomefailed', 'local_ulms_dashboard', $user['fullname']);

            return [
                'success' => false,
                'warning' => true,
                'message' => $message,
            ];
        }

        $tokenurl = $mode === 'passwordreset'
            ? local_ulms_auth_get_password_reset_token_url(null, $resetrecord->token)->out(false)
            : local_ulms_auth_get_activation_url($resetrecord->token)->out(false);
        $subjectkey = $mode === 'passwordreset'
            ? 'usermanagementpasswordresetemailsubject'
            : 'usermanagementwelcomeemailsubject';
        $bodykey = $mode === 'passwordreset'
            ? 'usermanagementpasswordresetemailbody'
            : 'usermanagementwelcomeemailbody';

        $templatedata = (object)[
            'firstname' => (string)$user['fullname'],
            'rolelabel' => (string)$user['rolelabel'],
            'username' => (string)$user['username'],
            'resetlink' => $tokenurl,
            'activationlink' => $tokenurl,
            'signinurl' => local_ulms_auth_get_unified_sign_in_url()->out(false),
            'sitename' => format_string($site->fullname),
            'supportsignature' => generate_email_signoff(),
        ];

        $subject = get_string($subjectkey, 'local_ulms_dashboard', format_string($site->fullname));
        $messagetext = get_string($bodykey, 'local_ulms_dashboard', $templatedata);
        $messagehtml = text_to_html($messagetext, false, false, true);
        $idempotencykey = sha1(implode('|', [
            'ulms_user_management',
            $mode,
            (string)$user['id'],
            (string)$tokenurl,
        ]));
        $emailsent = false;

        if (($CFG->ulmsmailtransport ?? 'moodle') === 'resend') {
            $replyto = !empty($CFG->ulmsreplyto) ? (string)$CFG->ulmsreplyto : (string)($CFG->supportemail ?? '');
            $replytoname = !empty($CFG->supportname) ? (string)$CFG->supportname : format_string($site->shortname);
            $result = $this->get_mail_service()->send_transactional_email([
                'to' => [[
                    'email' => (string)$userrecord->email,
                    'name' => fullname($userrecord),
                ]],
                'subject' => $subject,
                'text' => $messagetext,
                'html' => $messagehtml,
                'replyto' => $replyto !== '' ? [[
                    'email' => $replyto,
                    'name' => $replytoname,
                ]] : [],
                'idempotencykey' => $idempotencykey,
            ]);
            $emailsent = !empty($result['success']);
        } else {
            $emailsent = local_ulms_auth_send_transactional_email(
                $userrecord,
                $subject,
                $messagetext,
                $messagehtml,
                $idempotencykey
            );
        }

        if ($emailsent) {
            $message = $mode === 'passwordreset'
                ? get_string('usermanagementpasswordresetsent', 'local_ulms_dashboard', $user['fullname'])
                : get_string('usermanagementwelcomeresent', 'local_ulms_dashboard', $user['fullname']);

            return [
                'success' => true,
                'warning' => false,
                'message' => $message,
            ];
        }

        $message = $mode === 'passwordreset'
            ? get_string('usermanagementpasswordresetfailed', 'local_ulms_dashboard', $user['fullname'])
            : get_string('usermanagementwelcomefailed', 'local_ulms_dashboard', $user['fullname']);

        return [
            'success' => false,
            'warning' => true,
            'message' => $message,
        ];
    }

    /**
     * Persists a management audit entry.
     *
     * @param int $targetuserid
     * @param string $action
     * @param string $status
     * @param string $message
     * @param array<string, mixed> $details
     * @return void
     */
    private function log_user_management_event(
        int $targetuserid,
        string $action,
        string $status,
        string $message,
        array $details = []
    ): void {
        global $DB, $USER;

        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::LOG_TABLE))) {
            return;
        }

        $detailsscrubbed = \local_ulms_dashboard_scrub_sensitive_details($details);

        $DB->insert_record(self::LOG_TABLE, (object)[
            'actorid' => (int)($USER->id ?? 0),
            'targetuserid' => $targetuserid,
            'action' => substr($action, 0, 64),
            'status' => substr($status, 0, 32),
            'message' => $message,
            'detailsjson' => json_encode($detailsscrubbed),
            'ipaddress' => substr((string)getremoteaddr(null), 0, 64),
            'timecreated' => time(),
        ]);
    }

    /**
     * Returns whether a department id exists.
     *
     * @param int $departmentid
     * @return bool
     */
    private function department_exists(int $departmentid): bool {
        global $DB;

        if ($departmentid <= 0) {
            return true;
        }

        return $DB->record_exists('local_ulms_departments', ['id' => $departmentid]);
    }

    /**
     * Returns whether a username already exists, excluding an optional user.
     *
     * @param string $username
     * @param int $excludeuserid
     * @return bool
     */
    private function username_exists(string $username, int $excludeuserid = 0): bool {
        global $CFG, $DB;

        $sql = "SELECT 1
                  FROM {user}
                 WHERE username = :username
                   AND mnethostid = :mnethostid
                   AND deleted = 0";
        $params = [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
        ];

        if ($excludeuserid > 0) {
            $sql .= " AND id <> :excludeuserid";
            $params['excludeuserid'] = $excludeuserid;
        }

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Returns whether an email already exists, excluding an optional user.
     *
     * @param string $email
     * @param int $excludeuserid
     * @return bool
     */
    private function email_exists(string $email, int $excludeuserid = 0): bool {
        global $CFG, $DB;

        $sql = "SELECT 1
                  FROM {user}
                 WHERE mnethostid = :mnethostid
                   AND deleted = 0
                   AND " . $DB->sql_equal('email', ':email', false, true);
        $params = [
            'mnethostid' => $CFG->mnet_localhost_id,
            'email' => $email,
        ];

        if ($excludeuserid > 0) {
            $sql .= " AND id <> :excludeuserid";
            $params['excludeuserid'] = $excludeuserid;
        }

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Returns whether an idnumber already exists, excluding an optional user.
     *
     * @param string $idnumber
     * @param int $excludeuserid
     * @return bool
     */
    private function idnumber_exists(string $idnumber, int $excludeuserid = 0): bool {
        global $DB;

        if ($idnumber === '') {
            return false;
        }

        $sql = "SELECT 1
                  FROM {user}
                 WHERE idnumber = :idnumber
                   AND deleted = 0";
        $params = ['idnumber' => $idnumber];
        if ($excludeuserid > 0) {
            $sql .= " AND id <> :excludeuserid";
            $params['excludeuserid'] = $excludeuserid;
        }

        return $DB->record_exists_sql($sql, $params);
    }

    /**
     * Returns current user id.
     *
     * @return int
     */
    private function get_current_user_id(): int {
        global $USER;

        return (int)($USER->id ?? 0);
    }

    /**
     * Requires access to the user-management module.
     *
     * @return void
     */
    public function require_management_access(): void {
        $systemcontextid = \context_system::instance()->id;
        require_capability('local/ulms_dashboard:viewadmindashboard', \context::instance_by_id($systemcontextid));
    }

    /**
     * Requires user-create access.
     *
     * @return void
     */
    private function require_create_capability(): void {
        $this->require_management_access();
        require_capability('moodle/user:create', \context::instance_by_id(\context_system::instance()->id));
    }

    /**
     * Requires user-update access.
     *
     * @return void
     */
    private function require_update_capability(): void {
        $this->require_management_access();
        require_capability('moodle/user:update', \context::instance_by_id(\context_system::instance()->id));
    }

    /**
     * Requires user-delete access.
     *
     * @return void
     */
    private function require_delete_capability(): void {
        $this->require_management_access();
        require_capability('moodle/user:delete', \context::instance_by_id(\context_system::instance()->id));
    }

    /**
     * Protects the current and final active admin accounts.
     *
     * @param array<string, mixed> $user
     * @param string|null $newrole
     * @param string|null $newstatus
     * @return void
     */
    private function assert_admin_protection(array $user, ?string $newrole, ?string $newstatus): void {
        $removesadmin = false;
        $removessuperadmin = false;
        if ($user['rolekey'] === 'admin') {
            if ($newrole !== null && $newrole !== 'admin') {
                $removesadmin = true;
            }
            if ($newstatus === 'suspended' || $newstatus === 'deleted') {
                $removesadmin = true;
            }
        }
        if ($user['rolekey'] === 'superadmin') {
            if ($newrole !== null && $newrole !== 'superadmin') {
                $removessuperadmin = true;
            }
            if ($newstatus === 'suspended' || $newstatus === 'deleted') {
                $removessuperadmin = true;
            }
        }

        if (!$removesadmin && !$removessuperadmin) {
            return;
        }

        if ((int)$user['id'] === $this->get_current_user_id()) {
            throw new \moodle_exception('usermanagementerrorprotectselfadmin', 'local_ulms_dashboard');
        }

        if ($removesadmin && $this->count_active_admins_excluding((int)$user['id']) < 1) {
            throw new \moodle_exception('usermanagementerrorlastadmin', 'local_ulms_dashboard');
        }

        if ($removessuperadmin) {
            $this->assert_last_superadmin_protection((int)$user['id'], 'demote');
        }
    }

    /**
     * Returns the number of remaining active admin users excluding a target user.
     *
     * @param int $excludeuserid
     * @return int
     */
    private function count_active_admins_excluding(int $excludeuserid): int {
        global $CFG, $DB;

        $systemcontext = \context_system::instance();
        $adminroles = $this->get_admin_role_shortnames();
        [$adminrolesql, $adminroleparams] = $DB->get_in_or_equal($adminroles, SQL_PARAMS_NAMED, 'adminrole');
        $params = [
            'contextid' => $systemcontext->id,
            'excludeuserid' => $excludeuserid,
            'guest' => 'guest',
        ];
        $params = array_merge($params, $adminroleparams);
        $managerids = $DB->get_fieldset_sql(
            "SELECT DISTINCT u.id
               FROM {user} u
               JOIN {role_assignments} ra ON ra.userid = u.id
               JOIN {role} r ON r.id = ra.roleid
              WHERE ra.contextid = :contextid
                 AND r.shortname {$adminrolesql}
                AND u.deleted = 0
                AND u.suspended = 0
                AND u.id <> :excludeuserid
                AND u.username <> :guest",
            $params
        );

        $siteadmins = array_filter(array_map('intval', explode(',', (string)($CFG->siteadmins ?? ''))));
        $adminids = array_values(array_unique(array_merge($managerids, $siteadmins)));
        if ($adminids === []) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED);
        $inparams['excludeuserid'] = $excludeuserid;
        $inparams['guest'] = 'guest';

        return (int)$DB->count_records_sql(
            "SELECT COUNT(1)
               FROM {user}
              WHERE id {$insql}
                AND deleted = 0
                AND suspended = 0
                AND id <> :excludeuserid
                AND username <> :guest",
            $inparams
        );
    }

    /**
     * Returns role shortnames that should be treated as administrator access.
     *
     * @return array<int, string>
     */
    private function get_admin_role_shortnames(): array {
        return ['manager', 'coursecreator', 'ictadmin', 'facultyadmin', 'departmentadmin'];
    }

    /**
     * Returns all role shortnames managed by the user-management role switcher.
     *
     * @return array<int, string>
     */
    private function get_managed_role_shortnames(): array {
        return array_values(array_unique(array_merge(
            ['student', 'editingteacher', 'teacher'],
            $this->get_admin_role_shortnames()
        )));
    }

    /**
     * Returns configured site administrator user ids.
     *
     * @return array<int, int>
     */
    private function get_site_admin_ids(): array {
        global $CFG;

        return array_values(array_filter(array_map('intval', explode(',', (string)($CFG->siteadmins ?? '')))));
    }

    /**
     * Returns human-readable faculty and department labels for core user fields.
     *
     * @param int $facultyid
     * @param int $departmentid
     * @return array{0: string, 1: string}
     */
    private function get_core_academic_labels(int $facultyid, int $departmentid): array {
        global $DB;

        $institution = '';
        $department = '';

        if ($facultyid > 0) {
            $institution = (string)$DB->get_field('local_ulms_faculties', 'name', ['id' => $facultyid]) ?: '';
        }

        if ($departmentid > 0) {
            $department = (string)$DB->get_field('local_ulms_departments', 'name', ['id' => $departmentid]) ?: '';
        }

        return [$institution, $department];
    }

    /**
     * Returns a paginated, filterable list of level records.
     *
     * @param array{search?: string, status?: string, sort?: string, dir?: string, page?: int, perpage?: int} $filters
     * @return array{rows: array<int, \stdClass>, total: int}
     */
    public function get_levels(array $filters = []): array {
        global $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))) {
            return ['rows' => [], 'total' => 0];
        }

        $search = trim((string)($filters['search'] ?? ''));
        $status = trim((string)($filters['status'] ?? ''));
        $sortraw = (string)($filters['sort'] ?? 'sortorder');
        $sort = in_array($sortraw, ['sortorder', 'code', 'name', 'status', 'timecreated'], true)
            ? $sortraw
            : 'sortorder';
        $dir = strtoupper((string)($filters['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';
        $page = max(0, (int)($filters['page'] ?? 0));
        $perpage = max(1, min(100, (int)($filters['perpage'] ?? 20)));

        [$where, $params] = $this->build_level_where_clause($search, $status);

        $countsql = "SELECT COUNT(1) FROM {local_ulms_levels}";
        if ($where !== '') {
            $countsql .= ' WHERE ' . $where;
        }
        $total = (int)$DB->count_records_sql($countsql, $params);

        $sql = "SELECT * FROM {local_ulms_levels}";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= " ORDER BY {$sort} {$dir}";

        $rows = array_values(
            $DB->get_records_sql($sql, $params, $page * $perpage, $perpage)
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Builds the where clause and bound params for level listings.
     *
     * @param string $search
     * @param string $status
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function build_level_where_clause(string $search, string $status): array {
        global $DB;
        $where = [];
        $params = [];

        if ($search !== '') {
            $where[] = "(" . $DB->sql_like('code', ':lsearchcode', false, false)
                . " OR " . $DB->sql_like('name', ':lsearchname', false, false) . ")";
            $params['lsearchcode'] = '%' . $DB->sql_like_escape($search) . '%';
            $params['lsearchname'] = '%' . $DB->sql_like_escape($search) . '%';
        }

        if ($status !== '') {
            $where[] = "status = :lstatus";
            $params['lstatus'] = $status;
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Returns a single level record by id or null.
     *
     * @param int $id
     * @return \stdClass|null
     */
    public function get_level(int $id): ?\stdClass {
        global $DB;

        if ($id <= 0 || !$DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))) {
            return null;
        }

        $record = $DB->get_record('local_ulms_levels', ['id' => $id], '*', IGNORE_MISSING);

        return $record ?: null;
    }

    /**
     * Creates or updates a level record.
     *
     * @param array{id?: int, code: string, name: string, description?: string, status?: string, sortorder?: int} $payload
     * @return array{success: bool, id: int, message: string, errors: array<int, string>}
     */
    public function save_level(array $payload): array {
        global $DB, $USER;

        $errors = [];
        if (!$DB->get_manager()->table_exists(new \xmldb_table('local_ulms_levels'))) {
            return [
                'success' => false,
                'id' => 0,
                'message' => get_string('levelssaveerrortable', 'local_ulms_dashboard'),
                'errors' => [get_string('levelssaveerrortable', 'local_ulms_dashboard')],
            ];
        }

        $id = max(0, (int)($payload['id'] ?? 0));
        $origid = $id;
        $code = trim((string)($payload['code'] ?? ''));
        $name = trim((string)($payload['name'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));
        $statusraw = (string)($payload['status'] ?? 'active');
        $status = in_array($statusraw, ['active', 'inactive'], true)
            ? $statusraw
            : 'active';
        $sortorder = (int)($payload['sortorder'] ?? 0);
        $now = time();

        if ($code === '') {
            $errors[] = get_string('provisioningerrorrequiredfield', 'local_ulms_dashboard', 'code');
        } elseif (\core_text::strlen($code) > 50) {
            $errors[] = get_string('levelserrorcodetoolong', 'local_ulms_dashboard');
        } else {
            $duplicatewhere = 'code = :code';
            $duplicateparams = ['code' => $code];
            if ($id > 0) {
                $duplicatewhere .= ' AND id <> :id';
                $duplicateparams['id'] = $id;
            }
            if ($DB->record_exists_select('local_ulms_levels', $duplicatewhere, $duplicateparams)) {
                $errors[] = get_string('levelserrorduplicatecode', 'local_ulms_dashboard', s($code));
            }
        }

        if ($name === '') {
            $errors[] = get_string('provisioningerrorrequiredfield', 'local_ulms_dashboard', 'name');
        } elseif (\core_text::strlen($name) > 255) {
            $errors[] = get_string('levelserrornametoolong', 'local_ulms_dashboard');
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'id' => $id,
                'message' => implode(' ', $errors),
                'errors' => $errors,
            ];
        }

        $record = (object)[
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'status' => $status,
            'sortorder' => $sortorder,
            'timemodified' => $now,
        ];

        if ($id > 0) {
            $existing = $this->get_level($id);
            if (!$existing) {
                $errors[] = get_string('levelserrornotfound', 'local_ulms_dashboard');
                return [
                    'success' => false,
                    'id' => 0,
                    'message' => implode(' ', $errors),
                    'errors' => $errors,
                ];
            }
            $record->id = $id;
            $DB->update_record('local_ulms_levels', $record);
            $message = get_string('levelsupdated', 'local_ulms_dashboard', s($name));
        } else {
            $record->timecreated = $now;
            $id = (int)$DB->insert_record('local_ulms_levels', $record);
            $message = get_string('levelscreated', 'local_ulms_dashboard', s($name));
        }

        $this->append_level_management_log(
            $id,
            $origid > 0 ? 'level.update' : 'level.create',
            'success',
            $message,
            [
                'actor' => (int)($USER->id ?? 0),
                'code' => $code,
                'status' => $status,
            ]
        );

        return [
            'success' => true,
            'id' => $id,
            'message' => $message,
            'errors' => [],
        ];
    }

    /**
     * Toggles a level record between active and inactive (soft delete / disable pattern).
     *
     * @param int $id
     * @param string|null $targetstatus Optional explicit status; otherwise flips the current one.
     * @return array{success: bool, message: string}
     */
    public function toggle_level_status(int $id, ?string $targetstatus = null): array {
        global $DB, $USER;

        $record = $this->get_level($id);
        if (!$record) {
            return [
                'success' => false,
                'message' => get_string('levelserrornotfound', 'local_ulms_dashboard'),
            ];
        }

        $nextstatus = $targetstatus ?? ((string)$record->status === 'active' ? 'inactive' : 'active');
        if (!in_array($nextstatus, ['active', 'inactive'], true)) {
            $nextstatus = (string)$record->status === 'active' ? 'inactive' : 'active';
        }

        if ((string)$record->status !== $nextstatus) {
            $DB->update_record('local_ulms_levels', (object)[
                'id' => (int)$record->id,
                'status' => $nextstatus,
                'timemodified' => time(),
            ]);
        }

        $action = $nextstatus === 'active' ? 'level.enable' : 'level.disable';
        $messagekey = $nextstatus === 'active' ? 'levelsenabled' : 'levelsdisabled';
        $message = get_string($messagekey, 'local_ulms_dashboard', s((string)$record->name));

        $this->append_level_management_log(
            (int)$record->id,
            $action,
            'success',
            $message,
            ['actor' => (int)($USER->id ?? 0), 'status' => $nextstatus]
        );

        return ['success' => true, 'message' => $message];
    }

    /**
     * Hard-deletes a level record. The studylevel char column on user profile keeps working
     * because the display value is denormalized there; this only removes the master entry.
     *
     * @param int $id
     * @return array{success: bool, message: string}
     */
    public function delete_level(int $id): array {
        global $DB, $USER;

        $record = $this->get_level($id);
        if (!$record) {
            return [
                'success' => false,
                'message' => get_string('levelserrornotfound', 'local_ulms_dashboard'),
            ];
        }

        $DB->delete_records('local_ulms_levels', ['id' => (int)$record->id]);

        $message = get_string('levelsdeleted', 'local_ulms_dashboard', s((string)$record->name));
        $this->append_level_management_log(
            (int)$record->id,
            'level.delete',
            'success',
            $message,
            ['actor' => (int)($USER->id ?? 0), 'code' => (string)$record->code]
        );

        return ['success' => true, 'message' => $message];
    }

    /**
     * Appends an audit row for a level management action, reusing the standard user management
     * log table to keep all admin write operations in one place.
     *
     * @param int $levelid
     * @param string $action
     * @param string $status
     * @param string $message
     * @param array<string, mixed> $details
     * @return void
     */
    private function append_level_management_log(
        int $levelid,
        string $action,
        string $status,
        string $message,
        array $details = []
    ): void {
        global $USER, $DB;

        if (!$DB->get_manager()->table_exists(new \xmldb_table(self::MANAGEMENT_LOG_TABLE))) {
            return;
        }

        $now = time();
        $record = (object)[
            'actorid' => (int)($USER->id ?? 0),
            'targetuserid' => 0,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'detailsjson' => json_encode([
                'levelid' => $levelid,
            ] + $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ipaddress' => (string)getremoteaddr(),
            'timecreated' => $now,
        ];

        try {
            $DB->insert_record(self::MANAGEMENT_LOG_TABLE, $record);
        } catch (\Throwable $e) {
            // Never let audit-log writes break CRUD actions.
            unset($e);
        }
    }
}
