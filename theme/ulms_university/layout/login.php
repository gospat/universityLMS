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

/**
 * Login page layout for the ULMS university theme.
 *
 * The institutional brand mark was previously injected straight into
 * theme/boost/templates/login.mustache, and Boost's layout hardcodes
 * render_from_template('theme_boost/login'), so this theme had no way to own
 * its own login branding. Declaring the `login` layout here means
 * theme_config::resolve_layout() finds this file before the parent theme's,
 * and the brand mark renders from theme_ulms_university/login instead.
 *
 * @package   theme_ulms_university
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/** @var \moodle_page $PAGE */
/** @var mixed $OUTPUT */
/** @var \stdClass $SITE */

$bodyattributes = $OUTPUT->body_attributes();

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), 'escape' => false]),
    'sitefullname' => format_string($SITE->fullname, true, ['context' => context_system::instance(), 'escape' => false]),
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
];

echo $OUTPUT->render_from_template('theme_ulms_university/login', $templatecontext);
