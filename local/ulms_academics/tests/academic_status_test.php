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

namespace local_ulms_academics;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use local_ulms_academics\local\academic_status;
use local_ulms_academics\local\repository\academic_repository;
use local_ulms_academics\local\service\academic_structure_service;

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../lib.php');

/**
 * Regression tests for the canonical academic status policy and the programme
 * enrolment eligibility that depends on it.
 *
 * @coversNothing
 */
final class academic_status_test extends advanced_testcase {

    /**
     * Canonical values are accepted verbatim.
     */
    public function test_normalize_accepts_canonical_values(): void {
        $this->assertSame('active', academic_status::normalize('active'));
        $this->assertSame('inactive', academic_status::normalize('inactive'));
    }

    /**
     * Mixed case and surrounding whitespace are normalised, not rejected.
     */
    public function test_normalize_handles_mixed_case_and_whitespace(): void {
        $this->assertSame('active', academic_status::normalize('ACTIVE'));
        $this->assertSame('active', academic_status::normalize('Active'));
        $this->assertSame('active', academic_status::normalize('  aCtIvE  '));
        $this->assertSame('inactive', academic_status::normalize('Inactive'));
        $this->assertSame('inactive', academic_status::normalize('INACTIVE'));
        $this->assertSame('inactive', academic_status::normalize("\tInAcTiVe\n"));
    }

    /**
     * Legacy and boolean-ish spellings map onto the canonical vocabulary.
     */
    public function test_normalize_handles_legacy_alias_values(): void {
        foreach (['enabled', 'Enabled', 'enable', 'yes', 'YES', 'true', 'on', '1'] as $legacy) {
            $this->assertSame('active', academic_status::normalize($legacy), 'legacy active: ' . $legacy);
        }

        foreach (['disabled', 'Disabled', 'disable', 'suspended', 'SUSPENDED', 'no', 'false', 'off', '0'] as $legacy) {
            $this->assertSame('inactive', academic_status::normalize($legacy), 'legacy inactive: ' . $legacy);
        }
    }

    /**
     * Unrecognised values are rejected rather than silently coerced.
     */
    public function test_normalize_rejects_unknown_values(): void {
        foreach (['archived', 'pending', 'maybe', 'act', 'activee', '2', 'null'] as $invalid) {
            $this->assertNull(academic_status::normalize($invalid), 'should reject: ' . $invalid);
        }
    }

    /**
     * Missing (null / empty / whitespace) statuses have no canonical value when read.
     */
    public function test_missing_status_is_not_canonical_on_read(): void {
        $this->assertTrue(academic_status::is_missing(null));
        $this->assertTrue(academic_status::is_missing(''));
        $this->assertTrue(academic_status::is_missing('   '));

        $this->assertNull(academic_status::normalize(null));
        $this->assertNull(academic_status::normalize(''));
        $this->assertNull(academic_status::normalize('   '));

        // Fail-safe: a record with no explicit status is NOT eligible.
        $this->assertFalse(academic_status::is_active(''));
        $this->assertFalse(academic_status::is_active(null));
        $this->assertFalse(academic_status::is_inactive(''));
    }

    /**
     * Missing statuses default to "active" only when writing a record.
     */
    public function test_missing_status_defaults_to_active_for_storage(): void {
        $this->assertSame('active', academic_status::normalize_for_storage(null));
        $this->assertSame('active', academic_status::normalize_for_storage(''));
        $this->assertSame('active', academic_status::normalize_for_storage('   '));
        $this->assertSame('active', academic_status::normalize_for_storage('ACTIVE'));
        $this->assertSame('inactive', academic_status::normalize_for_storage('Inactive'));
        $this->assertNull(academic_status::normalize_for_storage('archived'));
    }

    /**
     * The active/inactive predicates agree with the canonical vocabulary.
     */
    public function test_active_and_inactive_predicates(): void {
        $this->assertTrue(academic_status::is_active('ACTIVE'));
        $this->assertFalse(academic_status::is_active('inactive'));
        $this->assertFalse(academic_status::is_active('archived'));

        $this->assertTrue(academic_status::is_inactive('Disabled'));
        $this->assertFalse(academic_status::is_inactive('active'));
        $this->assertFalse(academic_status::is_inactive('archived'));
    }

