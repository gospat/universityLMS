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
use xmldb_field;
use xmldb_index;
use xmldb_table;

require_once(__DIR__ . '/../../../config.php');

/**
 * Regression tests for the {local_ulms_programme_courses}.levelid schema repair.
 *
 * Savepoint 2026091501 introduced the levelid column plus the academic hierarchy indexes,
 * but it lives behind an "if ($oldversion < 2026091501)" guard while version.php already
 * reported a higher plugin version. Installations whose stored plugin version was already
 * past that point therefore never executed the block: the join table kept its legacy shape
 * (no levelid) while db/install.xml and academic_repository both query the column. The
 * Course Mapping page then aborted with a dml_read_exception inside
 * academic_repository::count_course_mappings().
 *
 * These tests reproduce that failure on a simulated legacy schema and pin the repair
 * performed by savepoint 2026101802, including the queries the repair unblocks.
 *
 * @coversNothing
 */
final class course_mapping_schema_upgrade_test extends advanced_testcase {

    /** @var string Table that carries the repaired column. */
    private const TABLE = 'local_ulms_programme_courses';

    /**
     * Every test starts from a clean database.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * MySQL does not roll back DDL, so the simulated legacy schema must be repaired here
     * before Moodle restores the captured table data for the next test.
     */
    protected function tearDown(): void {
        $this->restore_schema();
        parent::tearDown();
    }

    /**
     * The reported production failure: with levelid missing, counting course mappings
     * aborts with a dml_read_exception.
     */
    public function test_course_mapping_count_fails_while_levelid_is_missing(): void {
        $programme = $this->create_programme();
        $course = $this->create_moodle_course('Legacy Failure');

        $this->simulate_legacy_schema();
        $this->insert_mapping((int)$programme->programmeid, (int)$course->id);

        $this->assertFalse(
            $this->db_manager()->field_exists(new xmldb_table(self::TABLE), $this->levelid_field())
        );

        $this->expectException(\dml_read_exception::class);
        (new academic_repository())->count_course_mappings(programmeid: (int)$programme->programmeid);
    }

    /**
     * The repair adds the column and the hierarchy indexes, drops the superseded legacy
     * uniqueness and keeps every pre-existing mapping visible on the default listing.
     */
    public function test_legacy_upgrade_adds_levelid_and_preserves_existing_mappings(): void {
        global $DB;

        $programme = $this->create_programme();
        $course = $this->create_moodle_course('Legacy Preserved');

        $this->simulate_legacy_schema();
        $mappingid = $this->insert_mapping((int)$programme->programmeid, (int)$course->id);

        $dbman = $this->db_manager();
        $table = new xmldb_table(self::TABLE);
        $legacyindex = new xmldb_index('programme_course_unique', XMLDB_INDEX_UNIQUE, ['programmeid', 'moodlecourseid']);

        $this->assertFalse($dbman->field_exists($table, $this->levelid_field()));
        $this->assertTrue($dbman->index_exists($table, $legacyindex));

        $this->assertTrue($this->run_upgrade(2026100102));

        $this->assertTrue($dbman->field_exists($table, $this->levelid_field()));
        $this->assertTrue($dbman->index_exists($table, $this->hierarchy_unique_index()));
        $this->assertTrue($dbman->index_exists($table, $this->level_scope_index()));
        $this->assertFalse($dbman->index_exists($table, $legacyindex));

        $preserved = $DB->get_record(self::TABLE, ['id' => $mappingid], '*', MUST_EXIST);
        $this->assertSame(0, (int)$preserved->levelid);

        $repository = new academic_repository();
        $programmeid = (int)$programme->programmeid;

        $this->assertSame(1, $repository->count_course_mappings(programmeid: $programmeid));
        $this->assertSame(1, $repository->count_course_mappings(programmeid: $programmeid, levelid: 0));

        $rows = $repository->get_course_mappings(programmeid: $programmeid);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertSame(0, (int)$row->levelid);
        $this->assertNull($row->levelcode);
    }

    /**
     * Re-running the repair on an already repaired schema changes nothing and still
     * records the savepoint.
     */
    public function test_legacy_upgrade_is_idempotent(): void {
        global $DB;

        $programme = $this->create_programme();
        $course = $this->create_moodle_course('Legacy Idempotent');

        $this->simulate_legacy_schema();
        $mappingid = $this->insert_mapping((int)$programme->programmeid, (int)$course->id);

        $this->assertTrue($this->run_upgrade(2026100102));
        $this->assertTrue($this->run_upgrade(2026100102));

        $dbman = $this->db_manager();
        $table = new xmldb_table(self::TABLE);

        $this->assertTrue($dbman->field_exists($table, $this->levelid_field()));
        $this->assertTrue($dbman->index_exists($table, $this->hierarchy_unique_index()));
        $this->assertTrue($dbman->index_exists($table, $this->level_scope_index()));

        $this->assertSame(1, $DB->count_records(self::TABLE, ['id' => $mappingid]));
        $this->assertSame(0, (int)$DB->get_field(self::TABLE, 'levelid', ['id' => $mappingid]));
        $this->assertSame(2026101802, (int)get_config('local_ulms_academics', 'version'));
    }

