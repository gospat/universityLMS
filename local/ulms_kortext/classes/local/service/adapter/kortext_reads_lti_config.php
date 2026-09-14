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
 * Shared reader for Kortext LTI 1.3 tool configuration.
 *
 * Returns the LTI 1.3 configuration triple documented by Kortext (tool URL,
 * JWKS URL, initiate login URL, redirection URL, deep-linking support,
 * privacy sharing flags) using the following precedence:
 *
 *   1. Plugin admin settings stored in config_plugins
 *      (Site Administration → Plugins → Local plugins → ULMS Kortext)
 *   2. Environment variables (KORTEXT_LTI_*) via ulms_env()
 *      so infrastructure / docker-compose can override per tier.
 *   3. Sensible defaults matching Kortext public documentation URLs.
 *
 * This trait is consumed by both kortext_mock_adapter and
 * kortext_rest_production_adapter so the interface contract for
 * lti_tool_config() is honoured identically regardless of active mode.
 *
 * @package local_ulms_kortext
 */
trait kortext_reads_lti_config {

    /**
     * Reads a plugin setting key with ulms_env() fallback.
     *
     * @param string $cfgkey      Plugin setting name (e.g. 'lti_tool_url')
     * @param string $envkey      Environment variable name (e.g. 'KORTEXT_LTI_TOOL_URL')
     * @param string $default     Default literal value
     * @return string
     */
    private static function read_cfg_env(string $cfgkey, string $envkey, string $default): string {
        $dbval = (string)get_config('local_ulms_kortext', $cfgkey);
        if ($dbval !== '') {
            return $dbval;
        }
        if (function_exists('ulms_env')) {
            $envval = ulms_env($envkey, null);
            if (is_string($envval) && $envval !== '') {
                return $envval;
            }
        }
        $fallback = getenv($envkey);
        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }
        return $default;
    }

    /**
     * Reads a boolean-style setting (checkbox / '1'/'0' or env 'true'/'1').
     *
     * @param string $cfgkey
     * @param string $envkey
     * @param bool $default
     * @return bool
     */
    private static function read_cfg_env_bool(string $cfgkey, string $envkey, bool $default): bool {
        $dbval = get_config('local_ulms_kortext', $cfgkey);
        if ($dbval !== false && $dbval !== null && $dbval !== '') {
            return (bool)$dbval;
        }
        if (function_exists('ulms_env')) {
            $envval = ulms_env($envkey, null);
            if ($envval !== null && $envval !== '') {
                $lower = strtolower((string)$envval);
                return in_array($lower, ['1', 'true', 'yes', 'on'], true);
            }
        }
        $rawenv = getenv($envkey);
        if (is_string($rawenv) && $rawenv !== '') {
            $lower = strtolower($rawenv);
            return in_array($lower, ['1', 'true', 'yes', 'on'], true);
        }
        return $default;
    }

    /**
     * Returns the Kortext LTI 1.3 tool configuration following Kortext docs
     * "LTI 1.3 Moodle Integration" for the VLE side.
     *
     * @return array{
     *   tool_name: string,
     *   tool_url: string,
     *   lti_version: string,
     *   public_keyset_url: string,
     *   initiate_login_url: string,
     *   redirection_url: string,
     *   supports_deep_linking: bool,
     *   share_pii_always: bool,
     * }
     */
    public function lti_tool_config(): array {
        return [
            'tool_name'             => self::read_cfg_env('lti_tool_name', 'KORTEXT_LTI_TOOL_NAME', 'Kortext'),
            'tool_url'              => self::read_cfg_env('lti_tool_url', 'KORTEXT_LTI_TOOL_URL', 'https://vle.kortext.com'),
            'lti_version'           => self::read_cfg_env('lti_version', 'KORTEXT_LTI_VERSION', 'LTI 1.3'),
            'public_keyset_url'     => self::read_cfg_env(
                'lti_public_keyset_url',
                'KORTEXT_LTI_JWKS_URL',
                'https://vle.kortext.com/api/v1/lti/v1.3/jwks'
            ),
            'initiate_login_url'    => self::read_cfg_env(
                'lti_initiate_login_url',
                'KORTEXT_LTI_INITIATE_LOGIN_URL',
                'https://vle.kortext.com/api/v1/lti/v1.3/auth'
            ),
            'redirection_url'       => self::read_cfg_env(
                'lti_redirection_url',
                'KORTEXT_LTI_REDIRECTION_URL',
                'https://vle.kortext.com/api/v1/lti/v1.3/launch'
            ),
            'supports_deep_linking' => self::read_cfg_env_bool('lti_deep_linking', 'KORTEXT_LTI_DEEP_LINKING', true),
            'share_pii_always'      => self::read_cfg_env_bool('lti_share_pii_always', 'KORTEXT_LTI_SHARE_PII', true),
        ];
    }
}
