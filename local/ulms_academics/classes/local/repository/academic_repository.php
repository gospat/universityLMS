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

namespace local_ulms_academics\local\repository;

// NAMING SCHISM (DO NOT RENAME WITHOUT MIGRATION):
// User-facing UI displays "College" (renamed from "Faculty" 2026-08).
// DB/structural identifiers remain legacy *_faculties / facultyid / managefaculties.
// This is intentional (backward compatibility, reporting tool chain).
// If you rename schema-level items, add a formal savepoint upgrade and update ALL references.

defined('MOODLE_INTERNAL') || die();

/**
 * Repository for ULMS academic structure records.
 */
class academic_repository {
    /** @var object */
    private object $db;

    /**
     * Academic repository constructor.
     *
     * @param object|null $db
     */
    public function __construct(?object $db = null) {
        global $DB;
        $this->db = $db ?? $DB;
    }

    /**
     * Returns a list of supported entity names.
     *
     * @return string[]
     */
    public function get_supported_entities(): array {
        return ['faculties', 'departments', 'programmes', 'sessions', 'semesters'];
    }

    /**
     * Returns table name for an entity.
     *
     * @param string $entity
     * @return string
     */
    public function get_table_name(string $entity): string {
        return match ($entity) {
            'faculties' => 'local_ulms_faculties',
            'departments' => 'local_ulms_departments',
            'programmes' => 'local_ulms_programmes',
            'sessions' => 'local_ulms_sessions',
            'semesters' => 'local_ulms_semesters',
            default => '',
        };
    }

    /**
     * Returns whether an entity name is supported.
     *
     * @param string $entity
     * @return bool
     */
    public function is_supported_entity(string $entity): bool {
        return in_array($entity, $this->get_supported_entities(), true);
    }

    /**
     * Returns records for an entity.
     *
     * @param string $entity
     * @return array
     */
    public function get_records(string $entity): array {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            return [];
        }

