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

require_once($CFG->libdir . '/behat/lib.php');

global $CFG, $OUTPUT, $PAGE, $SITE;

if (!isset($CFG->themerev) || !is_numeric($CFG->themerev)) {
    $CFG->themerev = time();
}

$extraclasses = ['ulms-dashboard-shell', 'ulms-portal-suppress-native-drawers', 'theme-ulms-university'];
if (method_exists($OUTPUT, 'body_css_classes')) {
    $rawclasses = @$OUTPUT->body_css_classes([]);
    if (is_string($rawclasses) && $rawclasses !== '') {
        foreach (preg_split('/\s+/', trim($rawclasses)) as $cls) {
            if ($cls !== '' && !in_array($cls, $extraclasses, true)) {
                $extraclasses[] = $cls;
            }
        }
    }
}
$bodyattributes = $OUTPUT->body_attributes($extraclasses);
$usermenu = method_exists($OUTPUT, 'ulms_dashboard_user_menu') ? $OUTPUT->ulms_dashboard_user_menu() : [];
$homeurl = $CFG->wwwroot . '/';

if (\isloggedin() && !\isguestuser() && class_exists(\local_ulms_auth\local\service\landing_page_service::class)) {
    $service = new \local_ulms_auth\local\service\landing_page_service();
    $homeurl = $service->get_dashboard_url_for_current_user()->out(false);
}

$portalcontext = method_exists($OUTPUT, 'get_portal_shell_context') ? $OUTPUT->get_portal_shell_context() : null;

$headercontext = null;
$summarycards = [];
$quickaccess = [];

if (is_array($portalcontext)) {
    $headercontext = $portalcontext['headercontext'] ?? null;
    $summarycards = $portalcontext['summarycards'] ?? [];
    $quickaccess = $portalcontext['quickaccess'] ?? [];
}

$templatecontext = [
    'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), 'escape' => false]),
    'config' => [
        'wwwroot' => $CFG->wwwroot,
        'homeurl' => $homeurl,
    ],
    'output' => $OUTPUT,
    'bodyattributes' => $bodyattributes,
    'usermenu' => $usermenu,
    'portalnav' => $portalcontext,
    'headercontext' => $headercontext,
    'summarycards' => $summarycards,
    'summarycards_count' => is_countable($summarycards) ? count($summarycards) : 0,
    'quickaccess' => $quickaccess,
    'quickaccess_count' => is_countable($quickaccess) ? count($quickaccess) : 0,
];

echo $OUTPUT->render_from_template('theme_ulms_university/ulms_dashboard_shell', $templatecontext);
