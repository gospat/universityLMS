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

namespace local_ulms_kortext\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Moodle Privacy API null provider.
 *
 * Kortext stores user-level entitlements in its own remote system; inside
 * the ULMS Moodle tables we only keep immutable audit rows (entitlement_log)
 * and scoped adoption metadata that never stores non-SSN/PII beyond the
 * Moodle core user.id FK. Future: add metadata collection reason if we
 * later start caching firstname/email in plugin tables for any reason.
 *
 * @package local_ulms_kortext
 */
class provider implements \core_privacy\local\metadata\null_provider {

    use \core_privacy\local\legacy_polyfill;

    /**
     * Returns the localized reason this plugin does not export any user data.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:null_reason';
    }
}
