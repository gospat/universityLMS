<?php
defined('MOODLE_INTERNAL') || die();

/** @var \moodle_page $PAGE */
/** @var mixed $OUTPUT */
/** @var \stdClass $SITE */
/** @var \stdClass $CFG */

$hassidepre = $PAGE->blocks->is_known_region('side-pre');
$hassidepost = $PAGE->blocks->is_known_region('side-post');
$portalshell = method_exists($OUTPUT, 'get_portal_shell_context') ? $OUTPUT->get_portal_shell_context() : null;

$extraclasses = [];
if ($portalshell !== null) {
    $extraclasses[] = 'ulms-dashboard-shell';
    $extraclasses[] = 'ulms-portal-suppress-native-drawers';
}

$bodyattributes = $OUTPUT->body_attributes($extraclasses);
$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false);

$secondarynavigation = false;
$overflow = '';
if ($PAGE->has_secondary_navigation()) {
    $tablistnav = $PAGE->has_tablist_secondary_navigation();
    $moremenu = new \core\navigation\output\more_menu($PAGE->secondarynav, 'nav-tabs', true, $tablistnav);
    $secondarynavigation = $moremenu->export_for_template($OUTPUT);
    $overflowdata = $PAGE->secondarynav->get_overflow_menu_data();
    if (!is_null($overflowdata)) {
        $overflow = $overflowdata->export_for_template($OUTPUT);
    }
}

$primary = new core\navigation\output\primary($PAGE);
$renderer = $PAGE->get_renderer('core');
$primarymenu = $primary->export_for_template($renderer);
$regionmainsettingsmenu = $OUTPUT->region_main_settings_menu();

if ($portalshell !== null) {
    $usermenu = method_exists($OUTPUT, 'ulms_dashboard_user_menu') ? $OUTPUT->ulms_dashboard_user_menu() : $primarymenu['user'];

    $homeurl = $CFG->wwwroot . '/';
    if (\isloggedin() && !\isguestuser() && class_exists(\local_ulms_auth\local\service\landing_page_service::class)) {
        $landingservice = new \local_ulms_auth\local\service\landing_page_service();
        $homeurl = $landingservice->get_dashboard_url_for_current_user()->out(false);
    }

    $templatecontext = [
        'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), 'escape' => false]),
        'config' => [
            'wwwroot' => $CFG->wwwroot,
            'homeurl' => $homeurl,
        ],
        'output' => $OUTPUT,
        'bodyattributes' => $bodyattributes,
        'primarymoremenu' => $primarymenu['moremenu'],
        'secondarymoremenu' => $secondarynavigation ?: false,
        'mobileprimarynav' => $primarymenu['mobileprimarynav'],
        'usermenu' => $usermenu,
        'langmenu' => $primarymenu['lang'],
        'regionmainsettingsmenu' => $regionmainsettingsmenu,
        'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
        'overflow' => $overflow,
        'portalnav' => $portalshell,
        'pagecontent' => $OUTPUT->main_content(),
    ];

    echo $OUTPUT->render_from_template('theme_ulms_university/standard_shell', $templatecontext);
} else {
    $templatecontext = [
        'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
        'output' => $OUTPUT,
        'bodyattributes' => $bodyattributes,
        'sidepreblocks' => $blockshtml,
        'hasblocks' => $hasblocks,
        'hassidepre' => $hassidepre,
        'hassidepost' => $hassidepost,
        'primarymoremenu' => $primarymenu['moremenu'],
        'secondarymoremenu' => $secondarynavigation ?: false,
        'mobileprimarynav' => $primarymenu['mobileprimarynav'],
        'usermenu' => $primarymenu['user'],
        'langmenu' => $primarymenu['lang'],
        'regionmainsettingsmenu' => $regionmainsettingsmenu,
        'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
        'overflow' => $overflow,
    ];

    echo $OUTPUT->render_from_template('theme_boost/columns2', $templatecontext);
}