    /**
     * A site installed from the current install.xml already reports a version past the
     * hierarchy savepoint without ever running it; the repair must be a no-op there and
     * must not re-scope the level-specific mappings it finds.
     */
    public function test_upgrade_is_a_no_op_when_the_schema_is_already_correct(): void {
        global $DB;

        $programme = $this->create_programme();
        $levelid = $this->create_level('Level Alpha');
        $course = $this->create_moodle_course('Legacy Current');

        $mappingid = $this->insert_mapping((int)$programme->programmeid, (int)$course->id, $levelid);

        $dbman = $this->db_manager();
        $table = new xmldb_table(self::TABLE);
        $this->assertTrue($dbman->field_exists($table, $this->levelid_field()));

        $this->assertTrue($this->run_upgrade(2026101801));

        $this->assertTrue($dbman->field_exists($table, $this->levelid_field()));
        $this->assertTrue($dbman->index_exists($table, $this->hierarchy_unique_index()));
        $this->assertTrue($dbman->index_exists($table, $this->level_scope_index()));
        $this->assertSame($levelid, (int)$DB->get_field(self::TABLE, 'levelid', ['id' => $mappingid]));

        $repository = new academic_repository();
        $programmeid = (int)$programme->programmeid;

        $this->assertSame(1, $repository->count_course_mappings(programmeid: $programmeid, levelid: $levelid));
        $this->assertSame(0, $repository->count_course_mappings(programmeid: $programmeid, levelid: 0));

        $rows = $repository->get_course_mappings(programmeid: $programmeid, levelid: $levelid);
        $this->assertCount(1, $rows);

        $row = reset($rows);
        $this->assertSame($levelid, (int)$row->levelid);
        $this->assertSame('Level Alpha', (string)$row->levelname);
    }

    /**
     * After the repair, listing, summary totals, level filters, level sorting and
     * pagination all operate on the restored column.
     */
    public function test_repaired_schema_supports_filters_sorting_and_pagination(): void {
        $programme = $this->create_programme();
        $levela = $this->create_level('Level Alpha');
        $levelb = $this->create_level('Level Beta');

        $coursewide = $this->create_moodle_course('Wide Mapping');
        $coursealpha = $this->create_moodle_course('Alpha Mapping');
        $coursebeta = $this->create_moodle_course('Beta Mapping');

        $this->simulate_legacy_schema();
        $this->assertTrue($this->run_upgrade(2026100102));

        // Inserted after the repair so the level scopes are actually persisted: while the
        // column was missing these rows would all have been stored as levelid = 0.
        $this->insert_mapping((int)$programme->programmeid, (int)$coursewide->id);
        $this->insert_mapping((int)$programme->programmeid, (int)$coursealpha->id, $levela);
        $this->insert_mapping((int)$programme->programmeid, (int)$coursebeta->id, $levelb);

        $repository = new academic_repository();
        $programmeid = (int)$programme->programmeid;

        $this->assertSame(3, $repository->count_course_mappings(programmeid: $programmeid));
        $this->assertSame(1, $repository->count_course_mappings(programmeid: $programmeid, levelid: 0));
        $this->assertSame(1, $repository->count_course_mappings(programmeid: $programmeid, levelid: $levela));
        $this->assertSame(1, $repository->count_course_mappings(programmeid: $programmeid, levelid: $levelb));

        $summary = $repository->get_course_mapping_summary(programmeid: $programmeid);
        $this->assertSame(3, $summary['totalmappings']);
        $this->assertSame(3, $summary['totalcourses']);
        $this->assertSame(1, $summary['totalprogrammes']);
        $this->assertSame(3, $summary['coremappings']);

        $sorted = $repository->get_course_mappings(programmeid: $programmeid, sort: 'level', direction: 'ASC');
        $levelnames = array_map(
            static fn($row) => (string)($row->levelname ?? ''),
            array_values($sorted)
        );
        $this->assertSame(['', 'Level Alpha', 'Level Beta'], $levelnames);

        $firstpage = $repository->get_course_mappings(
            programmeid: $programmeid,
            sort: 'level',
            direction: 'ASC',
            limitfrom: 0,
            limitnum: 2
        );
        $secondpage = $repository->get_course_mappings(
            programmeid: $programmeid,
            sort: 'level',
            direction: 'ASC',
            limitfrom: 2,
            limitnum: 2
        );
        $this->assertCount(2, $firstpage);
        $this->assertCount(1, $secondpage);
    }