    /**
     * The dropdown options and the validator share exactly the same vocabulary.
     */
    public function test_choices_match_validator_vocabulary(): void {
        $this->assertSame(['active', 'inactive'], academic_status::get_choices());
        $this->assertSame(academic_status::get_choices(), array_keys(academic_status::get_options()));

        foreach (array_keys(academic_status::get_options()) as $choice) {
            $this->assertTrue(academic_status::is_missing($choice) === false);
            $this->assertNotNull(academic_status::normalize($choice));
        }
    }

    /**
     * The repository normalises mixed-case statuses and stores the canonical value.
     */
    public function test_repository_normalises_status_on_write(): void {
        $this->resetAfterTest(true);
        $repository = new academic_repository();

        $activecode = 'CASE-ACT-' . random_string(6);
        $activeid = $repository->save_record('faculties', (object)[
            'code' => $activecode,
            'name' => 'Mixed Case Active College',
            'status' => 'ACTIVE',
        ]);
        $this->assertSame('active', $repository->get_record('faculties', $activeid)->status);

        $inactivecode = 'CASE-INA-' . random_string(6);
        $inactiveid = $repository->save_record('faculties', (object)[
            'code' => $inactivecode,
            'name' => 'Mixed Case Inactive College',
            'status' => '  Inactive ',
        ]);
        $this->assertSame('inactive', $repository->get_record('faculties', $inactiveid)->status);

        $missingcode = 'CASE-MIS-' . random_string(6);
        $missingid = $repository->save_record('faculties', (object)[
            'code' => $missingcode,
            'name' => 'Missing Status College',
            'status' => '',
        ]);
        $this->assertSame('active', $repository->get_record('faculties', $missingid)->status);
    }

    /**
     * The repository rejects statuses outside the canonical vocabulary.
     */
    public function test_repository_rejects_invalid_status_on_write(): void {
        $this->resetAfterTest(true);
        $repository = new academic_repository();

        $this->expectException(\InvalidArgumentException::class);
        $repository->save_record('faculties', (object)[
            'code' => 'CASE-BAD-' . random_string(6),
            'name' => 'Invalid Status College',
            'status' => 'archived',
        ]);
    }

    /**
     * The manage-list status filter understands mixed-case input.
     */
    public function test_repository_filter_normalises_status_input(): void {
        $this->resetAfterTest(true);
        $repository = new academic_repository();

        $activecode = 'FLT-ACT-' . random_string(6);
        $inactivecode = 'FLT-INA-' . random_string(6);
        $repository->save_record('faculties', (object)[
            'code' => $activecode,
            'name' => 'Filter Active College',
            'status' => 'active',
        ]);
        $repository->save_record('faculties', (object)[
            'code' => $inactivecode,
            'name' => 'Filter Inactive College',
            'status' => 'inactive',
        ]);

        $activerows = $repository->get_filtered_records('faculties', 'Filter', 'ACTIVE', 'name', 'ASC', 0, 100);
        $activecodes = array_map(static fn($row): string => $row->code, $activerows);
        $this->assertContains($activecode, $activecodes);
        $this->assertNotContains($inactivecode, $activecodes);

        $inactiverows = $repository->get_filtered_records('faculties', 'Filter', 'Inactive', 'name', 'ASC', 0, 100);
        $inactivecodes = array_map(static fn($row): string => $row->code, $inactiverows);
        $this->assertContains($inactivecode, $inactivecodes);
        $this->assertNotContains($activecode, $inactivecodes);
    }

    /**
     * Activation targets exactly one named record and never touches its neighbours.
     */
    public function test_activate_entity_record_only_activates_the_named_record(): void {
        $this->resetAfterTest(true);
        $repository = new academic_repository();
        $service = new academic_structure_service();

        $firstcode = 'ACT-ONE-' . random_string(6);
        $secondcode = 'ACT-TWO-' . random_string(6);
        $firstid = $repository->save_record('faculties', (object)[
            'code' => $firstcode,
            'name' => 'Activation Target College',
            'status' => 'inactive',
        ]);
        $secondid = $repository->save_record('faculties', (object)[
            'code' => $secondcode,
            'name' => 'Activation Bystander College',
            'status' => 'inactive',
        ]);

        $result = $service->activate_entity_record('faculties', $firstid);
        $this->assertTrue($result['success']);

        $this->assertSame('active', $repository->get_record('faculties', $firstid)->status);
        $this->assertSame('inactive', $repository->get_record('faculties', $secondid)->status);
    }

