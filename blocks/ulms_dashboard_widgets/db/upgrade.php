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
 * Upgrade steps for the ULMS dashboard widgets block.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_ulms_dashboard_widgets_upgrade(int $oldversion): bool {
    if ($oldversion < 2026062200) {
        upgrade_block_savepoint(true, 2026062200, 'ulms_dashboard_widgets');
    }

    return true;
}
