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

namespace local_ulms_academics\local\service;

defined('MOODLE_INTERNAL') || die();

use local_ulms_academics\local\repository\academic_repository;

/**
 * Service helpers for managing ULMS academic structure data.
 */
class academic_structure_service {
    /** @var academic_repository */
    private academic_repository $repository;

    /**
     * Academic structure service constructor.
     *
     * @param academic_repository|null $repository
     */
    public function __construct(?academic_repository $repository = null) {
        $this->repository = $repository ?? new academic_repository();
    }

    /**
     * Returns whether the plugin is enabled.
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool)\get_config('local_ulms_academics', 'enableacademics');
    }

    /**
     * Returns the configured current session code.
     *
     * @return string
     */
    public function get_current_session_code(): string {
        return (string)\get_config('local_ulms_academics', 'currentsessioncode');
    }

    /**
     * Returns supported academic entities.
     *
     * @return string[]
     */
    public function get_supported_entities(): array {
        return $this->repository->get_supported_entities();
    }

    /**
     * Parses an uploaded CSV file into associative rows.
     *
     * @param string $filepath
     * @return array{rows: array<int, array<string, string>>, errors: array<int, string>}
     */
    public function parse_csv_upload_file(string $filepath): array {
        $result = [
            'rows' => [],
            'errors' => [],
        ];

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            $result['errors'][] = \get_string('csvupload', 'local_ulms_academics');
            return $result;
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            $result['errors'][] = \get_string('csvupload', 'local_ulms_academics');
            return $result;
        }

        $headers = array_map(
            static fn(string $header): string => \core_text::strtolower(trim($header)),
            $headers
        );

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_slice(array_pad($row, count($headers), ''), 0, count($headers));
            if (implode('', array_map('trim', $row)) === '') {
                continue;
            }

            $combined = array_combine($headers, $row);
            if ($combined === false) {
                $result['errors'][] = \get_string('csvupload', 'local_ulms_academics');
                break;
            }