    /**
     * Activation is a no-op on entities that carry no status, and on missing records.
     */
    public function test_activate_entity_record_rejects_unsupported_entity_and_missing_record(): void {
        $this->resetAfterTest(true);
        $service = new academic_structure_service();

        $unsupported = $service->activate_entity_record('sessions', 1);
        $this->assertFalse($unsupported['success']);

        $missing = $service->activate_entity_record('programmes', 0);
        $this->assertFalse($missing['success']);
    }

    /**
     * Activating an already-active record succeeds without changing anything.
     */
    public function test_activate_entity_record_is_idempotent(): void {
        $this->resetAfterTest(true);
        $repository = new academic_repository();
        $service = new academic_structure_service();

        $code = 'ACT-IDEM-' . random_string(6);
        $id = $repository->save_record('faculties', (object)[
            'code' => $code,
            'name' => 'Idempotent Activation College',
            'status' => 'ACTIVE',
        ]);
        $before = $repository->get_record('faculties', $id);

        $result = $service->activate_entity_record('faculties', $id);
        $this->assertTrue($result['success']);

        $after = $repository->get_record('faculties', $id);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->timemodified, $after->timemodified);
    }

    /**
     * College -> department -> programme relationships survive normalisation.
     */
    public function test_academic_hierarchy_relationships_remain_correct(): void {
        $this->resetAfterTest(true);
        $repository = new academic_repository();

        $facultyid = $repository->save_record('faculties', (object)[
            'code' => 'HIER-FAC-' . random_string(6),
            'name' => 'Hierarchy College',
            'status' => 'ACTIVE',
        ]);
        $departmentid = $repository->save_record('departments', (object)[
            'facultyid' => $facultyid,
            'code' => 'HIER-DEP-' . random_string(6),
            'name' => 'Hierarchy Department',
            'status' => 'Active',
        ]);
        $programmeid = $repository->save_record('programmes', (object)[
            'departmentid' => $departmentid,
            'code' => 'HIER-PRG-' . random_string(6),
            'name' => 'Hierarchy Programme',
            'awardtype' => 'BSc',
            'durationyears' => 4,
            'status' => 'inactive',
        ]);

        $programme = $repository->get_record('programmes', $programmeid);
        $this->assertSame($departmentid, (int)$programme->departmentid);
        $this->assertSame('inactive', $programme->status);

        $department = $repository->get_record('departments', $departmentid);
        $this->assertSame($facultyid, (int)$department->facultyid);
        $this->assertSame('active', $department->status);

        $faculty = $repository->get_record('faculties', $facultyid);
        $this->assertSame('active', $faculty->status);
    }

    /**
     * A programme that does not exist is not eligible and must not become eligible.
     */
    public function test_enrol_helper_is_a_no_op_for_unknown_programme(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->assertSame(0, local_ulms_academics_enrol_user_into_programme_courses((int)$user->id, 0));
        $this->assertSame(0, local_ulms_academics_enrol_user_into_programme_courses((int)$user->id, 999999));
    }

    /**
     * The enrolment helper must not raise a database exception for a legitimate
     * active programme (regression: it previously filtered on a non-existent
     * "status" column of local_ulms_programme_courses / local_ulms_semesters).
     */
    public function test_enrol_helper_enrols_into_active_programme_courses(): void {
        $this->resetAfterTest(true);
        global $DB;

        $fixture = $this->create_programme_fixture('active');
        $user = $this->getDataGenerator()->create_user();

        $enrolled = local_ulms_academics_enrol_user_into_programme_courses(
            (int)$user->id,
            $fixture->programmeid,
            $fixture->semesterid
        );

        $this->assertSame(1, $enrolled);
        $coursectx = \context_course::instance($fixture->courseid);
        $this->assertTrue(is_enrolled($coursectx, $user->id, '', true));
    }

    /**
     * An inactive programme keeps its restriction: no enrolment happens.
     */
    public function test_enrol_helper_skips_inactive_programme(): void {
        $this->resetAfterTest(true);
        global $DB;

        $fixture = $this->create_programme_fixture('inactive');
        $user = $this->getDataGenerator()->create_user();

        $enrolled = local_ulms_academics_enrol_user_into_programme_courses(
            (int)$user->id,
            $fixture->programmeid,
            $fixture->semesterid
        );

        $this->assertSame(0, $enrolled);
        $coursectx = \context_course::instance($fixture->courseid);
        $this->assertFalse(is_enrolled($coursectx, $user->id, '', true));
    }

    /**
     * A mixed-case stored status is still recognised as eligible.
     */
    public function test_enrol_helper_accepts_mixed_case_active_status(): void {
        $this->resetAfterTest(true);
        global $DB;

        $fixture = $this->create_programme_fixture('ACTIVE');
        $user = $this->getDataGenerator()->create_user();

        $enrolled = local_ulms_academics_enrol_user_into_programme_courses(
            (int)$user->id,
            $fixture->programmeid,
            $fixture->semesterid
        );

        $this->assertSame(1, $enrolled);
    }

    /**
     * With no explicit semester and no "current" semester the helper must still
     * run the mapping query without a database error.
     */
    public function test_enrol_helper_tolerates_absent_current_semester(): void {
        $this->resetAfterTest(true);
        global $DB;

        $fixture = $this->create_programme_fixture('active');
        $DB->set_field('local_ulms_semesters', 'iscurrent', 0, ['id' => $fixture->semesterid]);

        $user = $this->getDataGenerator()->create_user();
        $enrolled = local_ulms_academics_enrol_user_into_programme_courses((int)$user->id, $fixture->programmeid);

        $this->assertSame(1, $enrolled);
    }

    /**
     * Creates a college/department/programme hierarchy with a mapped course.
     *
     * @param string $programmestatus Raw status value to store for the programme.
     * @return \stdClass Fixture identifiers.
     */
    private function create_programme_fixture(string $programmestatus): \stdClass {
        global $DB;

        $repository = new academic_repository();
        $suffix = random_string(6);

        $facultyid = $repository->save_record('faculties', (object)[
            'code' => 'ENR-FAC-' . $suffix,
            'name' => 'Enrolment College ' . $suffix,
            'status' => 'active',
        ]);
        $departmentid = $repository->save_record('departments', (object)[
            'facultyid' => $facultyid,
            'code' => 'ENR-DEP-' . $suffix,
            'name' => 'Enrolment Department ' . $suffix,
            'status' => 'active',
        ]);
        $programmeid = $DB->insert_record('local_ulms_programmes', (object)[
            'departmentid' => $departmentid,
            'code' => 'ENR-PRG-' . $suffix,
            'name' => 'Enrolment Programme ' . $suffix,
            'awardtype' => 'BSc',
            'durationyears' => 4,
            // Written directly so the raw stored value (including mixed case) is exercised.
            'status' => $programmestatus,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $sessionid = $DB->insert_record('local_ulms_sessions', (object)[
            'code' => 'ENR-SES-' . $suffix,
            'name' => 'Enrolment Session ' . $suffix,
            'startdate' => time(),
            'enddate' => time() + YEARSECS,
            'iscurrent' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $semesterid = $DB->insert_record('local_ulms_semesters', (object)[
            'sessionid' => $sessionid,
            'code' => 'ENR-SEM-' . $suffix,
            'name' => 'Enrolment Semester ' . $suffix,
            'startdate' => time(),
            'enddate' => time() + WEEKSECS * 12,
            'iscurrent' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $course = $this->getDataGenerator()->create_course();
        $DB->insert_record('local_ulms_programme_courses', (object)[
            'programmeid' => $programmeid,
            'moodlecourseid' => $course->id,
            'semesterid' => $semesterid,
            'levelid' => 0,
            'coursetype' => 'core',
            'iscore' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        return (object)[
            'facultyid' => $facultyid,
            'departmentid' => $departmentid,
            'programmeid' => $programmeid,
            'semesterid' => $semesterid,
            'courseid' => (int)$course->id,
        ];
    }
}
