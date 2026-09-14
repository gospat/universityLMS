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

require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/login/lib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/local/ulms_mail/lib.php');
require_once($CFG->dirroot . '/local/ulms_auth/lib.php');

/**
 * Handles secure manual and bulk user provisioning for ULMS admin users.
 */
class user_provisioning_service {
    /** @var string */
    private const PENDING_IMPORT_SESSION_KEY = 'local_ulms_dashboard_pending_imports';
    /** @var string */
    private const REPORT_SESSION_KEY = 'local_ulms_dashboard_provisioning_reports';

    /**
     * Returns target role labels available to the current admin.
     *
     * @return array
     */
    public function get_available_target_roles(): array {
        $roles = [
            'student' => get_string('provisioningtargetstudent', 'local_ulms_dashboard'),
            'lecturer' => get_string('provisioningtargetlecturer', 'local_ulms_dashboard'),
        ];

        if ($this->can_create_admin_accounts()) {
            $roles['admin'] = get_string('provisioningtargetadmin', 'local_ulms_dashboard');
        }

        return $roles;
    }

    /**
     * Returns whether the current user can create admin accounts.
     *
     * @return bool
     */
    public function can_create_admin_accounts(): bool {
        global $USER;

        return isloggedin() && !isguestuser() && is_siteadmin($USER);
    }

    /**
     * Returns starter rows for a CSV template.
     *
     * @param string $targetrole
     * @return array
     */
    public function get_csv_template_rows(string $targetrole): array {
        $this->assert_target_role_access($targetrole);

        $samples = [
            'student' => ['Ada', 'Okafor', 'ada.okafor@student.example.edu', 'ada.okafor', 'STU-001', 'BCHEM-2024'],
            'lecturer' => ['Kemi', 'Adebayo', 'kemi.adebayo@lecturer.example.edu', 'kemi.adebayo', 'LEC-001', ''],
            'admin' => ['Ibrahim', 'Musa', 'ibrahim.musa@admin.example.edu', 'ibrahim.musa', 'ADM-001', ''],
        ];

        return [
            ['firstname', 'lastname', 'email', 'username', 'idnumber', 'programmecode'],
            $samples[$targetrole] ?? $samples['student'],
        ];
    }

    /**
     * Parses an uploaded CSV file into associative rows.
     *
     * @param string $filepath
     * @param array $errors
     * @return array
     */
    public function parse_csv_upload(string $filepath, array &$errors): array {
        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            $errors[] = get_string('provisioningcsvopenerror', 'local_ulms_dashboard');
            return [];
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $errors[] = get_string('provisioningcsvopenerror', 'local_ulms_dashboard');
            return [];
        }

        $headers = array_map(
            static fn(string $header): string => \core_text::strtolower(trim($header)),
            $headers
        );

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
            if (implode('', array_map('trim', $row)) === '') {
                continue;
            }

            $combined = array_combine($headers, $row);
            if ($combined === false) {
                $errors[] = get_string('provisioningcsvstructureerror', 'local_ulms_dashboard');
                break;
            }

