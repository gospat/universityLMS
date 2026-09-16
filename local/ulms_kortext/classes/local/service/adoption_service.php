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

namespace local_ulms_kortext\local\service;

defined('MOODLE_INTERNAL') || die();

/**
 * CRUD service for adoption records + CSV import/export + ISBN validation.
 *
 * @package local_ulms_kortext
 */
class adoption_service {

    /**
     * Validates ISBN-10 or ISBN-13 strings (with optional hyphens or X for ISBN-10 check digit).
     *
     * @param string $isbn
     * @return bool
     */
    public function validate_isbn(string $isbn): bool {
        $clean = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
        $len = strlen($clean);
        if ($len !== 10 && $len !== 13) {
            return false;
        }
        $clean = strtoupper($clean);
        if ($len === 10) {
            $sum = 0;
            for ($i = 0; $i < 9; $i++) {
                $digit = (int)$clean[$i];
                $sum += $digit * (10 - $i);
            }
            $tenth = $clean[9];
            $check = $tenth === 'X' ? 10 : (int)$tenth;
            $sum += $check;
            return ($sum % 11) === 0;
        }
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $digit = (int)$clean[$i];
            $sum += $i % 2 === 0 ? $digit : ($digit * 3);
        }
        return ($sum % 10) === 0;
    }

    /**
     * Returns a human-readable ISBN-validation error label, or empty string if valid.
     *
     * @param string $isbn
     * @return string
     */
    public function describe_isbn_error(string $isbn): string {
        $clean = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
        $len = strlen($clean);
        if ($len !== 10 && $len !== 13) {
            return get_string('isbn_invalid_tooshort', 'local_ulms_kortext');
        }
        if (preg_match('/[^0-9X]/', strtoupper($clean)) === 1) {
            return get_string('isbn_invalid_chars', 'local_ulms_kortext');
        }
        if (!$this->validate_isbn($isbn)) {
            return get_string('isbn_invalid_checksum', 'local_ulms_kortext');
        }
        return '';
    }

    /**
     * Creates a new adoption and returns the new record id.
     *
     * @param array<string, mixed> $data Required keys: programmeid, moodlecourseid, semesterid, isbn, adopted_by
     * @param int $actor_userid Calling user id (used for event logging)
     * @return int New adoption id
     */
    public function create_adoption(array $data, int $actor_userid): int {
        global $DB, $USER;
        if (empty($data['isbn']) || !$this->validate_isbn((string)$data['isbn'])) {
            throw new \moodle_exception(
                'isbn_invalid',
                'local_ulms_kortext',
                '',
                (object)['a' => $this->describe_isbn_error((string)($data['isbn'] ?? ''))]
            );
        }
        $levelid = isset($data['levelid']) ? (int)$data['levelid'] : 0;
        $sessionid = isset($data['sessionid']) ? (int)$data['sessionid'] : 0;
        if ($levelid > 0 && !$DB->record_exists('local_ulms_levels', ['id' => $levelid])) {
            throw new \invalid_parameter_exception(get_string('field_level_invalid', 'local_ulms_kortext'));
        }
        if ($sessionid > 0 && !$DB->record_exists('local_ulms_sessions', ['id' => $sessionid])) {
            throw new \invalid_parameter_exception(get_string('field_session_invalid', 'local_ulms_kortext'));
        }
        $now = time();
        $record = (object)[
            'programmeid'    => (int)$data['programmeid'],
            'moodlecourseid' => (int)$data['moodlecourseid'],
            'semesterid'     => (int)$data['semesterid'],
            'levelid'        => $levelid,
            'sessionid'      => $sessionid,
            'isbn'           => (string)$data['isbn'],
            'ebook_id'       => isset($data['ebook_id']) ? (string)$data['ebook_id'] : null,
            'deeplink_url'   => isset($data['deeplink_url']) ? (string)$data['deeplink_url'] : null,
            'adopted_by'     => !empty($data['adopted_by']) ? (int)$data['adopted_by'] : ($USER->id ?? $actor_userid),
            'status'         => !empty($data['status']) ? (string)$data['status'] : 'active',
            'timecreated'    => $now,
            'timemodified'   => $now,
        ];
        try {
            $newid = $DB->insert_record('local_ulms_kortext_adoptions', $record);
        } catch (\dml_exception $e) {
            if (stripos($e->getMessage(), 'unique') !== false || stripos($e->getMessage(), 'duplicate') !== false) {
                throw new \moodle_exception(get_string('adoption_exists', 'local_ulms_kortext'));
            }
            throw $e;
        }
        if (class_exists('\local_ulms_kortext\event\adoption_created')) {
            /** @var mixed $ctx */
            $ctx = \context_system::instance();
            $event = \local_ulms_kortext\event\adoption_created::create([
                'objectid' => $newid,
                'context'  => $ctx,
                'userid'   => $actor_userid,
                'other'    => [
                    'programmeid'    => $record->programmeid,
                    'moodlecourseid' => $record->moodlecourseid,
                    'semesterid'     => $record->semesterid,
                    'levelid'        => $record->levelid,
                    'sessionid'      => $record->sessionid,
                    'isbn'           => $record->isbn,
                ],
            ]);
            $event->trigger();
        }
        return (int)$newid;
    }

    /**
     * Updates an existing adoption.
     *
     * @param int $id
     * @param array<string, mixed> $data Partial record
     * @param int $actor_userid
     * @return void
     */
    public function update_adoption(int $id, array $data, int $actor_userid): void {
        global $DB;
        $existing = $DB->get_record('local_ulms_kortext_adoptions', ['id' => $id], '*', MUST_EXIST);
        $changed = false;
        foreach (['programmeid', 'moodlecourseid', 'semesterid', 'levelid', 'sessionid', 'isbn', 'ebook_id', 'deeplink_url', 'status'] as $_key) {
            if (!array_key_exists($_key, $data)) {
                continue;
            }
            $newval = $data[$_key];
            if ($_key === 'isbn' && !$this->validate_isbn((string)$newval)) {
                throw new \invalid_parameter_exception(get_string('isbn_invalid', 'local_ulms_kortext', $this->describe_isbn_error((string)$newval)));
            }
            if ($_key === 'levelid') {
                $lv = (int)$newval;
                if ($lv > 0 && !$DB->record_exists('local_ulms_levels', ['id' => $lv])) {
                    throw new \invalid_parameter_exception(get_string('field_level_invalid', 'local_ulms_kortext'));
                }
            }
            if ($_key === 'sessionid') {
                $sv = (int)$newval;
                if ($sv > 0 && !$DB->record_exists('local_ulms_sessions', ['id' => $sv])) {
                    throw new \invalid_parameter_exception(get_string('field_session_invalid', 'local_ulms_kortext'));
                }
            }
            $existing->{$_key} = in_array($_key, ['programmeid', 'moodlecourseid', 'semesterid', 'levelid', 'sessionid'], true)
                ? (int)$newval
                : (is_scalar($newval) || $newval === null ? $newval : $existing->{$_key});
            $changed = true;
        }
        if ($changed) {
            $existing->timemodified = time();
            $DB->update_record('local_ulms_kortext_adoptions', $existing);
            if (class_exists('\local_ulms_kortext\event\adoption_updated')) {
                /** @var mixed $ctx */
                $ctx = \context_system::instance();
                $event = \local_ulms_kortext\event\adoption_updated::create([
                    'objectid' => $id,
                    'context'  => $ctx,
                    'userid'   => $actor_userid,
                    'other'    => ['isbn' => $existing->isbn],
                ]);
                $event->trigger();
            }
        }
    }

    /**
     * Soft-archives an adoption (status = archived; existing entitlements remain).
     *
     * @param int $id
     * @param int $actor_userid
     * @return void
     */
    public function archive_adoption(int $id, int $actor_userid): void {
        global $DB;
        $existing = $DB->get_record('local_ulms_kortext_adoptions', ['id' => $id], '*', MUST_EXIST);
        $existing->status = 'archived';
        $existing->timemodified = time();
        $DB->update_record('local_ulms_kortext_adoptions', $existing);
        if (class_exists('\local_ulms_kortext\event\adoption_archived')) {
            /** @var mixed $ctx */
            $ctx = \context_system::instance();
            $event = \local_ulms_kortext\event\adoption_archived::create([
                'objectid' => $id,
                'context'  => $ctx,
                'userid'   => $actor_userid,
                'other'    => ['isbn' => $existing->isbn],
            ]);
            $event->trigger();
        }
    }

    /**
     * Lists adoptions with optional filters + pagination.
     *
     * @param array<string, mixed> $filters programmeid, departmentid, collegeid, semesterid, status, q (search)
     * @param int $page
     * @param int $perpage
     * @return array{items: array<int, object>, total: int}
     */
    public function list_adoptions(array $filters, int $page = 0, int $perpage = 50): array {
        global $DB;
        [$where, $params] = $this->build_list_where($filters);
        $total = (int)$DB->count_records_sql(
            'SELECT COUNT(1) FROM {local_ulms_kortext_adoptions} a ' .
            'LEFT JOIN {local_ulms_programmes} p ON p.id = a.programmeid ' .
            'LEFT JOIN {local_ulms_levels} lv ON lv.id = a.levelid ' .
            'LEFT JOIN {local_ulms_semesters} s ON s.id = a.semesterid ' .
            'LEFT JOIN {local_ulms_sessions} sess ON sess.id = COALESCE(s.sessionid, a.sessionid, 0) ' .
            $where,
            $params
        );
        $order = 'a.timemodified DESC, a.id DESC';
        $fields = 'a.*, p.code AS programmecode, p.name AS programmename, ' .
                  'lv.code AS levelcode, lv.name AS levelname, ' .
                  's.code AS semestercode, s.name AS semestername, s.sessionid AS semestersessionid, ' .
                  'sess.code AS sessioncode, sess.name AS sessionname';
        $records = $DB->get_records_sql(
            "SELECT {$fields} FROM {local_ulms_kortext_adoptions} a " .
            "LEFT JOIN {local_ulms_programmes} p ON p.id = a.programmeid " .
            "LEFT JOIN {local_ulms_levels} lv ON lv.id = a.levelid " .
            "LEFT JOIN {local_ulms_semesters} s ON s.id = a.semesterid " .
            "LEFT JOIN {local_ulms_sessions} sess ON sess.id = COALESCE(s.sessionid, a.sessionid, 0) " .
            $where . " ORDER BY " . $order,
            $params,
            $page * $perpage,
            $perpage
        );
        return [
            'items' => array_values($records),
            'total' => $total,
        ];
    }

    /**
     * Builds the WHERE clause + parameters for list_adoptions + CSV export.
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function build_list_where(array $filters): array {
        global $DB;
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['programmeid'])) {
            $where[] = 'a.programmeid = :fpid';
            $params['fpid'] = (int)$filters['programmeid'];
        }
        if (!empty($filters['semesterid'])) {
            $where[] = 'a.semesterid = :fsid';
            $params['fsid'] = (int)$filters['semesterid'];
        }
        if (array_key_exists('levelid', $filters) && $filters['levelid'] !== '' && $filters['levelid'] !== null && (int)$filters['levelid'] > 0) {
            $where[] = 'a.levelid = :flvid';
            $params['flvid'] = (int)$filters['levelid'];
        }
        if (array_key_exists('sessionid', $filters) && $filters['sessionid'] !== '' && $filters['sessionid'] !== null && (int)$filters['sessionid'] > 0) {
            $where[] = '(s.sessionid = :fsessid OR a.sessionid = :fsessid2)';
            $params['fsessid'] = (int)$filters['sessionid'];
            $params['fsessid2'] = (int)$filters['sessionid'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = :fstatus';
            $params['fstatus'] = (string)$filters['status'];
        }
        if (!empty($filters['moodlecourseid'])) {
            $where[] = 'a.moodlecourseid = :fcid';
            $params['fcid'] = (int)$filters['moodlecourseid'];
        }
        if (!empty($filters['q'])) {
            $like = $DB->sql_like('a.isbn', ':fqisbn', false, false, false);
            $like2 = $DB->sql_like('p.code', ':fqpcode', false, false, false);
            $like3 = $DB->sql_like('p.name', ':fqpname', false, false, false);
            $where[] = "({$like} OR {$like2} OR {$like3})";
            $q = '%' . $DB->sql_like_escape((string)$filters['q']) . '%';
            $params['fqisbn'] = $q;
            $params['fqpcode'] = $q;
            $params['fqpname'] = $q;
        }
        if (!empty($filters['departmentid'])) {
            $where[] = 'p.departmentid = :fdeptid';
            $params['fdeptid'] = (int)$filters['departmentid'];
        }
        return [
            'WHERE ' . implode(' AND ', $where),
            $params,
        ];
    }

    /**
     * Imports adoptions from CSV rows; supports dry-run.
     *
     * @param array<int, array<string, string>> $rows Each row is assoc header → value; required: programmecode, courseid, semestercode, isbn
     * @param bool $dryrun
     * @param int $actor_userid
     * @return array{created: int, skipped: int, errors: array<int, array{row:int, message:string, data:array<string,string>}>}
     */
    public function csv_import(array $rows, bool $dryrun, int $actor_userid): array {
        global $DB;
        $created = 0;
        $skipped = 0;
        $errors = [];
        foreach ($rows as $rownum => $_row) {
            $pc = trim((string)($_row['programmecode'] ?? ''));
            $cid = trim((string)($_row['courseid'] ?? ''));
            $sc = trim((string)($_row['semestercode'] ?? ''));
            $isbn = trim((string)($_row['isbn'] ?? ''));
            if ($pc === '' || $cid === '' || $sc === '' || $isbn === '') {
                $errors[] = ['row' => $rownum + 1, 'message' => get_string('csv_col_headers_required', 'local_ulms_kortext'), 'data' => $_row];
                continue;
            }
            if (!$this->validate_isbn($isbn)) {
                $errors[] = ['row' => $rownum + 1, 'message' => get_string('isbn_invalid', 'local_ulms_kortext', $this->describe_isbn_error($isbn)), 'data' => $_row];
                continue;
            }
            $prog = $DB->get_record('local_ulms_programmes', ['code' => $pc], 'id');
            $sem = $DB->get_record('local_ulms_semesters', ['code' => $sc], 'id');
            $course = $DB->get_record('course', ['idnumber' => (string)$cid], 'id');
            if (!$course && ctype_digit($cid)) {
                $course = $DB->get_record('course', ['id' => (int)$cid], 'id');
            }
            if (!$prog || !$sem || !$course) {
                $missing = [];
                if (!$prog) { $missing[] = 'programmecode=' . $pc; }
                if (!$sem)  { $missing[] = 'semestercode=' . $sc; }
                if (!$course) { $missing[] = 'courseid=' . $cid; }
                $errors[] = ['row' => $rownum + 1, 'message' => 'Lookup failed: ' . implode(', ', $missing), 'data' => $_row];
                continue;
            }
            $exists = $DB->record_exists('local_ulms_kortext_adoptions', [
                'programmeid'    => (int)$prog->id,
                'moodlecourseid' => (int)$course->id,
                'semesterid'     => (int)$sem->id,
                'isbn'           => $isbn,
            ]);
            if ($exists) {
                $skipped++;
                continue;
            }
            if ($dryrun) {
                $created++;
                continue;
            }
            try {
                $this->create_adoption([
                    'programmeid'    => (int)$prog->id,
                    'moodlecourseid' => (int)$course->id,
                    'semesterid'     => (int)$sem->id,
                    'isbn'           => $isbn,
                    'ebook_id'       => trim((string)($_row['ebook_id'] ?? '')),
                    'adopted_by'     => $actor_userid,
                ], $actor_userid);
                $created++;
            } catch (\Throwable) {
                $errors[] = ['row' => $rownum + 1, 'message' => get_string('adoption_exists', 'local_ulms_kortext'), 'data' => $_row];
            }
        }
        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Builds a CSV export stream (in-memory string of rows) from filters.
     *
     * @param array<string, mixed> $filters
     * @return string
     */
    public function csv_export(array $filters): string {
        global $DB;
        [$where, $params] = $this->build_list_where($filters);
        $sql = "SELECT a.*, p.code AS programmecode, c.shortname AS courseshort, c.fullname AS coursefull, " .
            "s.code AS semestercode, lv.code AS levelcode, sess.code AS sessioncode, u.username AS adopted_by_username " .
            "FROM {local_ulms_kortext_adoptions} a " .
            "LEFT JOIN {local_ulms_programmes} p ON p.id = a.programmeid " .
            "LEFT JOIN {local_ulms_semesters} s ON s.id = a.semesterid " .
            "LEFT JOIN {local_ulms_levels} lv ON lv.id = a.levelid " .
            "LEFT JOIN {local_ulms_sessions} sess ON sess.id = COALESCE(s.sessionid, a.sessionid, 0) " .
            "LEFT JOIN {course} c ON c.id = a.moodlecourseid " .
            "LEFT JOIN {user} u ON u.id = a.adopted_by " .
            $where . " ORDER BY a.timemodified DESC";
        $rs = $DB->get_recordset_sql($sql, $params);
        $out = fopen('php://memory', 'rwb');
        if ($out === false) {
            return '';
        }
        fputcsv($out, [
            get_string('csvcol_programmecode', 'local_ulms_kortext'),
            get_string('csvcol_courseid', 'local_ulms_kortext'),
            get_string('csvcol_sessioncode', 'local_ulms_kortext'),
            get_string('csvcol_semestercode', 'local_ulms_kortext'),
            get_string('csvcol_levelcode', 'local_ulms_kortext'),
            get_string('csvcol_isbn', 'local_ulms_kortext'),
            get_string('csvcol_ebookid', 'local_ulms_kortext'),
            get_string('csvcol_status', 'local_ulms_kortext'),
            get_string('csvcol_adoptedby', 'local_ulms_kortext'),
            get_string('csvcol_createddate', 'local_ulms_kortext'),
        ]);
        foreach ($rs as $_r) {
            fputcsv($out, [
                (string)($_r->programmecode ?? ''),
                (string)($_r->courseshort ?? $_r->coursefull ?? $_r->moodlecourseid),
                (string)($_r->sessioncode ?? ''),
                (string)($_r->semestercode ?? ''),
                (string)($_r->levelcode ?? ''),
                (string)$_r->isbn,
                (string)($_r->ebook_id ?? ''),
                (string)$_r->status,
                (string)($_r->adopted_by_username ?? ''),
                !empty($_r->timecreated) ? date('c', (int)$_r->timecreated) : '',
            ]);
        }
        $rs->close();
        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);
        return $csv;
    }

    /**
     * KPI summary cards used on the Admin adoption UI.
     *
     * @return array<int, array{label: string, value: string, description: string}>
     */
    public function kpi_summary(): array {
        global $DB;
        $adopted_active = (int)$DB->count_records('local_ulms_kortext_adoptions', ['status' => 'active']);
        $programmes_active = (int)$DB->count_records_sql(
            'SELECT COUNT(DISTINCT programmeid) FROM {local_ulms_kortext_adoptions} WHERE status = :s',
            ['s' => 'active']
        );
        $dayago = time() - 86400;
        $entitled_24h = (int)$DB->count_records_select(
            'local_ulms_kortext_entitlement_log',
            "status = 'granted' AND timecreated >= :ts",
            ['ts' => $dayago]
        );
        try {
            $adapter = adapter\kortext_adapter_factory::get_instance();
            $h = $adapter->health();
            $health_value = $h['ok']
                ? get_string('health_ok', 'local_ulms_kortext')
                : get_string('health_degraded', 'local_ulms_kortext');
            $health_desc  = $h['ok']
                ? get_string('summary_health_desc', 'local_ulms_kortext')
                : get_string('summary_health_desc', 'local_ulms_kortext') . ' — ' . ($h['error'] ?? '');
        } catch (\Throwable) {
            $health_value = get_string('health_offline', 'local_ulms_kortext');
            $health_desc  = get_string('summary_health_desc', 'local_ulms_kortext');
        }
        return [
            [
                'label'       => get_string('summary_adopted_isbns', 'local_ulms_kortext'),
                'value'       => (string)$adopted_active,
                'description' => get_string('summary_adopted_isbns_desc', 'local_ulms_kortext'),
            ],
            [
                'label'       => get_string('summary_active_programmes', 'local_ulms_kortext'),
                'value'       => (string)$programmes_active,
                'description' => get_string('summary_active_programmes_desc', 'local_ulms_kortext'),
            ],
            [
                'label'       => get_string('summary_entitled_24h', 'local_ulms_kortext'),
                'value'       => (string)$entitled_24h,
                'description' => get_string('summary_entitled_24h_desc', 'local_ulms_kortext'),
            ],
            [
                'label'       => get_string('summary_health', 'local_ulms_kortext'),
                'value'       => $health_value,
                'description' => $health_desc,
            ],
        ];
    }

    /**
     * Resolve the list of moodle course ids relevant for the given user+role.
     *
     * Lecturer: courses where the user has editingteacher / teacher enrolment role.
     * Student:  courses where the user has student enrolment role.
     *
     * @param int    $userid
     * @param string $role  'lecturer' | 'student'
     * @return array<int>
     */
    public static function resolve_courseids_for_user(int $userid, string $role): array {
        global $DB;
        if ($userid <= 0) {
            return [];
        }
        $roleids = [];
        if ($role === 'lecturer') {
            $shortnames = ['editingteacher', 'teacher', 'manager', 'coursecreator'];
        } elseif ($role === 'student') {
            $shortnames = ['student'];
        } else {
            return [];
        }
        [$snin, $snparams] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'sn');
        $roleids = $DB->get_fieldset_sql(
            "SELECT id FROM {role} WHERE shortname {$snin}",
            $snparams
        );
        if (count($roleids) === 0) {
            return [];
        }
        [$rin, $rparams] = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'rid');
        $sql = "SELECT DISTINCT e.courseid
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
                  JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
                 WHERE ue.userid = :uid AND ra.roleid {$rin}";
        $params = ['uid' => $userid] + $rparams;
        $ids = $DB->get_fieldset_sql($sql, $params);
        return array_values(array_map('intval', $ids));
    }
}