            $result['rows'][] = $combined;
        }

        fclose($handle);
        return $result;
    }

    /**
     * Encodes preview rows for the confirm-import round trip.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string|null
     */
    public function encode_preview_payload(array $rows): ?string {
        $json = json_encode($rows);
        if ($json === false || $json === '') {
            return null;
        }

        $payload = base64_encode($json);
        if ($payload === false || $payload === '') {
            return null;
        }

        return $payload;
    }

    /**
     * Decodes preview rows from the confirm-import round trip.
     *
     * @param string $payload
     * @return array<int, array<string, mixed>>|null
     */
    public function decode_preview_payload(string $payload): ?array {
        $decodedpayload = base64_decode($payload, true);
        if ($decodedpayload === false || $decodedpayload === '') {
            return null;
        }

        $rows = json_decode($decodedpayload, true);
        if (!is_array($rows)) {
            return null;
        }

        return $rows;
    }

    /**
     * Builds preview state for an uploaded CSV dataset.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param callable(array<int, array<string, mixed>>): array $previewcallback
     * @return array{previewdata: ?array, previewpayload: string, errors: array<int, string>}
     */
    public function create_preview_submission_state(array $rows, callable $previewcallback): array {
        $previewdata = $previewcallback($rows);
        $previewpayload = $this->encode_preview_payload($rows) ?? '';

        if ($previewpayload === '') {
            return [
                'previewdata' => null,
                'previewpayload' => '',
                'errors' => [\get_string('csvpreviewpayloaderror', 'local_ulms_academics')],
            ];
        }

        return [
            'previewdata' => $previewdata,
            'previewpayload' => $previewpayload,
            'errors' => [],
        ];
    }

    /**
     * Replays a confirmed preview payload and runs the import callback when valid.
     *
     * @param string $payload
     * @param callable(array<int, array<string, mixed>>): array $previewcallback
     * @param callable(array<int, array<string, mixed>>): array $importcallback
     * @return array{previewdata: ?array, previewpayload: string, errors: array<int, string>, result: ?array}
     */
    public function execute_confirmed_preview_import(
        string $payload,
        callable $previewcallback,
        callable $importcallback
    ): array {
        $rows = $this->decode_preview_payload($payload);
        if ($rows === null) {
            return [
                'previewdata' => null,
                'previewpayload' => '',
                'errors' => [\get_string('csvpreviewpayloaderror', 'local_ulms_academics')],
                'result' => null,
            ];
        }

        $previewdata = $previewcallback($rows);
        if (($previewdata['invalid'] ?? 0) > 0) {
            return [
                'previewdata' => $previewdata,
                'previewpayload' => $payload,
                'errors' => [\get_string('csvpreviewfixerrors', 'local_ulms_academics')],
                'result' => null,
            ];
        }

        $result = $importcallback($rows);

        return [
            'previewdata' => null,
            'previewpayload' => '',
            'errors' => $result['errors'] ?? [],
            'result' => $result,
        ];
    }

    /**
     * Returns whether the supplied entity is supported.
     *
     * @param string $entity
     * @return bool
     */
    public function is_supported_entity(string $entity): bool {
        return $this->repository->is_supported_entity($entity);
    }

    /**
     * Returns records for the supplied entity.
     *
     * @param string $entity
     * @return array
     */
    public function get_records_for_entity(string $entity): array {
        return $this->repository->get_records($entity);
    }

    /**
     * Returns filtered records for an entity.
     *
     * @param string $entity
     * @param string $search
     * @param string $status
     * @return array
     */
    public function get_filtered_records_for_entity(
        string $entity,
        string $search = '',
        string $status = '',
        string $sort = 'name',
        string $direction = 'ASC',
        int $limitfrom = 0,
        int $limitnum = 20
    ): array {
        return $this->repository->get_filtered_records(
            $entity,
            trim($search),
            trim($status),
            $sort,
            $direction,
            $limitfrom,
            $limitnum
        );
    }

    /**
     * Counts filtered records for an entity.
     *
     * @param string $entity
     * @param string $search
     * @param string $status
     * @return int
     */
    public function count_filtered_records_for_entity(string $entity, string $search = '', string $status = ''): int {
        return $this->repository->count_filtered_records($entity, trim($search), trim($status));
    }

    /**
     * Returns a single record for the supplied entity.
     *
     * @param string $entity
     * @param int $id
     * @return \stdClass|false
     */
    public function get_record_for_entity(string $entity, int $id) {
        return $this->repository->get_record($entity, $id);
    }

    /**
     * Returns a single record using an arbitrary field value.
     *
     * @param string $entity
     * @param string $field
     * @param mixed $value
     * @return \stdClass|false
     */
    public function get_record_for_entity_by_field(string $entity, string $field, $value) {
        return $this->repository->get_record_by_field($entity, $field, $value);
    }

    /**
     * Returns a friendly label for the supplied entity.
     *
     * @param string $entity
     * @return string
     */
    public function get_entity_label(string $entity): string {
        return match ($entity) {
            'faculties' => \get_string('faculties', 'local_ulms_academics'),
            'departments' => \get_string('departments', 'local_ulms_academics'),
            'programmes' => \get_string('programmes', 'local_ulms_academics'),
            'courses' => \get_string('courses', 'local_ulms_academics'),
            'sessions' => \get_string('academicsessions', 'local_ulms_academics'),
            'semesters' => \get_string('semesters', 'local_ulms_academics'),
            'coursemappings' => \get_string('mappings', 'local_ulms_academics'),
            default => \get_string('unknownentity', 'local_ulms_academics'),
        };
    }

    /**
     * Returns the parent entity name for a child entity.
     *
     * @param string $entity
     * @return string|null
     */
    public function get_parent_entity(string $entity): ?string {
        return match ($entity) {
            'departments' => 'faculties',
            'programmes' => 'departments',
            'semesters' => 'sessions',
            default => null,
        };
    }

    /**
     * Returns parent option records for an entity.
     *
     * @param string $entity
     * @return array
     */
    public function get_parent_options(string $entity): array {
        $parententity = $this->get_parent_entity($entity);
        $options = [0 => \get_string('none')];

        if (empty($parententity)) {
            return $options;
        }

        foreach ($this->get_records_for_entity($parententity) as $record) {
            $options[$record->id] = $record->name;
        }

        return $options;
    }

    /**
     * Returns department options filtered by faculty.
     *
     * @param int $facultyid
     * @return array
     */
    public function get_department_options_for_faculty(int $facultyid = 0): array {
        $options = [];

        foreach ($this->get_records_for_entity('departments') as $department) {
            if ($facultyid > 0 && (int)($department->facultyid ?? 0) !== $facultyid) {
                continue;
            }

            $options[(int)$department->id] = $department->name;
        }

        return $options;
    }

    /**
     * Returns programme options filtered by faculty and department.
     *
     * @param int $facultyid
     * @param int $departmentid
     * @return array
     */
    public function get_programme_options_for_hierarchy(int $facultyid = 0, int $departmentid = 0): array {
        $options = [];
        $departments = $this->get_records_for_entity('departments');

        foreach ($this->get_records_for_entity('programmes') as $programme) {
            $currentdepartmentid = (int)($programme->departmentid ?? 0);

            if ($departmentid > 0 && $currentdepartmentid !== $departmentid) {
                continue;
            }

            if ($facultyid > 0) {
                $department = $departments[$currentdepartmentid] ?? null;
                if (!$department || (int)($department->facultyid ?? 0) !== $facultyid) {
                    continue;
                }
            }

            $options[(int)$programme->id] = $programme->name;
        }

        return $options;
    }

    /**
     * Returns entity tabs for local navigation.
     *
     * @param string $currententity
     * @return array
     */
    public function get_entity_tabs(string $currententity): array {
        $tabs = [];
        $routingservice = new \local_ulms_auth\local\service\landing_page_service();

        foreach ($this->get_supported_entities() as $entity) {
            $tabs[] = [
                'label' => $this->get_entity_label($entity),
                'url' => $routingservice->get_url_for_route('management.academicsmanage', ['entity' => $entity]),
                'active' => $entity === $currententity,
            ];
        }

        return $tabs;
    }

    /**
     * Returns a display value for the parent label of a record.
     *
     * @param string $entity
     * @param \stdClass $record
     * @return string
     */
    public function get_parent_label_for_record(string $entity, \stdClass $record): string {
        $parententity = $this->get_parent_entity($entity);

        if (empty($parententity)) {
            return '';
        }

        $parentidfield = match ($entity) {
            'departments' => 'facultyid',
            'programmes' => 'departmentid',
            'semesters' => 'sessionid',
            default => '',
        };

        if (empty($parentidfield) || empty($record->{$parentidfield})) {
            return \get_string('notset', 'local_ulms_academics');
        }

        $parentrecord = $this->get_record_for_entity($parententity, (int)$record->{$parentidfield});

        return $parentrecord ? (string)$parentrecord->name : \get_string('notset', 'local_ulms_academics');
    }

    /**
     * Returns an extra value to display in listings.
     *
     * @param string $entity
     * @param \stdClass $record
     * @return string
     */
    public function get_extra_value_for_record(string $entity, \stdClass $record): string {
        return match ($entity) {
            'faculties', 'departments', 'programmes' => $record->status ?? '',
            'sessions', 'semesters' => !empty($record->iscurrent)
                ? \get_string('yes')
                : \get_string('no'),
            default => '',
        };
    }

    /**
     * Returns the extra column label for a listing.
     *
     * @param string $entity
     * @return string
     */
    public function get_extra_column_label(string $entity): string {
        return match ($entity) {
            'faculties', 'departments', 'programmes' => \get_string('status', 'local_ulms_academics'),
            'sessions', 'semesters' => \get_string('iscurrent', 'local_ulms_academics'),
            default => '',
        };
    }

    /**
     * Returns whether the entity supports status filtering.
     *
     * @param string $entity
     * @return bool
     */
    public function supports_status_filter(string $entity): bool {
        return in_array($entity, ['faculties', 'departments', 'programmes'], true);
    }

    /**
     * Returns supported sort options for manage tables.
     *
     * @return array
     */
    public function get_sort_options(): array {
        return [
            'name' => \get_string('name'),
            'code' => \get_string('code', 'local_ulms_academics'),
            'status' => \get_string('status', 'local_ulms_academics'),
            'timecreated' => \get_string('timecreated', 'local_ulms_academics'),
            'timemodified' => \get_string('timemodified', 'local_ulms_academics'),
        ];
    }

    /**
     * Deletes a record after dependency checks.
     *
     * @param string $entity
     * @param int $id
     * @return array
     */
    public function delete_entity_record(string $entity, int $id): array {
        $record = $this->get_record_for_entity($entity, $id);
        if (!$record) {
            return [
                'success' => false,
                'message' => \get_string('recordnotfound', 'local_ulms_academics'),
            ];
        }

        $dependencies = array_filter(
            $this->repository->get_dependency_counts($entity, $id),
            static fn(int $count): bool => $count > 0
        );

        if (!empty($dependencies)) {
            $parts = [];
            foreach ($dependencies as $dependencyentity => $count) {
                $parts[] = $count . ' ' . $this->get_entity_label($dependencyentity);
            }

            return [
                'success' => false,
                'message' => \get_string('cannotdeletewithdependencies', 'local_ulms_academics', implode(', ', $parts)),
            ];
        }

        $this->repository->delete_record($entity, $id);

        return [
            'success' => true,
            'message' => \get_string('recorddeleted', 'local_ulms_academics'),
        ];
    }

    /**
     * Returns report summary counts.
     *
     * @return array
     */
    public function get_summary_counts(): array {
        $summary = [];

        foreach ($this->get_supported_entities() as $entity) {
            $summary[] = [
                'entity' => $entity,
                'label' => $this->get_entity_label($entity),
                'count' => $this->repository->count_records($entity),
            ];
        }

        return $summary;
    }

    /**
     * Returns Moodle course options for mappings.
     *
     * @return array
     */
    public function get_moodle_course_options(): array {
        $options = [0 => \get_string('choose')];

        foreach ($this->repository->get_moodle_courses() as $course) {
            $label = $course->fullname;
            if (!empty($course->shortname)) {
                $label .= ' (' . $course->shortname . ')';
            }

            $options[(int)$course->id] = $label;
        }

        return $options;
    }

    /**
     * Returns semester options for mappings.
     *
     * @return array
     */
    public function get_semester_options(): array {
        $options = [0 => \get_string('none')];

        foreach ($this->get_records_for_entity('semesters') as $semester) {
            $options[(int)$semester->id] = $semester->name;
        }

        return $options;
    }

    /**
     * Returns course type options.
     *
     * @return array
     */
    public function get_course_type_options(): array {
        return [
            'core' => \get_string('mappingcoursetypecore', 'local_ulms_academics'),
            'elective' => \get_string('mappingcoursetypeelective', 'local_ulms_academics'),
            'general' => \get_string('mappingcoursetypegeneral', 'local_ulms_academics'),
        ];
    }

    /**
     * Returns saved course mappings.
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
        return $this->repository->get_course_mappings(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $sort,
            $direction,
            $limitfrom,
            $limitnum,
            $levelid,
            $sessionid
        );
    }

    /**
     * Returns available sort labels for course mappings.
     *
     * @return array
     */
    public function get_course_mapping_sort_options(): array {
        return [
            'course' => \get_string('mappingmoodlecourse', 'local_ulms_academics'),
            'programme' => \get_string('programmes', 'local_ulms_academics'),
            'department' => \get_string('departments', 'local_ulms_academics'),
            'faculty' => \get_string('faculties', 'local_ulms_academics'),
            'semester' => \get_string('semesters', 'local_ulms_academics'),
            'level' => \get_string('mappingcolumnlevel', 'local_ulms_academics'),
            'session' => \get_string('mappingcolumnsession', 'local_ulms_academics'),
            'coursetype' => \get_string('mappingcoursetype', 'local_ulms_academics'),
            'iscore' => \get_string('mappingiscore', 'local_ulms_academics'),
        ];
    }

    /**
     * Counts saved course mappings for the supplied filters.
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
        return $this->repository->count_course_mappings(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $levelid,
            $sessionid
        );
    }

    /**
     * Returns summary totals for course mappings.
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
        return $this->repository->get_course_mapping_summary(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $levelid,
            $sessionid
        );
    }

    /**
     * Returns a single saved course mapping.
     *
     * @param int $id
     * @return \stdClass|false
     */
    public function get_course_mapping(int $id) {
        return $this->repository->get_course_mapping($id);
    }

    /**
     * Saves a programme-course mapping.
     *
     * @param array $data
     * @return array
     */
    public function save_course_mapping(array $data): array {
        global $DB;
        $mappingid = (int)($data['mappingid'] ?? 0);
        $programmeid = (int)($data['programmeid'] ?? 0);
        $moodlecourseid = (int)($data['moodlecourseid'] ?? 0);
        $semesterid = (int)($data['semesterid'] ?? 0);
        $levelid = (int)($data['levelid'] ?? 0);
        $coursetype = trim((string)($data['coursetype'] ?? 'core'));
        $iscore = !empty($data['iscore']) ? 1 : 0;

        $errors = [];

        if ($programmeid <= 0) {
            $errors['programmeid'] = \get_string('mappingrequiredfieldprogramme', 'local_ulms_academics');
        }
        if ($moodlecourseid <= 0) {
            $errors['moodlecourseid'] = \get_string('mappingrequiredfieldcourse', 'local_ulms_academics');
        }
        if ($moodlecourseid === 1) {
            $errors['moodlecourseid'] = \get_string('mappinginvalidsitecourse', 'local_ulms_academics');
        }
        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => \get_string('mappingrequiredfields', 'local_ulms_academics'),
                'errors' => $errors,
            ];
        }

        if (!$this->get_record_for_entity('programmes', $programmeid)) {
            $errors['programmeid'] = \get_string('recordnotfound', 'local_ulms_academics');
            return [
                'success' => false,
                'message' => \get_string('recordnotfound', 'local_ulms_academics'),
                'errors' => $errors,
            ];
        }

        $courses = $this->repository->get_moodle_courses();
        if (!isset($courses[$moodlecourseid])) {
            $errors['moodlecourseid'] = \get_string('mappinginvalidcourse', 'local_ulms_academics');
            return [
                'success' => false,
                'message' => \get_string('mappinginvalidcourse', 'local_ulms_academics'),
                'errors' => $errors,
            ];
        }

        if ($semesterid > 0 && !$this->get_record_for_entity('semesters', $semesterid)) {
            $errors['semesterid'] = \get_string('recordnotfound', 'local_ulms_academics');
            return [
                'success' => false,
                'message' => \get_string('recordnotfound', 'local_ulms_academics'),
                'errors' => $errors,
            ];
        }

        if ($levelid > 0 && !$DB->record_exists('local_ulms_levels', ['id' => $levelid])) {
            $errors['levelid'] = \get_string('mappinginvalidlevel', 'local_ulms_academics');
            return [
                'success' => false,
                'message' => \get_string('mappinginvalidlevel', 'local_ulms_academics'),
                'errors' => $errors,
            ];
        }

        if (!array_key_exists($coursetype, $this->get_course_type_options())) {
            $coursetype = 'core';
        }

        if ($mappingid > 0 && !$this->repository->get_course_mapping($mappingid)) {
            $errors['mappingid'] = \get_string('recordnotfound', 'local_ulms_academics');
            return [
                'success' => false,
                'message' => \get_string('recordnotfound', 'local_ulms_academics'),
                'errors' => $errors,
            ];
        }

        $existing = $this->repository->get_course_mapping_by_hierarchy(
            $programmeid,
            $moodlecourseid,
            $semesterid,
            $levelid
        );
        if ($existing && $mappingid > 0 && (int)$existing->id !== $mappingid) {
            return [
                'success' => false,
                'message' => \get_string('mappingduplicate', 'local_ulms_academics'),
                'errors' => [
                    '_base' => \get_string('mappingduplicate', 'local_ulms_academics'),
                ],
            ];
        }

        if ($existing && $mappingid === 0) {
            $mappingid = (int)$existing->id;
        }

        $isnew = $mappingid === 0 && !$existing;
        $record = new \stdClass();
        if ($mappingid > 0) {
            $record->id = $mappingid;
        }
        $record->programmeid = $programmeid;
        $record->moodlecourseid = $moodlecourseid;
        $record->semesterid = $semesterid > 0 ? $semesterid : null;
        $record->levelid = $levelid;
        $record->coursetype = $coursetype;
        $record->iscore = $iscore;

        $this->repository->save_course_mapping($record);

        if ($isnew && $programmeid > 0 && $moodlecourseid > 0) {
            try {
                if (function_exists('local_ulms_academics_retro_enrol_programme_course')) {
                    local_ulms_academics_retro_enrol_programme_course(
                        $programmeid,
                        $moodlecourseid,
                        $semesterid > 0 ? $semesterid : null
                    );
                }
            } catch (\Throwable $enrolex) {
                if (function_exists('local_ulms_dashboard_log_operational_error')) {
                    local_ulms_dashboard_log_operational_error($enrolex, 'academic_structure_service::save_course_mapping::retro_enrol', [
                        'programmeid' => $programmeid,
                        'courseid' => $moodlecourseid,
                        'semesterid' => $semesterid,
                        'levelid' => $levelid,
                    ]);
                }
            }
        }

        return [
            'success' => true,
            'message' => $mappingid > 0
                ? \get_string('mappingupdated', 'local_ulms_academics')
                : \get_string('mappingsaved', 'local_ulms_academics'),
        ];
    }

    /**
     * Deletes a saved programme-course mapping.
     *
     * @param int $id
     * @return array
     */
    public function delete_course_mapping(int $id): array {
        $mapping = $this->repository->get_course_mapping($id);
        if (!$mapping) {
            return [
                'success' => false,
                'message' => \get_string('recordnotfound', 'local_ulms_academics'),
            ];
        }

        $this->repository->delete_course_mapping($id);

        return [
            'success' => true,
            'message' => \get_string('mappingdeleted', 'local_ulms_academics'),
        ];
    }

    /**
     * Builds a preview of rows from a course mapping CSV dataset before import.
     *
     * @param array $rows
     * @return array
     */
    public function preview_course_mapping_rows(array $rows): array {
        $result = [
            'processed' => 0,
            'valid' => 0,
            'invalid' => 0,
            'errors' => [],
            'previewrows' => [],
        ];

        foreach ($rows as $index => $row) {
            $linenumber = $index + 2;
            $rowerrors = [];
            $record = $this->build_course_mapping_record_from_import_row($row, $linenumber, $rowerrors);
            $previewrow = [
                'linenumber' => $linenumber,
                'programme' => trim((string)($row['programmecode'] ?? $row['programmename'] ?? $row['programmeid'] ?? '')),
                'course' => trim((string)($row['courseshortname'] ?? $row['moodlecourseid'] ?? '')),
                'level' => trim((string)($row['levelcode'] ?? '')),
                'semester' => trim((string)($row['semestercode'] ?? $row['semesterid'] ?? '')),
                'coursetype' => trim((string)($row['coursetype'] ?? 'core')),
                'iscore' => trim((string)($row['iscore'] ?? '1')),
                'action' => '',
                'message' => '',
                'valid' => false,
            ];

            if ($record) {
                $existing = $this->repository->get_course_mapping_by_hierarchy(
                    (int)$record->programmeid,
                    (int)$record->moodlecourseid,
                    (int)($record->semesterid ?? 0),
                    (int)($record->levelid ?? 0)
                );
                $previewrow['valid'] = true;
                $previewrow['action'] = $existing
                    ? \get_string('csvactionupdate', 'local_ulms_academics')
                    : \get_string('csvactioncreate', 'local_ulms_academics');
                $previewrow['message'] = \get_string('csvvalidationpassed', 'local_ulms_academics');
                $previewrow['course'] = $this->get_moodle_course_label((int)$record->moodlecourseid);
                $previewrow['programme'] = $this->get_programme_label((int)$record->programmeid);
                $previewrow['semester'] = $this->get_semester_label((int)($record->semesterid ?? 0));
                $previewrow['level'] = $this->get_level_label((int)($record->levelid ?? 0));
                $previewrow['coursetype'] = $this->get_course_type_options()[$record->coursetype] ?? $record->coursetype;
                $previewrow['iscore'] = !empty($record->iscore) ? \get_string('yes') : \get_string('no');
                $result['valid']++;
            } else {
                $previewrow['action'] = \get_string('csvactioninvalid', 'local_ulms_academics');
                $previewrow['message'] = implode(' ', $rowerrors);
                $result['errors'] = array_merge($result['errors'], $rowerrors);
                $result['invalid']++;
            }

            $result['previewrows'][] = $previewrow;
            $result['processed']++;
        }

        return $result;
    }

    /**
     * Imports rows from a course mapping CSV dataset.
     *
     * @param array $rows
     * @return array
     */
    public function import_course_mapping_rows(array $rows): array {
        $result = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            $linenumber = $index + 2;
            $record = $this->build_course_mapping_record_from_import_row($row, $linenumber, $result['errors']);
            if (!$record) {
                continue;
            }

            $existing = $this->repository->get_course_mapping_by_hierarchy(
                (int)$record->programmeid,
                (int)$record->moodlecourseid,
                (int)($record->semesterid ?? 0),
                (int)($record->levelid ?? 0)
            );
            if ($existing) {
                $record->id = $existing->id;
                $result['updated']++;
            } else {
                $result['created']++;
            }

            $this->repository->save_course_mapping($record);
            $result['processed']++;
        }

        return $result;
    }

    /**
     * Returns rows ready for course mapping CSV export.
     *
     * @return array
     */
    public function get_course_mapping_export_rows(
        string $search = '',
        int $facultyid = 0,
        int $departmentid = 0,
        int $programmeid = 0,
        int $semesterfilter = -1,
        string $coursetype = '',
        string $sort = 'faculty',
        string $direction = 'ASC',
        int $levelid = -1,
        int $sessionid = -1
    ): array {
        global $DB;
        $rows = [[
            'programmecode',
            'programmename',
            'courseshortname',
            'coursename',
            'moodlecourseid',
            'levelcode',
            'sessioncode',
            'semestercode',
            'semestername',
            'coursetype',
            'iscore',
        ]];

        $levelbyid = [];
        $sessionbyid = [];
        if ($DB->get_manager()->table_exists('local_ulms_levels')) {
            $levelbyid = $DB->get_records_menu('local_ulms_levels', [], '', 'id, code');
        }
        if ($DB->get_manager()->table_exists('local_ulms_sessions')) {
            $sessionbyid = $DB->get_records_menu('local_ulms_sessions', [], '', 'id, code');
        }

        foreach ($this->get_course_mappings(
            $search,
            $facultyid,
            $departmentid,
            $programmeid,
            $semesterfilter,
            $coursetype,
            $sort,
            $direction,
            0,
            0,
            $levelid,
            $sessionid
        ) as $mapping) {
            $levelidval = (int)($mapping->levelid ?? 0);
            $semestersessionid = (int)($mapping->semestersessionid ?? 0);
            $rows[] = [
                $mapping->programmecode ?? '',
                $mapping->programmename ?? '',
                $mapping->courseshortname ?? '',
                $mapping->coursename ?? '',
                (string)$mapping->moodlecourseid,
                $levelidval > 0 && isset($levelbyid[$levelidval]) ? (string)$levelbyid[$levelidval] : '',
                $semestersessionid > 0 && isset($sessionbyid[$semestersessionid]) ? (string)$sessionbyid[$semestersessionid] : '',
                $mapping->semestercode ?? '',
                $mapping->semestername ?? '',
                $mapping->coursetype ?? 'core',
                !empty($mapping->iscore) ? '1' : '0',
            ];
        }

        return $rows;
    }

    /**
     * Returns starter rows for a course mapping CSV template.
     *
     * @return array
     */
    public function get_course_mapping_template_rows(): array {
        return [
            ['programmecode', 'courseshortname', 'levelcode', 'semestercode', 'coursetype', 'iscore'],
            ['BSC-CS', 'CSC101', '100', 'SEM-1', 'core', '1'],
        ];
    }

    /**
     * Builds a course mapping record from a CSV import row.
     *
     * @param array $row
     * @param int $linenumber
     * @param array $errors
     * @return \stdClass|null
     */
    private function build_course_mapping_record_from_import_row(array $row, int $linenumber, array &$errors): ?\stdClass {
        global $DB;

        $programmeid = (int)($row['programmeid'] ?? 0);
        if ($programmeid <= 0) {
            $programmecode = trim((string)($row['programmecode'] ?? ''));
            if ($programmecode !== '') {
                $programme = $this->get_record_for_entity_by_field('programmes', 'code', $programmecode);
                $programmeid = $programme ? (int)$programme->id : 0;
            }
        }

        if ($programmeid <= 0 && !empty($row['programmename'])) {
            foreach ($this->get_records_for_entity('programmes') as $programme) {
                if (\core_text::strtolower($programme->name) === \core_text::strtolower(trim((string)$row['programmename']))) {
                    $programmeid = (int)$programme->id;
                    break;
                }
            }
        }

        $moodlecourseid = (int)($row['moodlecourseid'] ?? 0);
        if ($moodlecourseid <= 0) {
            $courseshortname = trim((string)($row['courseshortname'] ?? ''));
            if ($courseshortname !== '') {
                foreach ($this->repository->get_moodle_courses() as $course) {
                    if (\core_text::strtolower((string)$course->shortname) === \core_text::strtolower($courseshortname)) {
                        $moodlecourseid = (int)$course->id;
                        break;
                    }
                }
            }
        }

        if ($programmeid <= 0 || $moodlecourseid <= 0) {
            $errors[] = \get_string('mappingcsvrequired', 'local_ulms_academics', $linenumber);
            return null;
        }

        if ($moodlecourseid === 1) {
            $errors[] = \get_string('mappinginvalidsitecourse', 'local_ulms_academics');
            return null;
        }

        $levelid = 0;
        $levelcodein = trim((string)($row['levelcode'] ?? ''));
        if ($levelcodein !== '' && $DB->get_manager()->table_exists('local_ulms_levels')) {
            $level = $DB->get_record('local_ulms_levels', ['code' => $levelcodein], 'id', IGNORE_MISSING);
            if ($level) {
                $levelid = (int)$level->id;
            } else {
                $errors[] = \get_string('mappingcsvinvalidlevel', 'local_ulms_academics', $linenumber);
                return null;
            }
        }

        $semesterid = (int)($row['semesterid'] ?? 0);
        if ($semesterid <= 0) {
            $semestercode = trim((string)($row['semestercode'] ?? ''));
            if ($semestercode !== '') {
                $semester = $this->get_record_for_entity_by_field('semesters', 'code', $semestercode);
                $semesterid = $semester ? (int)$semester->id : 0;
            }
        }

        if ($semesterid <= 0 && !empty($row['semestername'])) {
            foreach ($this->get_records_for_entity('semesters') as $semester) {
                if (\core_text::strtolower($semester->name) === \core_text::strtolower(trim((string)$row['semestername']))) {
                    $semesterid = (int)$semester->id;
                    break;
                }
            }
        }

        if (!empty($row['semestercode'] ?? '') || !empty($row['semestername'] ?? '') || !empty($row['semesterid'] ?? '')) {
            if ($semesterid <= 0) {
                $errors[] = \get_string('mappingcsvsemesterinvalid', 'local_ulms_academics', $linenumber);
                return null;
            }
        }

        $coursetype = trim((string)($row['coursetype'] ?? 'core'));
        if (!array_key_exists($coursetype, $this->get_course_type_options())) {
            $coursetype = 'core';
        }

        $iscorevalue = \core_text::strtolower(trim((string)($row['iscore'] ?? '1')));
        $iscore = in_array($iscorevalue, ['1', 'true', 'yes', 'y', 'core'], true) ? 1 : 0;

        $record = new \stdClass();
        $record->programmeid = $programmeid;
        $record->moodlecourseid = $moodlecourseid;
        $record->semesterid = $semesterid > 0 ? $semesterid : null;
        $record->levelid = $levelid;
        $record->coursetype = $coursetype;
        $record->iscore = $iscore;

        return $record;
    }

    /**
     * Returns a readable Moodle course label.
     *
     * @param int $moodlecourseid
     * @return string
     */
    private function get_moodle_course_label(int $moodlecourseid): string {
        foreach ($this->repository->get_moodle_courses() as $course) {
            if ((int)$course->id === $moodlecourseid) {
                $label = $course->fullname;
                if (!empty($course->shortname)) {
                    $label .= ' (' . $course->shortname . ')';
                }
                return $label;
            }
        }

        return \get_string('notset', 'local_ulms_academics');
    }

    /**
     * Returns a readable programme label.
     *
     * @param int $programmeid
     * @return string
     */
    private function get_programme_label(int $programmeid): string {
        $programme = $this->get_record_for_entity('programmes', $programmeid);
        return $programme ? (string)$programme->name : \get_string('notset', 'local_ulms_academics');
    }

    /**
     * Returns a readable semester label.
     *
     * @param int $semesterid
     * @return string
     */
    private function get_semester_label(int $semesterid): string {
        if ($semesterid <= 0) {
            return \get_string('notset', 'local_ulms_academics');
        }

        $semester = $this->get_record_for_entity('semesters', $semesterid);
        return $semester ? (string)$semester->name : \get_string('notset', 'local_ulms_academics');
    }

    /**
     * Returns a readable level label using the dashboard levels table.
     *
     * @param int $levelid
     * @return string
     */
    private function get_level_label(int $levelid): string {
        global $DB;
        if ($levelid <= 0) {
            return \get_string('notset', 'local_ulms_academics');
        }
        if (!$DB->get_manager()->table_exists('local_ulms_levels')) {
            return \get_string('notset', 'local_ulms_academics');
        }
        $level = $DB->get_record('local_ulms_levels', ['id' => $levelid], 'name, code', IGNORE_MISSING);
        if (!$level) {
            return \get_string('notset', 'local_ulms_academics');
        }
        $label = (string)$level->name;
        if (!empty($level->code)) {
            $label .= ' (' . $level->code . ')';
        }
        return $label;
    }

    /**
     * Builds a preview of rows from a CSV dataset before import.
     *
     * @param string $entity
     * @param array $rows
     * @return array
     */
    public function preview_csv_rows(string $entity, array $rows): array {
        $result = [
            'processed' => 0,
            'valid' => 0,
            'invalid' => 0,
            'errors' => [],
            'previewrows' => [],
        ];

        if ($entity === 'courses') {
            global $DB;
            foreach ($rows as $index => $row) {
                $linenumber = $index + 2;
                $rowerrors = [];
                $shortname = trim((string)($row['shortname'] ?? ''));
                $fullname  = trim((string)($row['fullname'] ?? ''));
                $idnumber  = trim((string)($row['idnumber'] ?? ''));
                $category  = trim((string)($row['category'] ?? ''));
                $visible   = (string)($row['visible'] ?? '1') === '' ? '1' : trim((string)($row['visible'] ?? '1'));

                if ($shortname === '' || $fullname === '') {
                    $rowerrors[] = \get_string('csvmissingrequired', 'local_ulms_academics', $linenumber);
                }

                $categoryid = 0;
                if ($category !== '') {
                    if (is_numeric($category)) {
                        $catrec = $DB->get_record('course_categories', ['id' => (int)$category], 'id', IGNORE_MISSING);
                        $categoryid = $catrec ? (int)$catrec->id : 0;
                    } else {
                        $catrec = $DB->get_record('course_categories', ['idnumber' => $category], 'id', IGNORE_MISSING);
                        if (!$catrec) {
                            $catrec = $DB->get_record('course_categories', ['name' => $category], 'id', IGNORE_MISSING);
                        }
                        $categoryid = $catrec ? (int)$catrec->id : 0;
                    }
                }
                if ($category === '' || $categoryid <= 0) {
                    $misc = $DB->get_record('course_categories', ['name' => 'Miscellaneous'], 'id', IGNORE_MISSING);
                    if (!$misc) {
                        $misc = $DB->get_record_sql("SELECT id FROM {course_categories} ORDER BY id ASC LIMIT 1", [], IGNORE_MISSING);
                    }
                    $categoryid = $misc ? (int)$misc->id : 1;
                }

                $existing = null;
                if ($idnumber !== '') {
                    $existing = $DB->get_record('course', ['idnumber' => $idnumber], 'id,shortname,fullname,visible,category,idnumber', IGNORE_MISSING);
                }
                if (!$existing && $shortname !== '') {
                    $existing = $DB->get_record('course', ['shortname' => $shortname], 'id,shortname,fullname,visible,category,idnumber', IGNORE_MISSING);
                }

                $valid = empty($rowerrors);
                if ($valid) {
                    $result['valid']++;
                } else {
                    $result['invalid']++;
                    $result['errors'] = array_merge($result['errors'], $rowerrors);
                }

                $previewrow = [
                    'linenumber' => $linenumber,
                    'code'       => $shortname,
                    'name'       => $fullname,
                    'parent'     => (string)$categoryid,
                    'status'     => $visible === '0' || strcasecmp($visible, 'no') === 0 || strcasecmp($visible, 'hidden') === 0 ? 'hidden' : 'visible',
                    'action'     => !$valid
                        ? \get_string('csvactioninvalid', 'local_ulms_academics')
                        : ($existing
                            ? \get_string('csvactionupdate', 'local_ulms_academics')
                            : \get_string('csvactioncreate', 'local_ulms_academics')),
                    'message'    => $valid
                        ? \get_string('csvvalidationpassed', 'local_ulms_academics')
                        : implode(' ', $rowerrors),
                    'valid'      => $valid,
                ];
                $result['previewrows'][] = $previewrow;
                $result['processed']++;
            }
            return $result;
        }

        foreach ($rows as $index => $row) {
            $linenumber = $index + 2;
            $rowerrors = [];
            $record = $this->build_record_from_import_row($entity, $row, $linenumber, $rowerrors);
            $previewrow = [
                'linenumber' => $linenumber,
                'code' => trim((string)($row['code'] ?? '')),
                'name' => trim((string)($row['name'] ?? '')),
                'parent' => '',
                'status' => trim((string)($row['status'] ?? '')),
                'action' => '',
                'message' => '',
                'valid' => false,
            ];

            if ($entity === 'departments') {
                $previewrow['parent'] = trim((string)($row['facultycode'] ?? $row['facultyid'] ?? ''));
            } else if ($entity === 'programmes') {
                $previewrow['parent'] = trim((string)($row['departmentcode'] ?? $row['departmentid'] ?? ''));
            }

            if ($record) {
                $existing = $this->get_record_for_entity_by_field($entity, 'code', $record->code);
                $previewrow['valid'] = true;
                $previewrow['action'] = $existing
                    ? \get_string('csvactionupdate', 'local_ulms_academics')
                    : \get_string('csvactioncreate', 'local_ulms_academics');
                $previewrow['message'] = \get_string('csvvalidationpassed', 'local_ulms_academics');
                $previewrow['status'] = $record->status ?? $previewrow['status'];
                $result['valid']++;
            } else {
                $previewrow['action'] = \get_string('csvactioninvalid', 'local_ulms_academics');
                $previewrow['message'] = implode(' ', $rowerrors);
                $result['errors'] = array_merge($result['errors'], $rowerrors);
                $result['invalid']++;
            }

            $result['previewrows'][] = $previewrow;
            $result['processed']++;
        }

        return $result;
    }

    /**
     * Returns summary rows ready for CSV export.
     *
     * @return array
     */
    public function get_summary_export_rows(): array {
        $rows = [[
            \get_string('summaryentity', 'local_ulms_academics'),
            \get_string('summarycount', 'local_ulms_academics'),
        ]];

        foreach ($this->get_summary_counts() as $item) {
            $rows[] = [
                $item['label'],
                (string)$item['count'],
            ];
        }

        return $rows;
    }

    /**
     * Imports rows from a CSV dataset.
     *
     * @param string $entity
     * @param array $rows
     * @return array
     */
    public function import_csv_rows(string $entity, array $rows): array {
        $result = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => [],
        ];

        if ($entity === 'courses') {
            global $CFG, $DB;
            require_once($CFG->dirroot . '/course/lib.php');
            foreach ($rows as $index => $row) {
                $linenumber = $index + 2;
                $shortname = trim((string)($row['shortname'] ?? ''));
                $fullname  = trim((string)($row['fullname'] ?? ''));
                $idnumber  = trim((string)($row['idnumber'] ?? ''));
                $category  = trim((string)($row['category'] ?? ''));
                $visible   = trim((string)($row['visible'] ?? '1'));
                $summary   = trim((string)($row['summary'] ?? ''));
                $format    = trim((string)($row['format'] ?? 'topics'));
                $numsec    = trim((string)($row['numsections'] ?? ''));
                $lang      = trim((string)($row['lang'] ?? ''));

                if ($shortname === '' || $fullname === '') {
                    $result['errors'][] = \get_string('csvmissingrequired', 'local_ulms_academics', $linenumber);
                    continue;
                }

                $categoryid = 0;
                if ($category !== '') {
                    if (is_numeric($category)) {
                        $catrec = $DB->get_record('course_categories', ['id' => (int)$category], 'id', IGNORE_MISSING);
                        $categoryid = $catrec ? (int)$catrec->id : 0;
                    } else {
                        $catrec = $DB->get_record('course_categories', ['idnumber' => $category], 'id', IGNORE_MISSING);
                        if (!$catrec) {
                            $catrec = $DB->get_record('course_categories', ['name' => $category], 'id', IGNORE_MISSING);
                        }
                        $categoryid = $catrec ? (int)$catrec->id : 0;
                    }
                }
                if ($categoryid <= 0) {
                    $misc = $DB->get_record('course_categories', ['name' => 'Miscellaneous'], 'id', IGNORE_MISSING);
                    if (!$misc) {
                        $misc = $DB->get_record_sql("SELECT id FROM {course_categories} ORDER BY id ASC LIMIT 1", [], IGNORE_MISSING);
                    }
                    $categoryid = $misc ? (int)$misc->id : 1;
                }

                $existing = null;
                if ($idnumber !== '') {
                    $existing = $DB->get_record('course', ['idnumber' => $idnumber], '*', IGNORE_MISSING);
                }
                if (!$existing && $shortname !== '') {
                    $existing = $DB->get_record('course', ['shortname' => $shortname], '*', IGNORE_MISSING);
                }

                $visiblenum = ($visible === '0' || strcasecmp($visible, 'no') === 0 || strcasecmp($visible, 'hidden') === 0) ? 0 : 1;

                $data = new \stdClass();
                $data->shortname    = $shortname;
                $data->fullname     = $fullname;
                $data->idnumber     = $idnumber;
                $data->category     = $categoryid;
                $data->visible      = $visiblenum;
                $data->summary      = $summary;
                $data->summaryformat = FORMAT_HTML;
                $data->format       = $format !== '' ? $format : 'topics';
                if ($numsec !== '' && is_numeric($numsec)) {
                    $data->numsections = (int)$numsec;
                }
                if ($lang !== '') {
                    $data->lang = $lang;
                }

                try {
                    if ($existing) {
                        $data->id = (int)$existing->id;
                        update_course($data);
                        $result['updated']++;
                    } else {
                        create_course($data);
                        $result['created']++;
                    }
                    $result['processed']++;
                } catch (\Throwable $e) {
                    $result['errors'][] = 'Line ' . $linenumber . ': ' . s($e->getMessage());
                }
            }
            return $result;
        }

        foreach ($rows as $index => $row) {
            $linenumber = $index + 2;
            $record = $this->build_record_from_import_row($entity, $row, $linenumber, $result['errors']);
            if (!$record) {
                continue;
            }

            $existing = $this->get_record_for_entity_by_field($entity, 'code', $record->code);
            if ($existing) {
                $record->id = $existing->id;
                $result['updated']++;
            } else {
                $result['created']++;
            }

            $this->save_entity_record($entity, $record);
            $result['processed']++;
        }

        return $result;
    }

    /**
     * Builds an entity record from an import row.
     *
     * @param string $entity
     * @param array $row
     * @param int $linenumber
     * @param array $errors
     * @return \stdClass|null
     */
    private function build_record_from_import_row(string $entity, array $row, int $linenumber, array &$errors): ?\stdClass {
        $code = trim((string)($row['code'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));

        if ($code === '' || $name === '') {
            $errors[] = \get_string('csvmissingrequired', 'local_ulms_academics', $linenumber);
            return null;
        }

        $record = new \stdClass();
        $record->code = $code;
        $record->name = $name;

        if (in_array($entity, ['faculties', 'departments', 'programmes'], true)) {
            $record->status = trim((string)($row['status'] ?? 'active')) ?: 'active';
        }

        if ($entity === 'departments') {
            $facultyid = (int)($row['facultyid'] ?? 0);
            if (!$facultyid && !empty($row['facultycode'])) {
                $faculty = $this->get_record_for_entity_by_field('faculties', 'code', trim((string)$row['facultycode']));
                $facultyid = $faculty ? (int)$faculty->id : 0;
            }
            if (!$facultyid) {
                $errors[] = \get_string('csvparentmissing', 'local_ulms_academics', $linenumber);
                return null;
            }
            $record->facultyid = $facultyid;
        }

        if ($entity === 'programmes') {
            $departmentid = (int)($row['departmentid'] ?? 0);
            if (!$departmentid && !empty($row['departmentcode'])) {
                $department = $this->get_record_for_entity_by_field('departments', 'code', trim((string)$row['departmentcode']));
                $departmentid = $department ? (int)$department->id : 0;
            }
            if (!$departmentid) {
                $errors[] = \get_string('csvparentmissing', 'local_ulms_academics', $linenumber);
                return null;
            }

            $record->departmentid = $departmentid;
            $record->awardtype = trim((string)($row['awardtype'] ?? ''));
            $record->durationyears = max(1, (int)($row['durationyears'] ?? 4));
        }

        return $record;
    }

    /**
     * Saves a record for the supplied entity.
     *
     * @param string $entity
     * @param \stdClass $record
     * @return array{success:bool,id:int,message:string}
     */
    public function save_entity_record(string $entity, \stdClass $record): array {
        try {
            $id = $this->repository->save_record($entity, $record);
            return [
                'success' => true,
                'id' => $id,
                'message' => !empty($record->id)
                    ? \get_string('recordupdated', 'local_ulms_academics')
                    : \get_string('recordcreated', 'local_ulms_academics'),
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [
                    '_base' => $e->getMessage(),
                ],
            ];
        } catch (\Throwable $e) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) {
                local_ulms_dashboard_log_operational_error($e, 'academic_structure_service::save_entity_record', [
                    'entity' => $entity,
                    'record' => (array)$record,
                ]);
            }
            return [
                'success' => false,
                'message' => \get_string('recordnotsaved', 'local_ulms_academics'),
                'errors' => ['_base' => \get_string('recordnotsaved', 'local_ulms_academics')],
            ];
        }
    }
}
