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
use local_ulms_academics\local\repository\academic_repository;
use local_ulms_academics\local\service\academic_structure_service;

require_once(__DIR__ . '/../../../config.php');

/**
 * Regression tests for status handling on the academic CSV importer.
 *
 * The importer upserts on academic code, so an omitted status column used to rewrite
 * every matching college, department and programme back to "active". These tests pin the
 * corrected behaviour: a status is only defaulted for brand new records, an existing
 * record always keeps what is stored unless the CSV asks otherwise, and a bulk import can
 * never reactivate an inactive record.
 *
 * @coversNothing
 */
final class csv_import_status_test extends advanced_testcase {

    /**
     * Every test starts from a clean database.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * A brand new record with no status column at all is still created as active.
     */
    public function test_new_record_without_a_status_column_is_stored_active(): void {
        $code = $this->unique_code('CSV-NEW');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'New College',
        ]]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('active', $this->stored_status('faculties', $code));
    }

    /**
     * A brand new record with an empty status cell is still created as active.
     */
    public function test_new_record_with_an_empty_status_cell_is_stored_active(): void {
        $code = $this->unique_code('CSV-NEW');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'New College',
            'status' => '   ',
        ]]);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('active', $this->stored_status('faculties', $code));
    }

    /**
     * An explicit inactive status is honoured for a brand new record.
     */
    public function test_new_record_with_an_explicit_inactive_status_is_stored_inactive(): void {
        $code = $this->unique_code('CSV-NEW');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'New College',
            'status' => 'Inactive',
        ]]);

        $this->assertSame(1, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('inactive', $this->stored_status('faculties', $code));
    }

    /**
     * An existing active record is not rewritten by a missing status column.
     */
    public function test_existing_active_record_keeps_its_status_when_the_status_column_is_missing(): void {
        $code = $this->unique_code('CSV-EXI');
        $this->create_record('faculties', $code, 'active', 'Original Name');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Renamed College',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['created']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('active', $this->stored_status('faculties', $code));
        $this->assertSame('Renamed College', $this->stored_name('faculties', $code));
    }

    /**
     * The reported defect: an existing inactive record must stay inactive when the CSV row
     * carries no status value.
     */
    public function test_existing_inactive_record_keeps_its_status_when_the_status_column_is_missing(): void {
        $code = $this->unique_code('CSV-INA');
        $this->create_record('faculties', $code, 'inactive', 'Retired College');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Retired College',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('inactive', $this->stored_status('faculties', $code));
    }

    /**
     * An existing inactive record must also stay inactive when the status cell is empty.
     */
    public function test_existing_inactive_record_keeps_its_status_when_the_status_cell_is_empty(): void {
        $code = $this->unique_code('CSV-INA');
        $this->create_record('faculties', $code, 'inactive', 'Retired College');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Retired College',
            'status' => '',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('inactive', $this->stored_status('faculties', $code));
    }

    /**
     * Re-importing an inactive record as inactive is a no-op, not an error.
     */
    public function test_existing_inactive_record_reimported_as_inactive_stays_inactive(): void {
        $code = $this->unique_code('CSV-INA');
        $this->create_record('faculties', $code, 'inactive', 'Retired College');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Retired College',
            'status' => 'inactive',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('inactive', $this->stored_status('faculties', $code));
    }

    /**
     * Re-importing an active record as active is a no-op, not an error.
     */
    public function test_existing_active_record_reimported_as_active_stays_active(): void {
        $code = $this->unique_code('CSV-EXI');
        $this->create_record('faculties', $code, 'active', 'Live College');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Live College',
            'status' => 'ACTIVE',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('active', $this->stored_status('faculties', $code));
    }

    /**
     * A bulk import may not lift the restriction on an inactive record.
     */
    public function test_bulk_import_cannot_reactivate_an_inactive_record(): void {
        $code = $this->unique_code('CSV-INA');
        $this->create_record('faculties', $code, 'inactive', 'Retired College');

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Retired College',
            'status' => 'active',
        ]]);

        $expected = get_string('csvreactivationblocked', 'local_ulms_academics', (object)[
            'line' => 2,
            'code' => $code,
        ]);

        $this->assertSame([$expected], $result['errors']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('inactive', $this->stored_status('faculties', $code));
    }

    /**
     * The preview refuses the same transition and reports the status that will actually stand.
     */
    public function test_preview_flags_the_prohibited_reactivation_and_shows_the_stored_status(): void {
        $code = $this->unique_code('CSV-INA');
        $this->create_record('faculties', $code, 'inactive', 'Retired College');

        $preview = $this->service()->preview_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Retired College',
            'status' => 'active',
        ]]);

        $expected = get_string('csvreactivationblocked', 'local_ulms_academics', (object)[
            'line' => 2,
            'code' => $code,
        ]);

        $this->assertSame(1, $preview['invalid']);
        $this->assertSame(0, $preview['valid']);
        $this->assertSame([$expected], $preview['errors']);
        $this->assertFalse($preview['previewrows'][0]['valid']);
        $this->assertSame(
            get_string('csvactioninvalid', 'local_ulms_academics'),
            $preview['previewrows'][0]['action']
        );
        $this->assertSame($expected, $preview['previewrows'][0]['message']);
        // The effective status is the stored one, because the rejected row writes nothing.
        $this->assertSame('inactive', $preview['previewrows'][0]['status']);
    }

    /**
     * The preview and the import path agree on every supported status scenario.
     */
    public function test_preview_and_import_agree_on_every_status_scenario(): void {
        $scenarios = [
            'new record, no status' => [null, '', true, 'active'],
            'new record, explicit active' => [null, 'active', true, 'active'],
            'new record, explicit inactive' => [null, 'inactive', true, 'inactive'],
            'existing active, no status' => ['active', '', true, 'active'],
            'existing active, explicit active' => ['active', 'active', true, 'active'],
            'existing active, explicit inactive' => ['active', 'inactive', true, 'inactive'],
            'existing inactive, no status' => ['inactive', '', true, 'inactive'],
            'existing inactive, explicit inactive' => ['inactive', 'inactive', true, 'inactive'],
            'existing inactive, attempted reactivation' => ['inactive', 'active', false, 'inactive'],
            'existing active, unrecognised status' => ['active', 'archived', false, 'active'],
            'new record, unrecognised status' => [null, 'archived', false, null],
        ];

        foreach ($scenarios as $label => [$initial, $csvstatus, $expectedvalid, $expectedstored]) {
            $code = $this->unique_code('CSV-PAR');
            if ($initial !== null) {
                $this->create_record('faculties', $code, $initial, 'Parity College');
            }

            $row = ['code' => $code, 'name' => 'Parity College', 'status' => $csvstatus];

            $preview = $this->service()->preview_csv_rows('faculties', [$row]);
            $this->assertSame(
                $expectedvalid ? 1 : 0,
                $preview['valid'],
                'preview validity for: ' . $label
            );
            $this->assertSame(
                $expectedvalid ? 0 : 1,
                $preview['invalid'],
                'preview invalidity for: ' . $label
            );

            $result = $this->service()->import_csv_rows('faculties', [$row]);
            $this->assertSame(
                $expectedvalid ? 1 : 0,
                $result['processed'],
                'import processed count for: ' . $label
            );
            $this->assertSame(
                $expectedvalid,
                $result['errors'] === [],
                'import error presence for: ' . $label
            );

            $this->assertSame($expectedstored, $this->stored_status('faculties', $code), 'stored status for: ' . $label);
        }
    }

    /**
     * An unrecognised status is refused, so it cannot bypass validation on either path.
     */
    public function test_unrecognised_status_is_rejected_on_both_paths(): void {
        $code = $this->unique_code('CSV-BAD');
        $this->create_record('faculties', $code, 'active', 'Live College');

        $row = ['code' => $code, 'name' => 'Live College', 'status' => 'archived'];
        $expected = get_string('csvinvalidstatus', 'local_ulms_academics', 2);

        $preview = $this->service()->preview_csv_rows('faculties', [$row]);
        $this->assertSame(1, $preview['invalid']);
        $this->assertSame([$expected], $preview['errors']);

        $result = $this->service()->import_csv_rows('faculties', [$row]);
        $this->assertSame([$expected], $result['errors']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame('active', $this->stored_status('faculties', $code));
    }

    /**
     * A legacy inactive spelling already stored is not reactivated by a missing status.
     */
    public function test_import_preserves_the_restriction_of_a_legacy_stored_inactive_alias(): void {
        global $DB;

        $code = $this->unique_code('CSV-LEG');
        $this->create_record('faculties', $code, 'active', 'Legacy College');
        $DB->set_field('local_ulms_faculties', 'status', 'disabled', ['code' => $code]);

        $result = $this->service()->import_csv_rows('faculties', [[
            'code' => $code,
            'name' => 'Legacy College',
        ]]);

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['errors']);
        $this->assertSame('inactive', $this->stored_status('faculties', $code));
    }

    /**
     * The guard also covers programmes, including parent resolution from the CSV.
     */
    public function test_programme_reactivation_is_blocked_and_parent_resolution_still_works(): void {
        $service = $this->service();

        $departmentcode = $this->unique_code('CSV-DEP');
        $this->create_record('departments', $departmentcode, 'active', 'Parity Department');
        $programmecode = $this->unique_code('CSV-PRG');
        $this->create_record('programmes', $programmecode, 'inactive', 'Retired Programme');

        $blocked = $service->import_csv_rows('programmes', [[
            'code' => $programmecode,
            'name' => 'Retired Programme',
            'departmentcode' => $departmentcode,
            'status' => 'active',
        ]]);

        $this->assertSame(0, $blocked['processed']);
        $this->assertSame('inactive', $this->stored_status('programmes', $programmecode));

        // The same parent reference still resolves for a row that is allowed to be written.
        $newprogrammecode = $this->unique_code('CSV-PRG');
        $created = $service->import_csv_rows('programmes', [[
            'code' => $newprogrammecode,
            'name' => 'New Programme',
            'departmentcode' => $departmentcode,
            'status' => '',
        ]]);

        $this->assertSame(1, $created['created']);
        $this->assertSame([], $created['errors']);

        $stored = (new academic_repository())->get_record_by_field('programmes', 'code', $newprogrammecode);
        $this->assertNotFalse($stored);
        $this->assertSame('active', (string)$stored->status);

        $department = (new academic_repository())->get_record_by_field('departments', 'code', $departmentcode);
        $this->assertNotFalse($department);
        $this->assertSame((int)$department->id, (int)$stored->departmentid);
    }

    /**
     * Returns the shared academic structure service.
     *
     * @return academic_structure_service
     */
    private function service(): academic_structure_service {
        return new academic_structure_service();
    }

    /**
     * Builds a code that cannot collide with another test.
     *
     * @param string $prefix
     * @return string
     */
    private function unique_code(string $prefix): string {
        return $prefix . '-' . random_string(6);
    }

    /**
     * Stores a record through the normal repository write path.
     *
     * @param string $entity
     * @param string $code
     * @param string $status
     * @param string $name
     * @return int
     */
    private function create_record(string $entity, string $code, string $status, string $name): int {
        $repository = new academic_repository();

        $record = (object)[
            'code' => $code,
            'name' => $name,
            'status' => $status,
        ];

        if ($entity === 'departments') {
            $record->facultyid = $this->create_faculty();
        }

        if ($entity === 'programmes') {
            $parentcode = $this->unique_code('CSV-PARENT');
            $record->departmentid = $repository->save_record('departments', (object)[
                'code' => $parentcode,
                'name' => 'Parent ' . $parentcode,
                'status' => 'active',
                'facultyid' => $this->create_faculty(),
            ]);
            $record->awardtype = 'BSc';
            $record->durationyears = 4;
        }

        return $repository->save_record($entity, $record);
    }

    /**
     * Stores a throw away faculty so a department (or programme) has a valid parent.
     *
     * @return int
     */
    private function create_faculty(): int {
        $code = $this->unique_code('CSV-FACULTY');

        return (new academic_repository())->save_record('faculties', (object)[
            'code' => $code,
            'name' => 'Faculty ' . $code,
            'status' => 'active',
        ]);
    }

    /**
     * Returns the status currently stored for a code.
     *
     * @param string $entity
     * @param string $code
     * @return string|null
     */
    private function stored_status(string $entity, string $code): ?string {
        $record = (new academic_repository())->get_record_by_field($entity, 'code', $code);

        return $record ? (string)$record->status : null;
    }

    /**
     * Returns the name currently stored for a code.
     *
     * @param string $entity
     * @param string $code
     * @return string|null
     */
    private function stored_name(string $entity, string $code): ?string {
        $record = (new academic_repository())->get_record_by_field($entity, 'code', $code);

        return $record ? (string)$record->name : null;
    }
}
