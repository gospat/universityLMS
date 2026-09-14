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

require_once($CFG->dirroot . '/theme/boost/lib.php');

/**
 * Returns the precompiled CSS fallback for the theme.
 *
 * @return string
 */
function theme_ulms_university_get_precompiled_css(): string {
    global $CFG;

    return file_get_contents($CFG->dirroot . '/theme/boost/style/moodle.css');
}

/**
 * Returns SCSS that should be prepended before the main preset.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_ulms_university_get_pre_scss(theme_config $theme): string {
    $scss = '';
    $brandcolor = get_config('theme_ulms_university', 'brandcolor');
    $resolvedbrandcolor = !empty($brandcolor) ? $brandcolor : '#0f4c81';

    $scss .= '$primary: ' . $resolvedbrandcolor . ';' . PHP_EOL;
    $scss .= '$ulms-primary: ' . $resolvedbrandcolor . ';' . PHP_EOL;

    return $scss;
}

/**
 * Returns the main SCSS content for the ULMS university theme.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_ulms_university_get_main_scss_content(theme_config $theme): string {
    return theme_boost_get_main_scss_content($theme);
}

/**
 * Returns theme-specific SCSS that should be appended after Boost.
 *
 * @return string
 */
function theme_ulms_university_get_extra_scss(): string {
    $overridespath = __DIR__ . '/scss/preset/default.scss';

    if (is_readable($overridespath)) {
        return file_get_contents($overridespath);
    }

    return '';
}

/**
 * Serves theme files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function theme_ulms_university_pluginfile(
    stdClass $course,
    stdClass $cm,
    context $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    if ($context->contextlevel === CONTEXT_SYSTEM) {
        $theme = theme_config::load('ulms_university');
        if (!array_key_exists('cacheability', $options)) {
            $options['cacheability'] = 'public';
        }
        return $theme->setting_file_serve($filearea, $args, $forcedownload, $options);
    }

    send_file_not_found();
}

/**
 * Returns starter dashboard branding context for templates.
 *
 * @return array
 */
function theme_ulms_university_get_dashboard_context(): array {
    $portalcards = [];
    if (!isloggedin() && class_exists(\local_ulms_auth\local\service\landing_page_service::class)) {
        $portalcards = (new \local_ulms_auth\local\service\landing_page_service())->get_portal_cards();
    }

    return [
        'brandtitle' => get_config('theme_ulms_university', 'dashboardtitle') ?: get_string(
            'dashboardtitledefault',
            'theme_ulms_university'
        ),
        'supports_darkmode' => !empty(get_config('theme_ulms_university', 'enable_darkmode')),
        'showportalcards' => !empty($portalcards),
        'portalcards' => $portalcards,
    ];
}
