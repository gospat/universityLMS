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

if (!function_exists('local_ulms_mail_bootstrap_dependencies')) {
    /**
     * Loads Composer dependencies needed by the ULMS mail transport when available.
     *
     * @return void
     */
    function local_ulms_mail_bootstrap_dependencies(): void {
        global $CFG;

        static $loaded = false;
        if ($loaded) {
            return;
        }

        $candidates = [
            $CFG->dirroot . '/vendor/autoload.php',
            dirname($CFG->dirroot) . '/vendor/autoload.php',
        ];

        foreach ($candidates as $autoloader) {
            if (is_readable($autoloader)) {
                require_once($autoloader);
                break;
            }
        }

        $loaded = true;
    }
}