    /**
     * After the repair, the CSV template, importer and exporter all round-trip the
     * academic level relationship through the restored column.
     */
    public function test_repaired_schema_supports_csv_import_and_export(): void {
        $programme = $this->create_programme();
        $levelcode = 'LVL-' . random_string(4);
        $levelid = $this->create_level('Level Gamma', $levelcode);
        $course = $this->create_moodle_course('Gamma Mapping');

        $this->simulate_legacy_schema();
        $this->assertTrue($this->run_upgrade(2026100102));

        $service = new academic_structure_service();
        $programmeid = (int)$programme->programmeid;

        $template = $service->get_course_mapping_template_rows();
        $this->assertSame('levelcode', $template[0][2]);

        $import = $service->import_course_mapping_rows([[
            'programmecode' => $programme->programmecode,
            'courseshortname' => $course->shortname,
            'levelcode' => $levelcode,
            'coursetype' => 'core',
            'iscore' => '1',
        ]]);
        $this->assertSame([], $import['errors']);
        $this->assertSame(1, $import['created']);
        $this->assertSame(1, $import['processed']);

        $stored = (new academic_repository())->get_course_mapping_by_hierarchy(
            $programmeid,
            (int)$course->id,
            0,
            $levelid
        );
        $this->assertNotFalse($stored);
        $this->assertSame($levelid, (int)$stored->levelid);

        $preview = $service->preview_course_mapping_rows([[
            'programmecode' => $programme->programmecode,
            'courseshortname' => $course->shortname,
            'levelcode' => $levelcode,
            'coursetype' => 'core',
            'iscore' => '1',
        ]]);
        $this->assertSame(1, $preview['valid']);
        $this->assertSame(0, $preview['invalid']);
        $this->assertSame('Level Gamma (' . $levelcode . ')', $preview['previewrows'][0]['level']);

        $export = $service->get_course_mapping_export_rows(programmeid: $programmeid);
        $this->assertSame('levelcode', $export[0][5]);
        $this->assertCount(2, $export);
        $this->assertSame($levelcode, $export[1][5]);
        $this->assertSame($course->shortname, $export[1][2]);
    }

    /**
     * Reproduces the schema of an installation that never ran savepoint 2026091501.
     */
    private function simulate_legacy_schema(): void {
        $dbman = $this->db_manager();
        $table = new xmldb_table(self::TABLE);

        $hierarchy = $this->hierarchy_unique_index();
        if ($dbman->index_exists($table, $hierarchy)) {
            $dbman->drop_index($table, $hierarchy);
        }

        $scope = $this->level_scope_index();
        if ($dbman->index_exists($table, $scope)) {
            $dbman->drop_index($table, $scope);
        }

        $field = $this->levelid_field();
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // 2026062600 shipped a UNIQUE(programmeid, moodlecourseid) constraint that the
        // hierarchy savepoint supersedes; recreate it so the repair's drop path is exercised.
        $legacy = new xmldb_index('programme_course_unique', XMLDB_INDEX_UNIQUE, ['programmeid', 'moodlecourseid']);
        if (!$dbman->index_exists($table, $legacy)) {
            $dbman->add_index($table, $legacy);
        }
    }

    /**
     * Reinstates the declared schema. Required because MySQL DDL is not rolled back by
     * resetAfterTest() and the captured table data includes the levelid column.
     */
    private function restore_schema(): void {
        $dbman = $this->db_manager();
        $table = new xmldb_table(self::TABLE);

        if (!$dbman->table_exists($table)) {
            return;
        }

        $field = $this->levelid_field();
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        // Remove the simulated legacy uniqueness so the declared schema is left behind.
        $legacy = new xmldb_index('programme_course_unique', XMLDB_INDEX_UNIQUE, ['programmeid', 'moodlecourseid']);
        if ($dbman->index_exists($table, $legacy)) {
            $dbman->drop_index($table, $legacy);
        }

        $hierarchy = $this->hierarchy_unique_index();
        if (!$dbman->index_exists($table, $hierarchy)) {
            $dbman->add_index($table, $hierarchy);
        }

        $scope = $this->level_scope_index();
        if (!$dbman->index_exists($table, $scope)) {
            $dbman->add_index($table, $scope);
        }
    }

