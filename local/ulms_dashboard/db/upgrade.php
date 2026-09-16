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

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps for the ULMS dashboard plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ulms_dashboard_upgrade(int $oldversion): bool {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026062200) {
        upgrade_plugin_savepoint(true, 2026062200, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026062700) {
        upgrade_plugin_savepoint(true, 2026062700, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026062701) {
        upgrade_plugin_savepoint(true, 2026062701, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026062702) {
        $facultytable = new xmldb_table('local_ulms_faculty_admin_assignments');
        $facultytable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $facultytable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $facultytable->add_field('facultyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $facultytable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $facultytable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $facultytable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $facultytable->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $facultytable->add_key('facultyid_fk', XMLDB_KEY_FOREIGN, ['facultyid'], 'local_ulms_faculties', ['id']);

        if (!$dbman->table_exists($facultytable)) {
            $dbman->create_table($facultytable);
        }

        $departmenttable = new xmldb_table('local_ulms_department_admin_assignments');
        $departmenttable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $departmenttable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $departmenttable->add_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $departmenttable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $departmenttable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $departmenttable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $departmenttable->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
        $departmenttable->add_key('departmentid_fk', XMLDB_KEY_FOREIGN, ['departmentid'], 'local_ulms_departments', ['id']);

        if (!$dbman->table_exists($departmenttable)) {
            $dbman->create_table($departmenttable);
        }

        upgrade_plugin_savepoint(true, 2026062702, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026080400) {
        require_once($CFG->libdir . '/accesslib.php');

        $systemcontext = \context_system::instance();
        $unifiedcapability = 'local/ulms_dashboard:viewadmindashboard';
        $legacycapabilities = [
            'local/ulms_dashboard:viewictadmindashboard',
            'local/ulms_dashboard:viewfacultyadmindashboard',
            'local/ulms_dashboard:viewdepartmentadmindashboard',
            'local/ulms_dashboard:viewsupportdashboard',
        ];
        $roleshortnames = ['manager', 'ictadmin', 'facultyadmin', 'departmentadmin', 'coursecreator'];
        $permissionpriority = [
            CAP_INHERIT => 1,
            CAP_ALLOW => 2,
            CAP_PREVENT => 3,
            CAP_PROHIBIT => 4,
        ];

        foreach ($roleshortnames as $shortname) {
            $role = $DB->get_record('role', ['shortname' => $shortname], 'id', IGNORE_MISSING);
            if ($role) {
                assign_capability($unifiedcapability, CAP_ALLOW, (int)$role->id, $systemcontext->id, true);
            }
        }

        [$insql, $params] = $DB->get_in_or_equal($legacycapabilities, SQL_PARAMS_NAMED);
        $records = $DB->get_records_sql(
            "SELECT roleid, capability, permission
               FROM {role_capabilities}
              WHERE capability $insql",
            $params
        );

        $migratedpermissions = [];
        foreach ($records as $record) {
            $roleid = (int)$record->roleid;
            $permission = (int)$record->permission;
            $currentpriority = $permissionpriority[$migratedpermissions[$roleid] ?? CAP_INHERIT] ?? 0;
            $candidatepriority = $permissionpriority[$permission] ?? 0;

            if (!array_key_exists($roleid, $migratedpermissions) || $candidatepriority > $currentpriority) {
                $migratedpermissions[$roleid] = $permission;
            }
        }

        foreach ($migratedpermissions as $roleid => $permission) {
            assign_capability($unifiedcapability, $permission, $roleid, $systemcontext->id, true);
        }

        [$deleteinsql, $deleteparams] = $DB->get_in_or_equal($legacycapabilities, SQL_PARAMS_NAMED);
        $DB->delete_records_select('role_capabilities', "capability $deleteinsql", $deleteparams);

        upgrade_plugin_savepoint(true, 2026080400, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026080500) {
        $logtable = new xmldb_table('local_ulms_user_provisioning_log');
        $logtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $logtable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $logtable->add_field('createduserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $logtable->add_field('targetrole', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('createmode', XMLDB_TYPE_CHAR, '16', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('identifier', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $logtable->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $logtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $logtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $logtable->add_index('actorid_idx', XMLDB_INDEX_NOTUNIQUE, ['actorid']);
        $logtable->add_index('createduserid_idx', XMLDB_INDEX_NOTUNIQUE, ['createduserid']);
        $logtable->add_index('targetrole_idx', XMLDB_INDEX_NOTUNIQUE, ['targetrole']);
        $logtable->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $logtable->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($logtable)) {
            $dbman->create_table($logtable);
        }

        upgrade_plugin_savepoint(true, 2026080500, 'local', 'ulms_dashboard');
    }
    if ($oldversion < 2026081600) {
        require_once($CFG->libdir . '/accesslib.php');

        $systemcontext = \context_system::instance();

        $customroles = [
            [
                'name' => 'ICT Administrator',
                'shortname' => 'ictadmin',
            ],
            [
                'name' => 'College Administrator',
                'shortname' => 'facultyadmin',
            ],
            [
                'name' => 'Department Administrator',
                'shortname' => 'departmentadmin',
            ],
        ];

        foreach ($customroles as $customrole) {
            $role = $DB->get_record(
                'role',
                ['shortname' => $customrole['shortname']],
                '*',
                IGNORE_MISSING
            );

            if (!$role) {
                $roleid = create_role(
                    $customrole['name'],
                    $customrole['shortname'],
                    'ULMS custom administrative role.',
                    ''
                );

                $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
            }

            assign_capability(
                'local/ulms_dashboard:viewadmindashboard',
                CAP_ALLOW,
                (int)$role->id,
                $systemcontext->id,
                true
            );
        }

        upgrade_plugin_savepoint(true, 2026081600, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026081900) {
        $profiletable = new xmldb_table('local_ulms_user_profile');
        $profiletable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $profiletable->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $profiletable->add_field('facultyid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $profiletable->add_field('departmentid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $profiletable->add_field('programmeid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $profiletable->add_field('studylevel', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, '');
        $profiletable->add_field('staffid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, '');
        $profiletable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $profiletable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $profiletable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $profiletable->add_key('userprofile_faculty_fk', XMLDB_KEY_FOREIGN, ['facultyid'], 'local_ulms_faculties', ['id']);
        $profiletable->add_key('userprofile_department_fk', XMLDB_KEY_FOREIGN, ['departmentid'], 'local_ulms_departments', ['id']);
        $profiletable->add_key('userprofile_programme_fk', XMLDB_KEY_FOREIGN, ['programmeid'], 'local_ulms_programmes', ['id']);
        $profiletable->add_index('userid_uix', XMLDB_INDEX_UNIQUE, ['userid']);
        $profiletable->add_index('studylevel_idx', XMLDB_INDEX_NOTUNIQUE, ['studylevel']);

        if (!$dbman->table_exists($profiletable)) {
            $dbman->create_table($profiletable);
        }

        $logtable = new xmldb_table('local_ulms_user_management_log');
        $logtable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $logtable->add_field('actorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $logtable->add_field('targetuserid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $logtable->add_field('action', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('status', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $logtable->add_field('detailsjson', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $logtable->add_field('ipaddress', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, '');
        $logtable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $logtable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $logtable->add_key('actorid_fk', XMLDB_KEY_FOREIGN, ['actorid'], 'user', ['id']);
        $logtable->add_key('targetuserid_fk', XMLDB_KEY_FOREIGN, ['targetuserid'], 'user', ['id']);
        $logtable->add_index('action_idx', XMLDB_INDEX_NOTUNIQUE, ['action']);
        $logtable->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $logtable->add_index('timecreated_idx', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($logtable)) {
            $dbman->create_table($logtable);
        }

        upgrade_plugin_savepoint(true, 2026081900, 'local', 'ulms_dashboard');
    }

    // First pass rename of the facultyadmin role to College Administrator.
    if ($oldversion < 2026082600) {
        $role = $DB->get_record('role', ['shortname' => 'facultyadmin'], 'id, name', IGNORE_MISSING);

        if ($role && trim((string)$role->name) === 'Faculty Administrator') {
            $DB->update_record('role', (object)[
                'id' => (int)$role->id,
                'name' => 'College Administrator',
            ]);
        }

        upgrade_plugin_savepoint(true, 2026082600, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026083100) {
        require_once($CFG->libdir . '/accesslib.php');

        $systemcontext = \context_system::instance();
        $roleshortnames = ['manager', 'coursecreator', 'ictadmin', 'facultyadmin', 'departmentadmin'];
        $capabilitymap = [
            'local/ulms_academics:viewstructure' => $roleshortnames,
            'local/ulms_academics:manageacademics' => $roleshortnames,
            'moodle/course:create' => $roleshortnames,
            'moodle/category:manage' => $roleshortnames,
        ];

        foreach ($capabilitymap as $capability => $targets) {
            foreach ($targets as $shortname) {
                $role = $DB->get_record('role', ['shortname' => $shortname], 'id', IGNORE_MISSING);
                if (!$role) {
                    continue;
                }

                assign_capability($capability, CAP_ALLOW, (int)$role->id, $systemcontext->id, true);
            }
        }

        upgrade_plugin_savepoint(true, 2026083100, 'local', 'ulms_dashboard');
    }

    // Second pass catch-all for environments that missed 2026082600 or still contain the old label.
    if ($oldversion < 2026091100) {
        $role = $DB->get_record('role', ['shortname' => 'facultyadmin'], 'id, name', IGNORE_MISSING);

        if ($role && stripos(trim((string)$role->name), 'Faculty') !== false) {
            $DB->update_record('role', (object)[
                'id' => (int)$role->id,
                'name' => 'College Administrator',
            ]);
        }

        upgrade_plugin_savepoint(true, 2026091100, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026091301) {
        $leveltable = new xmldb_table('local_ulms_levels');
        $leveltable->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $leveltable->add_field('code', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $leveltable->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $leveltable->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $leveltable->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'active');
        $leveltable->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $leveltable->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $leveltable->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $leveltable->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $leveltable->add_index('code_unique', XMLDB_INDEX_UNIQUE, ['code']);
        $leveltable->add_index('status_idx', XMLDB_INDEX_NOTUNIQUE, ['status']);
        $leveltable->add_index('sortorder_idx', XMLDB_INDEX_NOTUNIQUE, ['sortorder']);

        if (!$dbman->table_exists($leveltable)) {
            $dbman->create_table($leveltable);
        }

        $seedlevels = [
            ['code' => '100', 'name' => '100 Level', 'sortorder' => 1],
            ['code' => '200', 'name' => '200 Level', 'sortorder' => 2],
            ['code' => '300', 'name' => '300 Level', 'sortorder' => 3],
            ['code' => '400', 'name' => '400 Level', 'sortorder' => 4],
            ['code' => '500', 'name' => '500 Level', 'sortorder' => 5],
            ['code' => '600', 'name' => '600 Level', 'sortorder' => 6],
        ];
        $now = time();
        foreach ($seedlevels as $seed) {
            $exists = $DB->record_exists('local_ulms_levels', ['code' => $seed['code']]);
            if (!$exists) {
                $DB->insert_record('local_ulms_levels', (object)[
                    'code' => $seed['code'],
                    'name' => $seed['name'],
                    'description' => '',
                    'status' => 'active',
                    'sortorder' => $seed['sortorder'],
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
            }
        }

        if ($dbman->table_exists(new xmldb_table('local_ulms_user_profile'))) {
            $existing = $DB->get_records_sql_menu(
                "SELECT DISTINCT studylevel, 1 FROM {local_ulms_user_profile} WHERE studylevel <> ''"
            );
            foreach (array_keys($existing) as $legacycode) {
                $legacycode = trim((string)$legacycode);
                if ($legacycode === '') {
                    continue;
                }
                $exists = $DB->record_exists('local_ulms_levels', ['code' => $legacycode]);
                if (!$exists) {
                    $maxsort = (int)$DB->get_field_sql('SELECT COALESCE(MAX(sortorder), 0) FROM {local_ulms_levels}');
                    $DB->insert_record('local_ulms_levels', (object)[
                        'code' => $legacycode,
                        'name' => $legacycode . ' Level',
                        'description' => 'Migrated from existing user profile studylevel values',
                        'status' => 'active',
                        'sortorder' => $maxsort + 1,
                        'timecreated' => $now,
                        'timemodified' => $now,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026091301, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026091302) {
        // Savepoint only — forces capability/access.php refresh for managelevels + any lang rebuild.
        upgrade_plugin_savepoint(true, 2026091302, 'local', 'ulms_dashboard');
    }

    if ($oldversion < 2026091501) {
        // Savepoint 2026091501: Academic Hierarchy Consistency upgrade.
        // - Registers new managelevels capability via access.php refresh at upgrade time.
        // - No schema changes in this plugin for this savepoint (schema changes
        //   for the programme_courses join table live in local/ulms_academics).
        // - Future dashboard-level schema/scoping migrations should land here.
        upgrade_plugin_savepoint(true, 2026091501, 'local', 'ulms_dashboard');
    }

    return true;
}
