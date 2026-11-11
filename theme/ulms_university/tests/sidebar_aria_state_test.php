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

namespace theme_ulms_university;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression tests for the ULMS sidebar toggle's aria-expanded state.
 *
 * The sidebar toggle button (data-ulms-sidebar-toggle="true") advertises the
 * state it controls through aria-expanded. That value must always be derived
 * from the live shell state, never from a hard-coded argument:
 *
 *  - 'sm' viewports: the toggle owns the off-canvas drawer, so aria-expanded
 *    must mirror whether the shell carries "is-sidebar-open".
 *  - 'xl'/'md' viewports: the toggle owns the collapsed rail, so
 *    aria-expanded must mirror whether the shell carries
 *    "is-sidebar-collapsed".
 *
 * The defect these tests pin: several call sites passed the *string*
 * 'false' into setAllTogglesExpanded(), which is truthy, so the toggle
 * reported aria-expanded="true" on tablet and mobile even while the rail and
 * the drawer were both closed.
 *
 * A CLI test cannot execute the inline browser script, so these assertions
 * pin the JavaScript source contract that makes the value correct. Runtime
 * behaviour is verified separately in a real browser.
 *
 * @coversNothing
 */
final class sidebar_aria_state_test extends \advanced_testcase {

    /**
     * Reads the renderer that owns the inline sidebar behaviour script.
     *
     * @return string
     */
    private function get_renderer_source(): string {
        global $CFG;

        return file_get_contents($CFG->dirroot . '/theme/ulms_university/classes/output/core_renderer.php');
    }

    /**
     * Returns every argument passed to setAllTogglesExpanded() in the source.
     *
     * @param string $source
     * @return string[]
     */
    private function get_toggle_argument_calls(string $source): array {
        preg_match_all('/setAllTogglesExpanded\(\s*([^;]*?)\s*\)\s*;/', $source, $matches);

        return $matches[1];
    }

    /**
     * Returns the body of the toggleExpandedForState() helper.
     *
     * @param string $source
     * @return string
     */
    private function get_state_helper_body(string $source): string {
        $marker = 'var toggleExpandedForState = function() {';
        $start = strpos($source, $marker);
        $this->assertNotFalse($start, 'toggleExpandedForState() must be defined.');

        $end = strpos($source, '};', $start);
        $this->assertNotFalse($end, 'toggleExpandedForState() must terminate with a statement.');

        return substr($source, $start, ($end - $start));
    }

    /**
     * No call site may pass a quoted string into setAllTogglesExpanded().
     *
     * A non-empty string such as 'false' is truthy, which is exactly how the
     * toggle ended up permanently announcing aria-expanded="true".
     *
     * @return void
     */
    public function test_toggle_state_is_never_passed_as_a_string_literal(): void {
        $source = $this->get_renderer_source();
        $arguments = $this->get_toggle_argument_calls($source);

        $this->assertNotEmpty($arguments, 'Expected at least one setAllTogglesExpanded() call site.');

        foreach ($arguments as $argument) {
            $this->assertDoesNotMatchRegularExpression(
                '/^[\'"`]/',
                $argument,
                'setAllTogglesExpanded() must receive a boolean, not the string literal: ' . $argument
            );
        }
    }

    /**
     * The toggle state must be derived from the live shell state.
     *
     * @return void
     */
    public function test_toggle_state_helper_is_derived_from_shell_state(): void {
        $source = $this->get_renderer_source();
        $body = $this->get_state_helper_body($source);

        $this->assertStringContainsString(
            "'sm'",
            $body,
            'The helper must branch on the sm viewport where the toggle owns the drawer.'
        );
        $this->assertStringContainsString(
            "classList.contains('is-sidebar-open')",
            $body,
            'In sm mode aria-expanded must mirror the open drawer state.'
        );
        $this->assertStringContainsString(
            "classList.contains('is-sidebar-collapsed')",
            $body,
            'In xl/md mode aria-expanded must mirror the expanded rail state.'
        );
    }

    /**
     * Closing the drawer/rail must re-sync aria-expanded from the new state.
     *
     * @return void
     */
    public function test_close_reassigns_toggle_state_from_live_state(): void {
        $source = $this->get_renderer_source();

        $this->assertStringContainsString(
            'setAllTogglesExpanded(toggleExpandedForState());',
            $source,
            'State changes must re-derive aria-expanded instead of hard-coding it.'
        );
    }

    /**
     * Opening the mobile drawer must announce the expanded state.
     *
     * @return void
     */
    public function test_open_announces_boolean_expanded(): void {
        $source = $this->get_renderer_source();

        $this->assertStringContainsString(
            'setAllTogglesExpanded(true);',
            $source,
            'Opening the drawer must pass the boolean true.'
        );
        $this->assertStringNotContainsString(
            "setAllTogglesExpanded('true')",
            $source,
            'The boolean true must not be passed as a string.'
        );
    }
}