    /**
     * Runs the plugin upgrade function from a simulated stored plugin version.
     *
     * upgrade_plugin_savepoint() refuses to move backwards, so the stored config_plugins
     * version has to be lowered before the upgrade path can be exercised. The upgrade
     * library is not autoloaded (the real upgrade runner includes it explicitly), so it
     * has to be required here before the plugin upgrade file is loaded.
     *
     * @param int $storedversion
     * @return bool
     */
    private function run_upgrade(int $storedversion): bool {
        global $CFG;

        require_once($CFG->libdir . '/upgradelib.php');

        set_config('version', $storedversion, 'local_ulms_academics');
        require_once($CFG->dirroot . '/local/ulms_academics/db/upgrade.php');

        return \xmldb_local_ulms_academics_upgrade($storedversion);
    }

    /**
     * Returns the database manager.
     *
     * @return \database_manager
     */
    private function db_manager(): \database_manager {
        global $DB;

        return $DB->get_manager();
    }

    /**
     * Returns the levelid field as declared in install.xml.
     *
     * @return xmldb_field
     */
    private function levelid_field(): xmldb_field {
        return new xmldb_field('levelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    }

    /**
     * Returns the 4-column academic hierarchy uniqueness declared in install.xml.
     *
     * @return xmldb_index
     */
    private function hierarchy_unique_index(): xmldb_index {
        return new xmldb_index(
            'programme_course_full_hierarchy_unique',
            XMLDB_INDEX_UNIQUE,
            ['programmeid', 'moodlecourseid', 'semesterid', 'levelid']
        );
    }

    /**
     * Returns the level scope index declared in install.xml.
     *
     * @return xmldb_index
     */
    private function level_scope_index(): xmldb_index {
        return new xmldb_index(
            'level_programme_semester_scope_idx',
            XMLDB_INDEX_NOTUNIQUE,
            ['levelid', 'programmeid', 'semesterid']
        );
    }

    /**
     * Creates a course mapping row, omitting levelid while the column is absent so the
     * insert matches what a legacy installation would have written.
     *
     * @param int $programmeid
     * @param int $moodlecourseid
     * @param int $levelid
     * @param int|null $semesterid
     * @return int
     */
    private function insert_mapping(
        int $programmeid,
        int $moodlecourseid,
        int $levelid = 0,
        ?int $semesterid = null
    ): int {
        global $DB;

        $now = time();
        $record = (object)[
            'programmeid' => $programmeid,
            'moodlecourseid' => $moodlecourseid,
            'semesterid' => $semesterid,
            'coursetype' => 'core',
            'iscore' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        if ($this->db_manager()->field_exists(new xmldb_table(self::TABLE), $this->levelid_field())) {
            $record->levelid = $levelid;
        }

        return (int)$DB->insert_record(self::TABLE, $record);
    }

    /**
     * Creates an academic level and returns its id.
     *
     * @param string $name
     * @param string|null $code
     * @return int
     */
    private function create_level(string $name, ?string $code = null): int {
        global $DB;

        $now = time();

        return (int)$DB->insert_record('local_ulms_levels', (object)[
            'code' => $code ?? 'LVL-' . random_string(6),
            'name' => $name,
            'description' => null,
            'status' => 'active',
            'sortorder' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Creates a Moodle course that can be mapped.
     *
     * @param string $prefix
     * @return \stdClass
     */
    private function create_moodle_course(string $prefix): \stdClass {
        $suffix = random_string(6);

        return $this->getDataGenerator()->create_course([
            'fullname' => $prefix . ' ' . $suffix,
            'shortname' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $prefix), 0, 8)) . $suffix,
        ]);
    }

    /**
     * Creates a college -> department -> programme chain.
     *
     * @return \stdClass
     */
    private function create_programme(): \stdClass {
        $repository = new academic_repository();

        $facultycode = 'FAC-' . random_string(6);
        $facultyid = $repository->save_record('faculties', (object)[
            'code' => $facultycode,
            'name' => 'College ' . $facultycode,
            'status' => 'active',
        ]);

        $departmentcode = 'DEP-' . random_string(6);
        $departmentid = $repository->save_record('departments', (object)[
            'code' => $departmentcode,
            'name' => 'Department ' . $departmentcode,
            'status' => 'active',
            'facultyid' => $facultyid,
        ]);

        $programmecode = 'PRG-' . random_string(6);
        $programmeid = $repository->save_record('programmes', (object)[
            'code' => $programmecode,
            'name' => 'Programme ' . $programmecode,
            'status' => 'active',
            'departmentid' => $departmentid,
            'awardtype' => 'BSc',
            'durationyears' => 4,
        ]);

        return (object)[
            'facultyid' => $facultyid,
            'departmentid' => $departmentid,
            'programmeid' => $programmeid,
            'programmecode' => $programmecode,
        ];
    }
}
