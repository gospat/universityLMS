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

namespace local_ulms_exam;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;

require_once(__DIR__ . '/../../../config.php');

/**
 * Regression tests for the exam validation strings.
 *
 * Regression: lecturer_exam_create.php called
 * get_string('err_required_title', 'local_ulms_exam', null, 'Exam title is required.')
 * for nine identifiers that were never defined in the language file. The fourth
 * argument was also misused — Moodle's signature is
 * get_string($identifier, $component, $a, $lang) — so the intended fallback text
 * was consumed as a language code and the lecturer was shown the raw
 * "[[err_required_title]]" placeholder instead of a readable validation message.
 *
 * @coversNothing
 */
final class lang_strings_test extends advanced_testcase {

    /**
     * Every validation message surfaced by the exam creation form must resolve to
     * readable text rather than an unresolved placeholder.
     */
    public function test_exam_validation_strings_are_defined(): void {
        $identifiers = [
            'err_required_title',
            'err_required_programme',
            'err_required_level',
            'err_required_session',
            'err_required_semester',
            'err_required_course',
            'err_required_start',
            'err_required_end',
            'err_end_before_start',
        ];

        foreach ($identifiers as $identifier) {
            $text = get_string($identifier, 'local_ulms_exam');
            $this->assertNotSame('', $text, 'Empty exam validation string: ' . $identifier);
            $this->assertStringNotContainsString('[[', $text, 'Unresolved exam validation string: ' . $identifier);
        }
    }
}