            $rows[] = $combined;
        }

        fclose($handle);
        return $rows;
    }

    /**
     * Returns a preview of a bulk provisioning import.
     *
     * @param string $targetrole
     * @param array $rows
     * @return array
     */
    public function preview_bulk_import(string $targetrole, array $rows): array {
        $this->assert_target_role_access($targetrole);

        $result = [
            'processed' => 0,
            'valid' => 0,
            'invalid' => 0,
            'previewrows' => [],
            'validrows' => [],
            'reportrows' => [],
            'errors' => [],
        ];
        $seen = [
            'emails' => [],
            'usernames' => [],
            'idnumbers' => [],
        ];

        foreach ($rows as $index => $row) {
            $linenumber = $index + 2;
            $validation = $this->validate_row($row, $linenumber, $seen, $targetrole);

            $result['previewrows'][] = [
                'linenumber' => $linenumber,
                'firstname' => $validation['display']['firstname'],
                'lastname' => $validation['display']['lastname'],
                'email' => $validation['display']['email'],
                'username' => $validation['display']['username'],
                'idnumber' => $validation['display']['idnumber'],
                'action' => $validation['valid']
                    ? get_string('provisioningcsvactioncreate', 'local_ulms_dashboard')
                    : get_string('provisioningcsvactioninvalid', 'local_ulms_dashboard'),
                'message' => $validation['message'],
                'valid' => $validation['valid'],
            ];

            $result['reportrows'][] = [
                'line' => (string)$linenumber,
                'target_role' => $targetrole,
                'firstname' => $validation['display']['firstname'],
                'lastname' => $validation['display']['lastname'],
                'email' => $validation['display']['email'],
                'username' => $validation['display']['username'],
                'idnumber' => $validation['display']['idnumber'],
                'status' => $validation['valid'] ? 'valid' : 'invalid',
                'message' => $validation['message'],
            ];

            if ($validation['valid']) {
                $result['valid']++;
                $result['validrows'][] = $validation['record'];
            } else {
                $result['invalid']++;
                $result['errors'] = array_merge($result['errors'], $validation['errors']);
            }

            $result['processed']++;
        }

        return $result;
    }

    /**
     * Creates a single user account from admin-submitted data.
     *
     * @param string $targetrole
     * @param array $data
     * @return array
     */
    public function create_single_user(string $targetrole, array $data): array {
        $this->assert_target_role_access($targetrole);

        $seen = [
            'emails' => [],
            'usernames' => [],
            'idnumbers' => [],
        ];
        $validation = $this->validate_row($data, 1, $seen, $targetrole);

        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
                'message' => $validation['message'],
            ];
        }

        $record = $validation['record'];
        $record['middlename'] = trim((string)($data['middlename'] ?? ''));
        $record['password'] = (string)($data['password'] ?? '');
        $record['institution'] = trim((string)($data['institution'] ?? ''));
        $record['department'] = trim((string)($data['department'] ?? ''));

        return $this->create_user_account($record, $targetrole, 'single');
    }

    /**
     * Imports a validated bulk dataset and sends onboarding emails.
     *
     * @param string $targetrole
     * @param array $rows
     * @return array
     */
    public function import_bulk_rows(string $targetrole, array $rows): array {
        $this->assert_target_role_access($targetrole);

        $result = [
            'processed' => 0,
            'created' => 0,
            'emailfailed' => 0,
            'errors' => [],
            'reportrows' => [],
        ];

        foreach ($rows as $row) {
            $outcome = $this->create_user_account($row, $targetrole, 'bulk');
            $result['processed']++;

            $result['reportrows'][] = [
                'line' => (string)($row['linenumber'] ?? ''),
                'target_role' => $targetrole,
                'firstname' => (string)($row['firstname'] ?? ''),
                'lastname' => (string)($row['lastname'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'username' => (string)($row['username'] ?? ''),
                'idnumber' => (string)($row['idnumber'] ?? ''),
                'status' => $outcome['success']
                    ? ($outcome['emailsent'] ? 'created' : 'created_with_email_warning')
                    : 'failed',
                'message' => $outcome['message'],
            ];

            if ($outcome['success']) {
                $result['created']++;
                if (!$outcome['emailsent']) {
                    $result['emailfailed']++;
                    $result['errors'][] = $outcome['message'];
                }
            } else {
                $result['errors'][] = $outcome['message'];
            }
        }

        return $result;
    }

    /**
     * Stores a pending import in the current session.
     *
     * @param string $targetrole
     * @param array $rows
     * @return string
     */
    public function store_pending_import(string $targetrole, array $rows): string {
        global $SESSION;

        $token = bin2hex(random_bytes(16));
        if (!isset($SESSION->{self::PENDING_IMPORT_SESSION_KEY}) || !is_array($SESSION->{self::PENDING_IMPORT_SESSION_KEY})) {
            $SESSION->{self::PENDING_IMPORT_SESSION_KEY} = [];
        }

        $SESSION->{self::PENDING_IMPORT_SESSION_KEY}[$token] = [
            'targetrole' => $targetrole,
            'rows' => $rows,
            'timecreated' => time(),
        ];

        return $token;
    }

    /**
     * Returns a pending import by token.
     *
     * @param string $token
     * @return array|null
     */
    public function get_pending_import(string $token): ?array {
        global $SESSION;

        $imports = $SESSION->{self::PENDING_IMPORT_SESSION_KEY} ?? [];
        if (!is_array($imports) || empty($imports[$token])) {
            return null;
        }

        return $imports[$token];
    }

    /**
     * Removes a pending import from the session.
     *
     * @param string $token
     * @return void
     */
    public function clear_pending_import(string $token): void {
        global $SESSION;

        $imports = $SESSION->{self::PENDING_IMPORT_SESSION_KEY} ?? [];
        if (is_array($imports) && isset($imports[$token])) {
            unset($imports[$token]);
            $SESSION->{self::PENDING_IMPORT_SESSION_KEY} = $imports;
        }
    }

    /**
     * Stores a report dataset for CSV download.
     *
     * @param string $filename
     * @param array $rows
     * @return string|null
     */
    public function store_report(string $filename, array $rows): ?string {
        global $SESSION;

        if (empty($rows)) {
            return null;
        }

        $token = bin2hex(random_bytes(16));
        if (!isset($SESSION->{self::REPORT_SESSION_KEY}) || !is_array($SESSION->{self::REPORT_SESSION_KEY})) {
            $SESSION->{self::REPORT_SESSION_KEY} = [];
        }

        $SESSION->{self::REPORT_SESSION_KEY}[$token] = [
            'filename' => $filename,
            'rows' => $rows,
            'timecreated' => time(),
        ];

        return $token;
    }

    /**
     * Returns a stored report payload.
     *
     * @param string $token
     * @return array|null
     */
    public function get_report(string $token): ?array {
        global $SESSION;

        $reports = $SESSION->{self::REPORT_SESSION_KEY} ?? [];
        if (!is_array($reports) || empty($reports[$token])) {
            return null;
        }

        return $reports[$token];
    }

    /**
     * Returns recent provisioning activity for the admin workspace.
     *
     * @param int $limit
     * @return array<int, array<string, string>>
     */
    public function get_recent_activity(int $limit = 10): array {
        global $DB;

        $sql = "SELECT l.*,
                       actor.firstname AS actorfirstname,
                       actor.lastname AS actorlastname,
                       created.firstname AS createdfirstname,
                       created.lastname AS createdlastname
                  FROM {local_ulms_user_provisioning_log} l
             LEFT JOIN {user} actor ON actor.id = l.actorid
             LEFT JOIN {user} created ON created.id = l.createduserid
              ORDER BY l.timecreated DESC, l.id DESC";
        $records = $DB->get_records_sql($sql, [], 0, $limit);
        $items = [];

        foreach ($records as $record) {
            $createdname = trim((string)($record->createdfirstname ?? '') . ' ' . (string)($record->createdlastname ?? ''));
            $actorname = trim((string)($record->actorfirstname ?? '') . ' ' . (string)($record->actorlastname ?? ''));
            $items[] = [
                'label' => trim(($createdname !== '' ? $createdname : (string)$record->identifier) . ' - ' . get_string(
                    'provisioningtarget' . $record->targetrole,
                    'local_ulms_dashboard'
                )),
                'subtitle' => get_string('provisioningactivitymeta', 'local_ulms_dashboard', (object)[
                    'status' => (string)$record->status,
                    'mode' => (string)$record->createmode,
                    'actor' => $actorname !== '' ? $actorname : get_string('adminportaleyebrow', 'local_ulms_dashboard'),
                ]),
                'time' => userdate((int)$record->timecreated, get_string('strftimedatetimeshort')),
            ];
        }

        return $items;
    }

    /**
     * Validates a CSV or single-entry row.
     *
     * @param array $row
     * @param int $linenumber
     * @param array $seen
     * @return array
     */
    private function validate_row(array $row, int $linenumber, array &$seen, string $targetrole = ''): array {
        global $DB;

        $errors = [];
        $firstname = trim((string)($row['firstname'] ?? ''));
        $lastname = trim((string)($row['lastname'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));
        $username = trim((string)($row['username'] ?? ''));
        $idnumber = trim((string)($row['idnumber'] ?? ''));
        $programmecode = trim((string)($row['programmecode'] ?? ''));
        $programmeid = max(0, (int)($row['programmeid'] ?? 0));

        // Student programme requirement (FR-A3 / AC-A3).
        if ($targetrole === 'student') {
            if ($programmeid <= 0 && $programmecode === '') {
                $errors[] = get_string('provisioningerrorprogrammerequired', 'local_ulms_dashboard');
            } else {
                // Try lookup by code if only code set.
                if ($programmeid <= 0 && $programmecode !== '') {
                    $record = $DB->get_record('local_ulms_programmes', ['code' => $programmecode, 'status' => 'active'], 'id', IGNORE_MISSING);
                    if ($record) {
                        $programmeid = (int)$record->id;
                    } else {
                        $errors[] = get_string('provisioningerrorprogrammeinvalid', 'local_ulms_dashboard');
                        $programmeid = 0;
                    }
                } elseif ($programmeid > 0) {
                    $exists = (bool)$DB->record_exists('local_ulms_programmes', ['id' => $programmeid, 'status' => 'active']);
                    if (!$exists) {
                        $errors[] = get_string('provisioningerrorprogrammeinvalid', 'local_ulms_dashboard');
                        $programmeid = 0;
                    }
                }
            }
        }

        if ($firstname === '') {
            $errors[] = get_string('provisioningerrorrequiredfield', 'local_ulms_dashboard', 'firstname');
        }

        if ($lastname === '') {
            $errors[] = get_string('provisioningerrorrequiredfield', 'local_ulms_dashboard', 'lastname');
        }

        if ($email === '') {
            $errors[] = get_string('provisioningerrorrequiredfield', 'local_ulms_dashboard', 'email');
        } else if (!validate_email($email)) {
            $errors[] = get_string('provisioningerrorinvalidemail', 'local_ulms_dashboard', $email);
        } else if ($this->email_exists($email)) {
            $errors[] = get_string('provisioningerrorexistingemail', 'local_ulms_dashboard', $email);
        } else if (isset($seen['emails'][\core_text::strtolower($email)])) {
            $errors[] = get_string('provisioningerrorduplicateemail', 'local_ulms_dashboard', $email);
        }

        $resolvedusername = $this->resolve_username($username, $firstname, $lastname, $email, $seen['usernames'], $errors);

        if ($idnumber !== '') {
            if ($this->idnumber_exists($idnumber)) {
                $errors[] = get_string('provisioningerrorexistingidnumber', 'local_ulms_dashboard', $idnumber);
            } else if (isset($seen['idnumbers'][$idnumber])) {
                $errors[] = get_string('provisioningerrorduplicateidnumber', 'local_ulms_dashboard', $idnumber);
            }
        }

        if (!empty($errors)) {
            return [
                'valid' => false,
                'errors' => $errors,
                'message' => implode(' ', array_unique($errors)),
                'display' => [
                    'firstname' => $firstname,
                    'lastname' => $lastname,
                    'email' => $email,
                    'username' => $resolvedusername ?? $username,
                    'idnumber' => $idnumber,
                ],
            ];
        }

        $seen['emails'][\core_text::strtolower($email)] = true;
        $seen['usernames'][$resolvedusername] = true;
        if ($idnumber !== '') {
            $seen['idnumbers'][$idnumber] = true;
        }

        // Cascade facultyid/departmentid from programmeid when programme is set.
        $facultyid = 0;
        $departmentid = 0;
        if ($programmeid > 0) {
            $hierarchy = $DB->get_record_sql(
                "SELECT p.departmentid, d.facultyid
                   FROM {local_ulms_programmes} p
                   JOIN {local_ulms_departments} d ON d.id = p.departmentid
                  WHERE p.id = :pid",
                ['pid' => $programmeid]
            );
            if ($hierarchy) {
                $facultyid = max(0, (int)($hierarchy->facultyid ?? 0));
                $departmentid = max(0, (int)($hierarchy->departmentid ?? 0));
            }
        }
        $institutionlabel = $facultyid > 0 ? (string)($DB->get_field('local_ulms_faculties', 'name', ['id' => $facultyid], IGNORE_MISSING) ?? '') : '';
        $departmentlabel = $departmentid > 0 ? (string)($DB->get_field('local_ulms_departments', 'name', ['id' => $departmentid], IGNORE_MISSING) ?? '') : '';

        return [
            'valid' => true,
            'errors' => [],
            'message' => get_string('provisioningcsvvalidationpassed', 'local_ulms_dashboard'),
            'display' => [
                'firstname' => $firstname,
                'lastname' => $lastname,
                'email' => $email,
                'username' => $resolvedusername,
                'idnumber' => $idnumber,
            ],
            'record' => [
                'linenumber' => $linenumber,
                'firstname' => $firstname,
                'middlename' => trim((string)($row['middlename'] ?? '')),
                'lastname' => $lastname,
                'email' => $email,
                'username' => $resolvedusername,
                'idnumber' => $idnumber,
                'facultyid' => $facultyid,
                'departmentid' => $departmentid,
                'programmeid' => $programmeid,
                'institution' => $institutionlabel,
                'department' => $departmentlabel,
            ],
        ];
    }

    /**
     * Resolves a valid unique username.
     *
     * @param string $username
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     * @param array $seenusernames
     * @param array $errors
     * @return string|null
     */
    private function resolve_username(
        string $username,
        string $firstname,
        string $lastname,
        string $email,
        array $seenusernames,
        array &$errors
    ): ?string {
        $explicit = $username !== '';
        $candidate = \core_text::strtolower($explicit ? $username : $this->build_default_username($firstname, $lastname, $email));
        $candidate = \core_user::clean_field($candidate, 'username');

        if ($candidate === '') {
            $errors[] = get_string('provisioningerrorusernamegeneration', 'local_ulms_dashboard');
            return null;
        }

        if ($explicit) {
            if ($candidate !== \core_text::strtolower($username)) {
                $errors[] = get_string('provisioningerrorinvalidusername', 'local_ulms_dashboard', $username);
                return null;
            }

            if ($this->username_exists($candidate)) {
                $errors[] = get_string('provisioningerrorexistingusername', 'local_ulms_dashboard', $candidate);
                return null;
            }

            if (isset($seenusernames[$candidate])) {
                $errors[] = get_string('provisioningerrorduplicateusername', 'local_ulms_dashboard', $candidate);
                return null;
            }

            return $candidate;
        }

        $base = $candidate;
        $suffix = 1;
        while ($this->username_exists($candidate) || isset($seenusernames[$candidate])) {
            $suffixtext = (string)$suffix;
            $maxbaselength = max(1, 100 - strlen($suffixtext));
            $candidate = substr($base, 0, $maxbaselength) . $suffixtext;
            $suffix++;

            if ($suffix > 1000) {
                $errors[] = get_string('provisioningerrorusernamegeneration', 'local_ulms_dashboard');
                return null;
            }
        }

        return $candidate;
    }

    /**
     * Builds a default username from row data.
     *
     * @param string $firstname
     * @param string $lastname
     * @param string $email
     * @return string
     */
    private function build_default_username(string $firstname, string $lastname, string $email): string {
        $localpart = trim((string)strtok($email, '@'));
        if ($localpart !== '') {
            return $localpart;
        }

        return trim($firstname . '.' . $lastname, '.');
    }

    /**
     * Creates a user account, role assignment, reset token, and onboarding email.
     *
     * @param array $record
     * @param string $targetrole
     * @param string $createmode
     * @return array
     */
    private function create_user_account(array $record, string $targetrole, string $createmode): array {
        global $DB, $CFG, $USER;

        $roleconfig = $this->get_role_config($targetrole);
        $password = trim((string)($record['password'] ?? ''));
        if ($password === '') {
            $password = generate_password(16);
        }
        $systemcontext = \context_system::instance();
        $userrecord = (object)[
            'auth' => 'manual',
            'confirmed' => 1,
            'suspended' => 0,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $record['username'],
            'password' => $password,
            'firstname' => $record['firstname'],
            'middlename' => $record['middlename'] ?? '',
            'lastname' => $record['lastname'],
            'email' => $record['email'],
            'idnumber' => $record['idnumber'],
            'institution' => $record['institution'] ?? '',
            'department' => $record['department'] ?? '',
        ];
        $userid = 0;
        $newuser = null;
        $activationlink = '';

        try {
            $transaction = $DB->start_delegated_transaction();
            $userid = user_create_user($userrecord, true, true);
            role_assign($roleconfig['roleid'], $userid, $systemcontext);
            $newuser = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
            set_user_preference('auth_forcepasswordchange', 1, $newuser);
            set_user_preference('create_password', 1, $newuser);
            // Save ULMS user profile for academic metadata when available.
            if (!empty($record['programmeid']) || !empty($record['facultyid']) || !empty($record['departmentid'])) {
                $existing = $DB->get_record('local_ulms_user_profile', ['userid' => $userid]);
                $timestamp = time();
                $payload = (object)[
                    'userid' => $userid,
                    'facultyid' => max(0, (int)($record['facultyid'] ?? 0)),
                    'departmentid' => max(0, (int)($record['departmentid'] ?? 0)),
                    'programmeid' => max(0, (int)($record['programmeid'] ?? 0)),
                    'studylevel' => (string)($record['studylevel'] ?? ''),
                    'staffid' => (string)($record['staffid'] ?? ''),
                ];
                if ($existing) {
                    $payload->id = (int)$existing->id;
                    $payload->timecreated = (int)$existing->timecreated;
                    $payload->timemodified = $timestamp;
                    $DB->update_record('local_ulms_user_profile', $payload);
                } else {
                    $payload->timecreated = $timestamp;
                    $payload->timemodified = $timestamp;
                    $payload->id = $DB->insert_record('local_ulms_user_profile', $payload);
                }
            }
            if ($targetrole === 'student' && !empty($record['programmeid']) && (int)$record['programmeid'] > 0) {
                try {
                    if (function_exists('local_ulms_academics_enrol_user_into_programme_courses')) {
                        local_ulms_academics_enrol_user_into_programme_courses($userid, (int)$record['programmeid']);
                    }
                } catch (\Throwable $enrolex) {
                    if (function_exists('local_ulms_dashboard_log_operational_error')) {
                        local_ulms_dashboard_log_operational_error($enrolex, 'user_provisioning_service::create_user::enrol_programme', [
                            'userid' => $userid,
                            'programmeid' => (int)($record['programmeid'] ?? 0),
                            'role' => $targetrole,
                        ]);
                    }
                }
            }
            $resetrecord = local_ulms_auth_issue_password_token($newuser);
            $transaction->allow_commit();

            if (!$resetrecord) {
                throw new \moodle_exception('provisioningcreateunexpectederror', 'local_ulms_dashboard');
            }

            $activationlink = local_ulms_auth_get_activation_url($resetrecord->token)->out(false);
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning_service::create_single_user', []); }
            if (isset($transaction)) {
                $transaction->rollback($exception);
            }

            $this->safe_log_provisioning_event(
                (int)$USER->id,
                0,
                $targetrole,
                $createmode,
                (string)($record['username'] ?? $record['email'] ?? ''),
                'failed',
                get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard'),
                [
                    'email' => $record['email'] ?? '',
                    'line' => $record['linenumber'] ?? null,
                    'exception' => $exception->getMessage(),
                ]
            );

            return [
                'success' => false,
                'emailsent' => false,
                'message' => get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard'),
                'errors' => [get_string('provisioningcreateunexpectederror', 'local_ulms_dashboard')],
            ];
        }

        $emailsent = false;
        $emailerror = '';
        try {
            $emailsent = $this->send_account_email($newuser, $targetrole, $activationlink);
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning_service::send_welcome_email', []); }
            $emailsent = false;
            $emailerror = $exception->getMessage();
        }

        $message = $emailsent
            ? get_string('provisioningcreatesuccess', 'local_ulms_dashboard', $newuser->username)
            : get_string('provisioningcreateemailwarning', 'local_ulms_dashboard', $newuser->username);

        $this->safe_log_provisioning_event(
            (int)$USER->id,
            $userid,
            $targetrole,
            $createmode,
            $newuser->username,
            $emailsent ? 'created' : 'created_with_email_warning',
            $message,
            [
                'email' => $newuser->email,
                'line' => $record['linenumber'] ?? null,
                'activationlink' => $activationlink,
                'emailerror' => $emailerror,
            ]
        );

        return [
            'success' => true,
            'emailsent' => $emailsent,
            'userid' => $userid,
            'username' => $newuser->username,
            'message' => $message,
        ];
    }

    /**
     * Sends account details and activation link to the new user.
     *
     * @param \stdClass $user
     * @param string $targetrole
     * @param string $activationlink
     * @return bool
     */
    private function send_account_email(\stdClass $user, string $targetrole, string $activationlink): bool {
        global $CFG;

        $site = get_site();
        $data = (object)[
            'firstname' => fullname($user),
            'rolelabel' => get_string('provisioningtarget' . $targetrole, 'local_ulms_dashboard'),
            'username' => $user->username,
            'activationlink' => $activationlink,
            'signinurl' => local_ulms_auth_get_unified_sign_in_url()->out(false),
            'sitename' => format_string($site->fullname),
            'supportsignature' => generate_email_signoff(),
        ];

        $subject = get_string('provisioningaccountemailsubject', 'local_ulms_dashboard', format_string($site->fullname));
        $messagetext = get_string('provisioningaccountemailbody', 'local_ulms_dashboard', $data);
        $messagehtml = text_to_html($messagetext, false, false, true);
        $idempotencykey = sha1(implode('|', [
            'ulms_account_provisioning',
            (string)$user->id,
            $targetrole,
            (string)$activationlink,
        ]));

        if (($CFG->ulmsmailtransport ?? 'moodle') === 'resend') {
            $replyto = !empty($CFG->ulmsreplyto) ? (string)$CFG->ulmsreplyto : (string)($CFG->supportemail ?? '');
            $replytoname = !empty($CFG->supportname) ? (string)$CFG->supportname : format_string($site->shortname);
            $result = $this->create_mail_service()->send_transactional_email([
                'to' => [[
                    'email' => (string)$user->email,
                    'name' => fullname($user),
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

            return !empty($result['success']);
        }

        return local_ulms_auth_send_transactional_email(
            $user,
            $subject,
            $messagetext,
            $messagehtml,
            $idempotencykey
        );
    }

    /**
     * Creates the ULMS mail service.
     *
     * @return \local_ulms_mail\local\service\resend_mail_service
     */
    protected function create_mail_service(): \local_ulms_mail\local\service\resend_mail_service {
        return new \local_ulms_mail\local\service\resend_mail_service();
    }

    /**
     * Returns the role configuration for a supported target role.
     *
     * @param string $targetrole
     * @return array
     */
    private function get_role_config(string $targetrole): array {
        global $DB;

        $configs = [
            'student' => [
                'shortnames' => ['student'],
                'label' => get_string('provisioningtargetstudent', 'local_ulms_dashboard'),
            ],
            'lecturer' => [
                'shortnames' => ['editingteacher', 'teacher'],
                'label' => get_string('provisioningtargetlecturer', 'local_ulms_dashboard'),
            ],
            'admin' => [
                'shortnames' => ['manager'],
                'label' => get_string('provisioningtargetadmin', 'local_ulms_dashboard'),
            ],
        ];

        if (empty($configs[$targetrole])) {
            throw new \moodle_exception('invaliddata');
        }

        foreach ($configs[$targetrole]['shortnames'] as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id,shortname', IGNORE_MISSING);
            if ($role) {
                $configs[$targetrole]['roleid'] = (int)$role->id;
                $configs[$targetrole]['roleshortname'] = (string)$role->shortname;
                return $configs[$targetrole];
            }
        }

        throw new \moodle_exception('invaliddata');
    }

    /**
     * Ensures the current admin may create the requested target role.
     *
     * @param string $targetrole
     * @return void
     */
    private function assert_target_role_access(string $targetrole): void {
        $allowed = array_keys($this->get_available_target_roles());
        if (!in_array($targetrole, $allowed, true)) {
            throw new \required_capability_exception(
                \context::instance_by_id(\context_system::instance()->id),
                'local/ulms_dashboard:viewadmindashboard',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Returns whether a username already exists.
     *
     * @param string $username
     * @return bool
     */
    private function username_exists(string $username): bool {
        global $CFG, $DB;

        return $DB->record_exists('user', [
            'username' => $username,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
    }

    /**
     * Returns whether an email already exists.
     *
     * @param string $email
     * @return bool
     */
    private function email_exists(string $email): bool {
        global $CFG, $DB;

        $sql = "SELECT 1
                  FROM {user}
                 WHERE mnethostid = :mnethostid
                   AND deleted = 0
                   AND " . $DB->sql_equal('email', ':email', false, true);

        return $DB->record_exists_sql($sql, [
            'mnethostid' => $CFG->mnet_localhost_id,
            'email' => $email,
        ]);
    }

    /**
     * Returns whether an idnumber already exists.
     *
     * @param string $idnumber
     * @return bool
     */
    private function idnumber_exists(string $idnumber): bool {
        global $DB;

        return $idnumber !== '' && $DB->record_exists('user', ['idnumber' => $idnumber, 'deleted' => 0]);
    }

    /**
     * @deprecated since Moodle 4.05.12+ 2026-09-08 Use local_ulms_dashboard_scrub_sensitive_details() directly.
     * @see \local_ulms_dashboard_scrub_sensitive_details()
     */
    public static function scrub_sensitive_details(mixed $data): mixed {
        return \local_ulms_dashboard_scrub_sensitive_details($data);
    }

    private function log_provisioning_event(
        int $actorid,
        int $createduserid,
        string $targetrole,
        string $createmode,
        string $identifier,
        string $status,
        string $message,
        array $details = []
    ): void {
        global $DB;

        $detailsscrubbed = self::scrub_sensitive_details($details);

        $DB->insert_record('local_ulms_user_provisioning_log', (object)[
            'actorid' => $actorid,
            'createduserid' => $createduserid,
            'targetrole' => $targetrole,
            'createmode' => $createmode,
            'identifier' => substr($identifier, 0, 255),
            'status' => substr($status, 0, 32),
            'message' => $message,
            'detailsjson' => json_encode($detailsscrubbed),
            'timecreated' => time(),
        ]);
    }

    /**
     * Logs provisioning activity without breaking the user-facing flow.
     *
     * @param int $actorid
     * @param int $createduserid
     * @param string $targetrole
     * @param string $createmode
     * @param string $identifier
     * @param string $status
     * @param string $message
     * @param array $details
     * @return void
     */
    private function safe_log_provisioning_event(
        int $actorid,
        int $createduserid,
        string $targetrole,
        string $createmode,
        string $identifier,
        string $status,
        string $message,
        array $details = []
    ): void {
        try {
            $this->log_provisioning_event(
                $actorid,
                $createduserid,
                $targetrole,
                $createmode,
                $identifier,
                $status,
                $message,
                $details
            );
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'user_provisioning_service::store_pending_cleanup', []); }
            debugging('ULMS provisioning audit logging failed.', DEBUG_DEVELOPER);
        }
    }
}
