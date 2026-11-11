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

namespace local_ulms_auth;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../config.php');

/**
 * Regression tests for the output-buffer guard on the ULMS bootstrap.
 *
 * Regression: the ULMS root configuration wrapper opened a buffer with
 * @ob_start() for web requests only, then its registered shutdown fallback
 * called @ob_end_flush() unconditionally on three paths. PHP raises
 *   ob_end_flush(): Failed to delete and flush buffer
 * whenever ob_get_level() is 0, and the ULMS error handler logs every warning
 * without consulting error_reporting(), so the @ suppression did not hide it.
 *
 * The invariant enforced here is deliberately broader than the one file: no
 * ULMS-owned entry point may end or flush an output buffer without first
 * proving one exists, and the only two idioms permitted are an `if
 * (ob_get_level() > 0)` statement wrapping the call or a `while (ob_get_level()
 * > 0)` loop draining the stack. That is exactly how config.php and every
 * plugin site is written, so a future bare @ob_end_flush() is caught wherever
 * it is introduced.
 *
 * Coverage note: the GitHub Actions PHPUnit jobs replace config.php with the
 * Moodle CI template before the suites run, so in that environment the config
 * wrapper itself is not the file under test. The scan therefore also covers
 * every other ULMS PHP file, which keeps the check non-vacuous in CI and fails
 * the gate for any plugin or theme regression.
 *
 * @coversNothing
 */
final class bootstrap_output_buffer_guard_test extends \advanced_testcase {

    /**
     * The two guarding idioms the ULMS codebase is allowed to use.
     */
    private const GUARD_PATTERN = '/(?:if|while)\s*\(\s*ob_get_level\s*\(\s*\)\s*>\s*0\s*\)\s*\{\s*$/';

    /**
     * Every buffer-terminating call in ULMS application code must be level-guarded.
     */
    public function test_no_ulms_entry_point_ends_an_output_buffer_without_a_level_guard(): void {
        $unguarded = [];

        foreach ($this->ulms_php_files() as $path) {
            $source = file_get_contents($path);
            if ($source === false) {
                continue;
            }

            foreach ($this->unguarded_buffer_ends($source) as $offset) {
                $unguarded[] = $path . ' (byte ' . $offset . ')';
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            "These ULMS calls end or flush an output buffer without an ob_get_level() guard, " .
            "so they warn with 'Failed to delete and flush buffer' when no buffer is open:\n" .
            implode("\n", $unguarded)
        );
    }

    /**
     * The wrapper must still own a shutdown fallback that drains the buffer safely.
     *
     * Asserted only against the ULMS wrapper when it is the file present: the CI
     * template is a different file and never contains this function.
     */
    public function test_bootstrap_shutdown_fallback_drains_the_buffer_safely(): void {
        global $CFG;

        $path = $CFG->dirroot . '/config.php';
        $source = is_readable($path) ? (string)file_get_contents($path) : '';

        if (strpos($source, 'ulms_emergency_shutdown_fallback') === false) {
            // The active bootstrap is not the ULMS wrapper (the CI template is in
            // place instead). There is nothing to assert about a function that is
            // not part of the file under test, so the systemic scan above carries
            // the regression signal here.
            $this->assertStringNotContainsString('@ob_end_flush', $source);

            return;
        }

        $this->assertMatchesRegularExpression(
            '/while\s*\(\s*ob_get_level\s*\(\s*\)\s*>\s*0\s*\)\s*\{\s*@ob_end_clean\s*\(\s*\)\s*;/',
            $source,
            'The bootstrap fallback must drain every open buffer with a guarded loop.'
        );
    }

    /**
     * Returns the byte offsets of buffer-terminating calls that are not guarded.
     *
     * @param string $source PHP source.
     * @return int[]
     */
    private function unguarded_buffer_ends(string $source): array {
        $pattern = '/(?<![A-Za-z0-9_$])@?(?:ob_end_flush|ob_end_clean|ob_flush)\s*\(\s*\)\s*;/';

        preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

        $unguarded = [];
        foreach ($matches[0] as $match) {
            $offset = (int)$match[1];
            if (preg_match(self::GUARD_PATTERN, substr($source, 0, $offset))) {
                continue;
            }
            $unguarded[] = $offset;
        }

        return $unguarded;
    }

    /**
     * ULMS-owned PHP entry points and support files, excluding test fixtures.
     *
     * @return string[]
     */
    private function ulms_php_files(): array {
        global $CFG;

        $files = [];

        $configpath = $CFG->dirroot . '/config.php';
        if (is_readable($configpath)) {
            $files[] = $configpath;
        }

        foreach (['/local', '/theme/ulms_university'] as $tree) {
            $base = $CFG->dirroot . $tree;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileinfo) {
                if (!$fileinfo->isFile() || $fileinfo->getExtension() !== 'php') {
                    continue;
                }

                $path = $fileinfo->getPathname();
                if (
                    strpos($path, '/tests/') !== false
                    || strpos($path, '/node_modules/') !== false
                    || strpos($path, '/vendor/') !== false
                ) {
                    continue;
                }

                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }
}
