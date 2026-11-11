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
 * Regression tests for the render-time response header.
 *
 * Regression: local_ulms_dashboard_register_x_render_time_shutdown() decided
 * whether to emit "X-ULMS-Render-Time" by calling headers_sent() at
 * REGISTRATION time, which is always early in the request and therefore always
 * false. The header() call itself lives in the shutdown closure, which runs
 * after the response body has been written, so PHP raised
 *   Cannot modify header information - headers already sent
 * on every request that registered the handler.
 *
 * The sibling local_ulms_dashboard_emit_x_render_time() is correct because it
 * re-checks headers_sent() immediately before emitting. The fix makes the
 * shutdown closure do the same, so the two paths now agree.
 *
 * A CLI test cannot observe header() output, so the contract is pinned against
 * the source, in the same way the theme's security-header policy is pinned.
 *
 * @coversNothing
 */
final class shutdown_header_guard_test extends advanced_testcase {

    /**
     * The shutdown closure must re-check headers_sent() before emitting the header.
     */
    public function test_shutdown_closure_rechecks_headers_sent_before_emitting(): void {
        $source = $this->get_lib_source();
        $closure = $this->get_shutdown_closure_source($source);

        $guardposition = strpos($closure, 'headers_sent()');
        $headerposition = strpos($closure, "@header('X-ULMS-Render-Time:");

        $this->assertNotFalse(
            $guardposition,
            'The registered shutdown closure must re-check headers_sent(), because the check at ' .
            'registration time runs before any output exists.'
        );
        $this->assertNotFalse(
            $headerposition,
            'The shutdown closure must still emit the X-ULMS-Render-Time header when it is safe to do so.'
        );
        $this->assertLessThan(
            $headerposition,
            $guardposition,
            'The headers_sent() re-check must come before the header() call inside the shutdown closure.'
        );

        $this->assertStringContainsString(
            "PHP_SAPI === 'cli' || headers_sent()",
            $closure,
            'The shutdown closure must skip emission in CLI and once headers are sent.'
        );
    }

    /**
     * Registering and invoking the helper must never raise a PHP warning.
     *
     * This is the behavioural half of the contract: the source assertions above pin
     * the guard, and this proves the entry points are warning-free when they run.
     */
    public function test_registering_the_handler_never_raises_a_error_in_cli(): void {
        $raised = [];

        set_error_handler(static function (int $errno, string $errstr) use (&$raised): bool {
            $raised[] = $errstr;
            return true;
        });

        try {
            local_ulms_dashboard_mark_request_start();
            local_ulms_dashboard_emit_x_render_time();
            local_ulms_dashboard_register_x_render_time_shutdown();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $raised, 'Registering or invoking the render-time header must not raise a warning.');
    }

    /**
     * Returns the source of local/ulms_dashboard/lib.php.
     *
     * @return string
     */
    private function get_lib_source(): string {
        global $CFG;

        $path = $CFG->dirroot . '/local/ulms_dashboard/lib.php';
        $source = @file_get_contents($path);
        $this->assertNotFalse($source, 'Unable to read ' . $path);

        return (string)$source;
    }

    /**
     * Extracts the body of the register_shutdown_function() call.
     *
     * @param string $source Full lib.php source.
     * @return string
     */
    private function get_shutdown_closure_source(string $source): string {
        $start = strpos($source, 'register_shutdown_function(static function (): void {');
        $this->assertNotFalse(
            $start,
            'The render-time shutdown handler must still be registered through register_shutdown_function().'
        );

        $end = strpos($source, '});', (int)$start);
        $this->assertNotFalse($end, 'The registered shutdown closure must be terminated.');

        return substr($source, (int)$start, (int)$end - (int)$start);
    }
}
