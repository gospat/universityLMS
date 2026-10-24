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

namespace local_ulms_dashboard;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;

require_once(__DIR__ . '/../../../config.php');

/**
 * Regression tests for portal strings that were referenced but never defined.
 *
 * Regression: the four portal banner titles, the lecturer grading navigation
 * label and the super admin KPI failure notice were requested with
 * get_string() but absent from the language file. The portal services masked the
 * missing copy with hard-coded fallbacks, yet every request still raised a
 * developer debugging notice, which surfaced as errors in the dashboard test
 * suite. Defining the strings fixes the notice at its source instead of hiding
 * the suite.
 *
 * The same audit, extended to identifier arguments built at runtime (for
 * example 'attendance.status.' . $status), confirmed that the remaining
 * wrapper-resolved identifiers were genuinely absent.  Those are pinned here as
 * well so the language file stays internally consistent.
 *
 * @coversNothing
 */
final class lang_strings_test extends advanced_testcase {

    /**
     * Portal banner titles are defined and accept the user's display name.
     */
    public function test_portal_welcome_strings_are_defined_and_use_their_placeholder(): void {
        foreach (['studentwelcome', 'lecturerwelcome', 'adminwelcome', 'superadminwelcome'] as $identifier) {
            $text = get_string($identifier, 'local_ulms_dashboard', 'Ada Lovelace');
            $this->assertStringNotContainsString('[[', $text, 'Unresolved portal string: ' . $identifier);
            $this->assertStringContainsString('Ada Lovelace', $text, 'Placeholder not applied: ' . $identifier);
        }
    }

    /**
     * The lecturer "Grading" navigation label and the super admin KPI notice are defined.
     */
    public function test_other_referenced_portal_strings_are_defined(): void {
        foreach (['lecturernavgrading', 'superadmin.kpifetcherror'] as $identifier) {
            $text = get_string($identifier, 'local_ulms_dashboard');
            $this->assertNotSame('', $text, 'Empty portal string: ' . $identifier);
            $this->assertStringNotContainsString('[[', $text, 'Unresolved portal string: ' . $identifier);
        }
    }

    /**
     * Identifiers reached through dashboard_commons::safe_lang_string() and
     * safe_eyebrow() are defined too.  Those wrappers swallow a missing string
     * and substitute their own fallback, so the pages still rendered — but the
     * reference was dead weight that no translator could ever override.
     */
    public function test_wrapper_resolved_portal_strings_are_defined(): void {
        foreach (['management.lecturers.eyebrow', 'schedulesessionrepeatflexible'] as $identifier) {
            $text = get_string($identifier, 'local_ulms_dashboard');
            $this->assertNotSame('', $text, 'Empty portal string: ' . $identifier);
            $this->assertStringNotContainsString('[[', $text, 'Unresolved portal string: ' . $identifier);
        }
    }
}
