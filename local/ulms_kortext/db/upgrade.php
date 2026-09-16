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
 * Upgrade steps for local_ulms_kortext.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_ulms_kortext_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026091300) {
        // Initial release — tables are created via install.xml on fresh install.
        // Upgrade migrations for later plugin point-releases will go here.
        upgrade_plugin_savepoint(true, 2026091300, 'local', 'ulms_kortext');
    }

    if ($oldversion < 2026091601) {
        $table = new xmldb_table('local_ulms_kortext_adoptions');

        $levelidfield = new xmldb_field('levelid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'semesterid');
        if (!$dbman->field_exists($table, $levelidfield)) {
            $dbman->add_field($table, $levelidfield);
        }

        $sessionidfield = new xmldb_field('sessionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'levelid');
        if (!$dbman->field_exists($table, $sessionidfield)) {
            $dbman->add_field($table, $sessionidfield);
        }

        $levelidx = new xmldb_index('level_idx', XMLDB_INDEX_NOTUNIQUE, ['levelid']);
        if (!$dbman->index_exists($table, $levelidx)) {
            $dbman->add_index($table, $levelidx);
        }

        $sessionidx = new xmldb_index('session_idx', XMLDB_INDEX_NOTUNIQUE, ['sessionid']);
        if (!$dbman->index_exists($table, $sessionidx)) {
            $dbman->add_index($table, $sessionidx);
        }

        upgrade_plugin_savepoint(true, 2026091601, 'local', 'ulms_kortext');
    }

    return true;
}