        return $this->db->get_records($table, null, 'name ASC, id ASC');
    }

    /**
     * Returns records filtered by search and optional status.
     *
     * @param string $entity
     * @param string $search
     * @param string $status
     * @return array
     */
    public function get_filtered_records(
        string $entity,
        string $search = '',
        string $status = '',
        string $sort = 'name',
        string $direction = 'ASC',
        int $limitfrom = 0,
        int $limitnum = 20
    ): array {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            return [];
        }

        [$whereclause, $params] = $this->build_filter_sql($entity, $search, $status);
        $allowedsorts = ['code', 'name', 'status', 'timecreated', 'timemodified'];
        $sort = in_array($sort, $allowedsorts, true) ? $sort : 'name';
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $sql = "SELECT * FROM {{$table}} WHERE {$whereclause} ORDER BY {$sort} {$direction}, id ASC";
        return $this->db->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }

    /**
     * Counts filtered records.
     *
     * @param string $entity
     * @param string $search
     * @param string $status
     * @return int
     */
    public function count_filtered_records(string $entity, string $search = '', string $status = ''): int {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            return 0;
        }

        [$whereclause, $params] = $this->build_filter_sql($entity, $search, $status);
        $sql = "SELECT COUNT(1) FROM {{$table}} WHERE {$whereclause}";
        return (int)$this->db->count_records_sql($sql, $params);
    }

    /**
     * Returns a single record.
     *
     * @param string $entity
     * @param int $id
     * @return \stdClass|false
     */
    public function get_record(string $entity, int $id) {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            return false;
        }

        return $this->db->get_record($table, ['id' => $id]);
    }

    /**
     * Returns a single record using an arbitrary field.
     *
     * @param string $entity
     * @param string $field
     * @param mixed $value
     * @return \stdClass|false
     */
    public function get_record_by_field(string $entity, string $field, $value) {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            return false;
        }

        return $this->db->get_record($table, [$field => $value]);
    }

    /**
     * Validates that a parent foreign key references an existing record.
     *
     * @param string $entity
     * @param string $parentfield
     * @param int $parentid
     * @return void
     * @throws \InvalidArgumentException if the parent does not exist
     */
    public function validate_parent_reference(string $entity, string $parentfield, int $parentid): void {
        if ($parentid <= 0) {
            throw new \InvalidArgumentException(
                ucfirst($parentfield) . ' is required.'
            );
        }
        $parenttable = $this->get_table_name($entity);
        if (empty($parenttable)) {
            throw new \InvalidArgumentException('Unsupported parent entity: ' . $entity);
        }
        if (!$this->db->record_exists($parenttable, ['id' => $parentid])) {
            throw new \InvalidArgumentException(
                'Selected ' . str_replace('id', '', $parentfield) . ' does not exist.'
            );
        }
    }

    /**
     * Saves a record for the provided entity.
     *
     * @param string $entity
     * @param \stdClass $record
     * @return int
     */
    public function save_record(string $entity, \stdClass $record): int {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            throw new \InvalidArgumentException('Unsupported academic entity: ' . $entity);
        }

        switch ($entity) {
            case 'departments':
                $this->validate_parent_reference('faculties', 'facultyid', (int)($record->facultyid ?? 0));
                break;
            case 'programmes':
                $this->validate_parent_reference('departments', 'departmentid', (int)($record->departmentid ?? 0));
                break;
            case 'semesters':
                $this->validate_parent_reference('sessions', 'sessionid', (int)($record->sessionid ?? 0));
                break;
        }

        $now = time();
        $record->timemodified = $now;

        if (empty($record->id)) {
            $record->timecreated = $now;
            return (int)$this->db->insert_record($table, $record);
        }

        $this->db->update_record($table, $record);
        return (int)$record->id;
    }

    /**
     * Deletes a record if it is safe to do so.
     *
     * @param string $entity
     * @param int $id
     * @return bool
     */
    public function delete_record(string $entity, int $id): bool {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            throw new \InvalidArgumentException('Unsupported academic entity: ' . $entity);
        }

        return (bool)$this->db->delete_records($table, ['id' => $id]);
    }

    /**
     * Returns the number of records for the entity.
     *
     * @param string $entity
     * @return int
     */
    public function count_records(string $entity): int {
        $table = $this->get_table_name($entity);

        if (empty($table)) {
            return 0;
        }

        return (int)$this->db->count_records($table);
    }

    /**
     * Returns Moodle course options for mapping.
     *
     * @return array
     */
    public function get_moodle_courses(): array {
        return $this->db->get_records_select(
            'course',
            'id > :sitecourse',
            ['sitecourse' => 1],
            'fullname ASC, id ASC',
            'id, fullname, shortname, visible'
        );
    }

    /**
     * Returns course mapping rows with joined academic labels.
     *
     * @return array
     */
    public function get_course_mappings(
        string $search = '',
        int $facultyid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $semesterfilter = -1,
        string $coursetype = '',
        string $sort = 'faculty',
        string $direction = 'ASC',
        int $limitfrom = 0,
        int $limitnum = 0,
        int $levelid = -1,
        int $sessionid = -1
    ): array {
        [$wheresql, $params] = $this->build_course_mapping_filter_sql(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $levelid,
            $sessionid
        );
        $orderby = $this->build_course_mapping_order_by($sort, $direction);

        $sql = "SELECT pcm.id, pcm.programmeid, pcm.moodlecourseid, pcm.semesterid, pcm.levelid, pcm.coursetype, pcm.iscore,
                       pcm.timecreated, pcm.timemodified,
                       c.fullname AS coursename, c.shortname AS courseshortname, c.visible,
                       p.code AS programmecode, p.name AS programmename,
                       d.name AS departmentname,
                       f.name AS facultyname,
                       s.code AS semestercode, s.name AS semestername, s.sessionid AS semestersessionid,
                       lv.code AS levelcode, lv.name AS levelname
                  FROM {local_ulms_programme_courses} pcm
                  JOIN {course} c ON c.id = pcm.moodlecourseid
                  JOIN {local_ulms_programmes} p ON p.id = pcm.programmeid
                  JOIN {local_ulms_departments} d ON d.id = p.departmentid
                  JOIN {local_ulms_faculties} f ON f.id = d.facultyid
             LEFT JOIN {local_ulms_semesters} s ON s.id = pcm.semesterid
             LEFT JOIN {local_ulms_levels} lv ON lv.id = pcm.levelid
                 WHERE {$wheresql}
              ORDER BY {$orderby}";

        return $this->db->get_records_sql($sql, $params, $limitfrom, $limitnum);
    }

    /**
     * Counts course mappings matching the supplied filters.
     *
     * @param string $search
     * @param int $programmeid
     * @param int $semesterfilter
     * @param string $coursetype
     * @return int
     */
    public function count_course_mappings(
        string $search = '',
        int $facultyid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $semesterfilter = -1,
        string $coursetype = '',
        int $levelid = -1,
        int $sessionid = -1
    ): int {
        [$wheresql, $params] = $this->build_course_mapping_filter_sql(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $levelid,
            $sessionid
        );

        $sql = "SELECT COUNT(1)
                  FROM {local_ulms_programme_courses} pcm
                  JOIN {course} c ON c.id = pcm.moodlecourseid
                  JOIN {local_ulms_programmes} p ON p.id = pcm.programmeid
                  JOIN {local_ulms_departments} d ON d.id = p.departmentid
                  JOIN {local_ulms_faculties} f ON f.id = d.facultyid
             LEFT JOIN {local_ulms_semesters} s ON s.id = pcm.semesterid
             LEFT JOIN {local_ulms_levels} lv ON lv.id = pcm.levelid
                 WHERE {$wheresql}";

        return (int)$this->db->count_records_sql($sql, $params);
    }

    /**
     * Returns summary totals for the current mapping filters.
     *
     * @param string $search
     * @param int $programmeid
     * @param int $semesterfilter
     * @param string $coursetype
     * @return array
     */
    public function get_course_mapping_summary(
        string $search = '',
        int $facultyid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $semesterfilter = -1,
        string $coursetype = '',
        int $levelid = -1,
        int $sessionid = -1
    ): array {
        [$wheresql, $params] = $this->build_course_mapping_filter_sql(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $levelid,
            $sessionid
        );

        $sql = "SELECT COUNT(pcm.id) AS totalmappings,
                       COUNT(DISTINCT pcm.moodlecourseid) AS totalcourses,
                       COUNT(DISTINCT pcm.programmeid) AS totalprogrammes,
                       COUNT(DISTINCT pcm.semesterid) AS totalsemesters,
                       SUM(CASE WHEN pcm.iscore = 1 THEN 1 ELSE 0 END) AS coremappings
                  FROM {local_ulms_programme_courses} pcm
                  JOIN {course} c ON c.id = pcm.moodlecourseid
                  JOIN {local_ulms_programmes} p ON p.id = pcm.programmeid
                  JOIN {local_ulms_departments} d ON d.id = p.departmentid
                  JOIN {local_ulms_faculties} f ON f.id = d.facultyid
             LEFT JOIN {local_ulms_semesters} s ON s.id = pcm.semesterid
                 WHERE {$wheresql}";

        $record = $this->db->get_record_sql($sql, $params);

        return [
            'totalmappings' => (int)($record->totalmappings ?? 0),
            'totalcourses' => (int)($record->totalcourses ?? 0),
            'totalprogrammes' => (int)($record->totalprogrammes ?? 0),
            'totalsemesters' => (int)($record->totalsemesters ?? 0),
            'coremappings' => (int)($record->coremappings ?? 0),
        ];
    }

    /**
     * Returns the allowed course mapping sort fields.
     *
     * @return array
     */
    public function get_course_mapping_sort_fields(): array {
        return [
            'course' => 'c.fullname',
            'programme' => 'p.name',
            'department' => 'd.name',
            'faculty' => 'f.name',
            'semester' => 's.name',
            'level' => 'lv.name',
            'session' => 's.sessionid',
            'coursetype' => 'pcm.coursetype',
            'iscore' => 'pcm.iscore',
            'timecreated' => 'pcm.timecreated',
        ];
    }

    /**
     * Returns a single course mapping.
     *
     * @param int $id
     * @return \stdClass|false
     */
    public function get_course_mapping(int $id) {
        return $this->db->get_record('local_ulms_programme_courses', ['id' => $id]);
    }

    /**
     * Returns a course mapping by programme and Moodle course.
     *
     * @param int $programmeid
     * @param int $moodlecourseid
     * @return \stdClass|false
     */
    public function get_course_mapping_by_programme_course(int $programmeid, int $moodlecourseid) {
        return $this->db->get_record('local_ulms_programme_courses', [
            'programmeid' => $programmeid,
            'moodlecourseid' => $moodlecourseid,
        ]);
    }

    /**
     * Returns a course mapping matching the full 4-column hierarchy key.
     * Semester NULL semantics are handled as a distinct match-group.
     *
     * @param int $programmeid
     * @param int $moodlecourseid
     * @param int $semesterid  0 means NULL semester
     * @param int $levelid     0 means level-wide
     * @return \stdClass|false
     */
    public function get_course_mapping_by_hierarchy(
        int $programmeid,
        int $moodlecourseid,
        int $semesterid,
        int $levelid
    ) {
        $sql = "SELECT *
                  FROM {local_ulms_programme_courses}
                 WHERE programmeid = :pid
                   AND moodlecourseid = :cid
                   AND levelid = :lid
                   AND (";
        $params = [
            'pid' => $programmeid,
            'cid' => $moodlecourseid,
            'lid' => $levelid,
        ];
        if ($semesterid > 0) {
            $sql .= "semesterid = :sid)";
            $params['sid'] = $semesterid;
        } else {
            $sql .= "semesterid IS NULL)";
        }
        return $this->db->get_record_sql($sql, $params, IGNORE_MISSING);
    }

    /**
     * Saves a course mapping row.
     *
     * @param \stdClass $record
     * @return int
     */
    public function save_course_mapping(\stdClass $record): int {
        $now = time();
        $record->timemodified = $now;

        if (empty($record->id)) {
            $record->timecreated = $now;
            return (int)$this->db->insert_record('local_ulms_programme_courses', $record);
        }

        $this->db->update_record('local_ulms_programme_courses', $record);
        return (int)$record->id;
    }

    /**
     * Deletes a course mapping row.
     *
     * @param int $id
     * @return bool
     */
    public function delete_course_mapping(int $id): bool {
        return (bool)$this->db->delete_records('local_ulms_programme_courses', ['id' => $id]);
    }

    /**
     * Returns dependent child counts that block deletion.
     *
     * @param string $entity
     * @param int $id
     * @return array
     */
    public function get_dependency_counts(string $entity, int $id): array {
        return match ($entity) {
            'faculties' => [
                'departments' => (int)$this->db->count_records('local_ulms_departments', ['facultyid' => $id]),
            ],
            'departments' => [
                'programmes' => (int)$this->db->count_records('local_ulms_programmes', ['departmentid' => $id]),
            ],
            'programmes' => [
                'coursemappings' => (int)$this->db->count_records('local_ulms_programme_courses', ['programmeid' => $id]),
            ],
            'sessions' => [
                'semesters' => (int)$this->db->count_records('local_ulms_semesters', ['sessionid' => $id]),
            ],
            default => [],
        };
    }

    /**
     * Builds reusable filter SQL and parameters.
     *
     * @param string $entity
     * @param string $search
     * @param string $status
     * @return array
     */
    private function build_filter_sql(string $entity, string $search, string $status): array {
        $whereclause = '1 = 1';
        $params = [];

        if ($search !== '') {
            $whereclause .= " AND (" . $this->db->sql_like('name', ':searchname', false, false) .
                " OR " . $this->db->sql_like('code', ':searchcode', false, false) . ")";
            $params['searchname'] = '%' . $search . '%';
            $params['searchcode'] = '%' . $search . '%';
        }

        if ($status !== '' && in_array($entity, ['faculties', 'departments', 'programmes'], true)) {
            $whereclause .= " AND status = :status";
            $params['status'] = $status;
        }

        return [$whereclause, $params];
    }

    /**
     * Builds reusable course mapping filter SQL and parameters.
     *
     * @param string $search
     * @param int $facultyid
     * @param int $departmentid
     * @param int $programmeid
     * @param int $semesterfilter
     * @param string $coursetype
     * @return array
     */
    private function build_course_mapping_filter_sql(
        string $search,
        int $facultyid,
        int $departmentid,
        int $programmeid,
        int $semesterfilter,
        string $coursetype,
        int $levelid = -1,
        int $sessionid = -1
    ): array {
        $where = ['1 = 1'];
        $params = [];

        if ($search !== '') {
            $likesql = [];
            $likesql[] = $this->db->sql_like('c.fullname', ':coursefullname', false, false);
            $likesql[] = $this->db->sql_like('c.shortname', ':courseshortname', false, false);
            $likesql[] = $this->db->sql_like('p.name', ':programmename', false, false);
            $likesql[] = $this->db->sql_like('p.code', ':programmecode', false, false);
            $likesql[] = $this->db->sql_like('d.name', ':departmentname', false, false);
            $likesql[] = $this->db->sql_like('f.name', ':facultyname', false, false);
            $where[] = '(' . implode(' OR ', $likesql) . ')';
            $params['coursefullname'] = '%' . $search . '%';
            $params['courseshortname'] = '%' . $search . '%';
            $params['programmename'] = '%' . $search . '%';
            $params['programmecode'] = '%' . $search . '%';
            $params['departmentname'] = '%' . $search . '%';
            $params['facultyname'] = '%' . $search . '%';
        }

        if ($facultyid > 0) {
            $where[] = 'f.id = :facultyid';
            $params['facultyid'] = $facultyid;
        }

        if ($departmentid > 0) {
            $where[] = 'd.id = :departmentid';
            $params['departmentid'] = $departmentid;
        }

        if ($programmeid > 0) {
            $where[] = 'pcm.programmeid = :programmeid';
            $params['programmeid'] = $programmeid;
        }

        if ($semesterfilter === 0) {
            $where[] = 'pcm.semesterid IS NULL';
        } else if ($semesterfilter > 0) {
            $where[] = 'pcm.semesterid = :semesterid';
            $params['semesterid'] = $semesterfilter;
        }

        if ($coursetype !== '') {
            $where[] = 'pcm.coursetype = :coursetype';
            $params['coursetype'] = $coursetype;
        }

        if ($levelid === 0) {
            $where[] = '(pcm.levelid = :levelid0 OR pcm.levelid IS NULL)';
            $params['levelid0'] = 0;
        } else if ($levelid > 0) {
            $where[] = 'pcm.levelid = :levelidmatch';
            $params['levelidmatch'] = $levelid;
        }

        if ($sessionid > 0) {
            $where[] = '(s.sessionid = :sessionidmatch OR (s.id IS NOT NULL AND s.sessionid = :sessionidmatch2))';
            $params['sessionidmatch'] = $sessionid;
            $params['sessionidmatch2'] = $sessionid;
        } else if ($sessionid === 0) {
            $where[] = 's.id IS NULL';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Builds a safe ORDER BY clause for course mapping listings.
     *
     * @param string $sort
     * @param string $direction
     * @return string
     */
    private function build_course_mapping_order_by(string $sort, string $direction): string {
        $sortfields = $this->get_course_mapping_sort_fields();
        $sortcolumn = $sortfields[$sort] ?? $sortfields['faculty'];
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        return match ($sort) {
            'course' => "{$sortcolumn} {$direction}, p.name ASC, f.name ASC",
            'programme' => "{$sortcolumn} {$direction}, d.name ASC, c.fullname ASC",
            'department' => "{$sortcolumn} {$direction}, p.name ASC, c.fullname ASC",
            'faculty' => "{$sortcolumn} {$direction}, d.name ASC, p.name ASC, c.fullname ASC",
            'semester' => "{$sortcolumn} {$direction}, p.name ASC, c.fullname ASC",
            'level' => "{$sortcolumn} {$direction}, p.name ASC, c.fullname ASC",
            'session' => "{$sortcolumn} {$direction}, s.name ASC, p.name ASC, c.fullname ASC",
            'coursetype' => "{$sortcolumn} {$direction}, p.name ASC, c.fullname ASC",
            'iscore' => "{$sortcolumn} {$direction}, p.name ASC, c.fullname ASC",
            'timecreated' => "{$sortcolumn} {$direction}, p.name ASC, c.fullname ASC",
            default => "{$sortfields['faculty']} ASC, d.name ASC, p.name ASC, c.fullname ASC",
        };
    }

    /**
     * D5 canonical helper — returns the moodlecourseid values that are
     * mapped to a programme via `{local_ulms_programme_courses}`.
     *
     * Scoping rules (exactly match the mapping UI's notion of "wide"
     * vs "level-specific"):
     *   • $studylevelid === null (default) — returns all rows in the
     *     programme regardless of levelid.  Use this for institution-
     *     wide / cross-programme KPI queries.
     *   • $studylevelid > 0               — returns rows where
     *     `pcm.levelid = $studylevelid` OR `pcm.levelid IS NULL` OR
     *     `pcm.levelid = 0` (= "wide" courses).  Use this anywhere a
     *     student view is rendered so a 100L user sees 100L-specific
     *     plus programme-wide courses but never 200L, 300L etc.
     *     IMPORTANT — accepts EITHER a literal levels.id (1..6) OR a
     *     level code like 100 / 200 (the format used by
     *     {local_ulms_user_profile}.studylevel, which stores the
     *     textual code not the PK).  The value is normalised via
     *     {@see self::resolve_level_id_from_studylevel()} before use.
     *   • $semesterid                     — optional semester scoping
     *     (null = any semester, 0 = semesterid IS NULL only, >0 =
     *     exact match).
     *   • $visibleonly                    — when true (default) joins
     *     `{course}` and requires `c.visible = 1 AND c.id > 1`.
     *
     * Always returns a list of unique integers keyed numerically
     * (suitable for IN($list) without array_values() prep).
     *
     * @param int      $programmeid   Programme primary key; 0 → empty.
     * @param int|null $studylevelid  Student level.  Accepts levels.id
     *                                (1..6) or a level code (100,200).
     *                                NULL for unfiltered programme-wide.
     * @param bool     $visibleonly   Filter out hidden / deleted Moodle courses.
     * @param int|null $semesterid    Optional semester scoping (see above).
     * @return array<int,int> Unique moodlecourseid values (0..N entries).
     */
    public static function get_programme_moodlecourseids(
        int $programmeid,
        ?int $studylevelid = null,
        bool $visibleonly = true,
        ?int $semesterid = null
    ): array {
        global $DB;
        if ($programmeid <= 0) {
            return [];
        }

        $where  = ['pc.programmeid = :pid'];
        $params = ['pid' => $programmeid];

        if ($studylevelid !== null) {
            $resolved = self::resolve_level_id_from_studylevel($studylevelid);
            if ($resolved > 0) {
                $where[] = '(pc.levelid = :slevel OR pc.levelid = :slevel0 OR pc.levelid IS NULL)';
                $params['slevel']  = $resolved;
                $params['slevel0'] = 0;
            }
        }

        if ($semesterid === 0) {
            $where[] = 'pc.semesterid IS NULL';
        } elseif ($semesterid !== null && $semesterid > 0) {
            $where[] = 'pc.semesterid = :sem';
            $params['sem'] = $semesterid;
        }

        if ($visibleonly) {
            $joinsql = "JOIN {course} c ON c.id = pc.moodlecourseid";
            $where[] = 'c.id > 1';
            $where[] = 'c.visible = 1';
        } else {
            $joinsql = '';
        }

        $wheresql = implode(' AND ', $where);
        $sql = "SELECT DISTINCT pc.moodlecourseid
                  FROM {local_ulms_programme_courses} pc
                  {$joinsql}
                 WHERE {$wheresql}";
        try {
            $rows = $DB->get_records_sql($sql, $params);
        } catch (\Throwable) {
            return [];
        }
        if (empty($rows)) {
            return [];
        }
        $ids = [];
        foreach ($rows as $r) {
            $cid = (int)($r->moodlecourseid ?? 0);
            if ($cid > 0) {
                $ids[] = $cid;
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values(array_unique($ids, SORT_NUMERIC));
    }

    /**
     * Resolves a user-profile "studylevel" value into the corresponding
     * {local_ulms_levels}.id primary key.
     *
     * The data model uses TWO representations:
     *   1. {local_ulms_levels}.id        — INT 1..6 (1 = 100L, 2 = 200L, …)
     *   2. {local_ulms_levels}.code      — CHAR "100" … "600"
     *   3. {local_ulms_user_profile}.studylevel — CHAR stores the CODE
     *      string, e.g. "100", NOT the PK (user profile schema mismatch
     *      from earlier migrations, present in live data).
     *
     * To keep callers robust we accept ANY of:
     *   • integer 1..6       → used directly as levels.id
     *   • integer 100..600   → looked up via levels.code → id
     *   • string "100" etc.  → cast to int, then code lookup
     *   • NULL / 0 / empty   → 0 (unresolved, no level filter applied)
     *
     * Results are memoised per-request via a small static cache since
     * the code→id mapping is 6 rows and never changes during a request.
     *
     * @param string|int|null $studylevel The raw studylevel (code or id).
     * @return int levels.id if resolvable (1..6); 0 otherwise.
     */
    public static function resolve_level_id_from_studylevel(string|int|null $studylevel): int {
        global $DB;
        static $cache = null;
        if ($cache === null) {
            $cache = [];
            try {
                $rs = $DB->get_recordset('local_ulms_levels', [], 'id ASC', 'id,code,name');
                foreach ($rs as $l) {
                    $id = (int)$l->id;
                    $code = (int)trim((string)$l->code);
                    $cache['id_' . $id] = $id;
                    if ($code > 0) {
                        $cache['code_' . $code] = $id;
                    }
                }
                $rs->close();
            } catch (\Throwable) {
                $cache = [];
            }
        }
        if ($studylevel === null || $studylevel === '' || $studylevel === 0) {
            return 0;
        }
        $val = is_string($studylevel)
            ? (int)trim($studylevel)
            : (int)$studylevel;
        if ($val <= 0) {
            return 0;
        }
        if (isset($cache['id_' . $val])) {
            return (int)$cache['id_' . $val];
        }
        if (isset($cache['code_' . $val])) {
            return (int)$cache['code_' . $val];
        }
        return 0;
    }

    /**
     * Convenience wrapper around {@see self::get_programme_moodlecourseids()}
     * that resolves a user's {local_ulms_user_profile} record and applies
     * student-level scoping automatically.  Used as the Single Source of
     * Truth for every student-side whitelist / catalogue / grades /
     * attendance / timetable query so 100L users never see 200L-only
     * courses for their programme.
     *
     * Returns an empty array if the user has no profile or no programme.
     *
     * @param int      $userid
     * @param bool     $visibleonly Passed through to get_programme_moodlecourseids().
     * @param int|null $semesterid  Passed through to get_programme_moodlecourseids().
     * @return array{programmeid:int,studylevelid:int,studylevel_code:int,resolved_level_id:int,courseids:array<int,int>}
     */
    public static function get_student_programme_courseids(
        int $userid,
        bool $visibleonly = true,
        ?int $semesterid = null
    ): array {
        global $DB;
        $fallback = [
            'programmeid'        => 0,
            'studylevelid'       => 0,
            'studylevel_code'    => 0,
            'resolved_level_id'  => 0,
            'courseids'          => [],
        ];
        if ($userid <= 0) {
            return $fallback;
        }
        try {
            $profile = $DB->get_record(
                'local_ulms_user_profile',
                ['userid' => $userid],
                'id,programmeid,studylevel',
                IGNORE_MISSING
            );
        } catch (\Throwable) {
            return $fallback;
        }
        $programmeid      = (int)($profile->programmeid ?? 0);
        $studylevel_code  = is_string($profile->studylevel ?? null)
            ? (int)trim((string)($profile->studylevel ?? '0'))
            : (int)($profile->studylevel ?? 0);
        $resolved_levelid = self::resolve_level_id_from_studylevel($studylevel_code);
        if ($programmeid <= 0) {
            return [
                'programmeid'        => 0,
                'studylevelid'       => $resolved_levelid,
                'studylevel_code'    => $studylevel_code,
                'resolved_level_id'  => $resolved_levelid,
                'courseids'          => [],
            ];
        }
        return [
            'programmeid'        => $programmeid,
            'studylevelid'       => $resolved_levelid,
            'studylevel_code'    => $studylevel_code,
            'resolved_level_id'  => $resolved_levelid,
            'courseids'          => self::get_programme_moodlecourseids($programmeid, $studylevel_code, $visibleonly, $semesterid),
        ];
    }
}
