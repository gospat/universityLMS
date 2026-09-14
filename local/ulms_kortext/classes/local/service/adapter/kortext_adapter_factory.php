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

namespace local_ulms_kortext\local\service\adapter;

defined('MOODLE_INTERNAL') || die();

/**
 * Singleton adapter factory.
 *
 * Adapter mode is determined by the `adapter_mode` admin setting. No other
 * class in the codebase needs to inspect the mode string directly — this
 * confinement satisfies AC-4 (one-line runtime swap) and supports the
 * future pull-mode operation switch (AC-5) without cross-class leakage.
 *
 * @package local_ulms_kortext
 */
final class kortext_adapter_factory {

    /**
     * @var kortext_adapter_interface|null Lazily constructed singleton instance.
     */
    private static ?kortext_adapter_interface $instance = null;

    /**
     * Prevents direct construction.
     */
    private function __construct() {
    }

    /**
     * Returns the singleton adapter, resolving mode via config (DB -> ulms_env() ->
     * getenv -> default mock).
     *
     * Precedence allows infrastructure to force a specific mode per deployment
     * tier (e.g. production=live REST on AWS, staging=mock for CI, dev=mock default)
     * without touching Moodle's config_plugins DB table.
     *
     * @return kortext_adapter_interface
     */
    public static function get_instance(): kortext_adapter_interface {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $mode = (string)get_config('local_ulms_kortext', 'adapter_mode');
        if ($mode === '') {
            if (function_exists('ulms_env')) {
                $em = ulms_env('KORTEXT_ADAPTER_MODE', null);
                if (is_string($em) && $em !== '') {
                    $mode = $em;
                }
            }
        }
        if ($mode === '') {
            $ev = getenv('KORTEXT_ADAPTER_MODE');
            if (is_string($ev) && $ev !== '') {
                $mode = $ev;
            }
        }
        if ($mode === 'production') {
            self::$instance = new kortext_rest_production_adapter();
        } else {
            self::$instance = new kortext_mock_adapter();
        }
        return self::$instance;
    }

    /**
     * Reset singleton (useful for tests that flip adapter_mode mid-run).
     *
     * @return void
     */
    public static function reset_instance(): void {
        self::$instance = null;
    }
}
