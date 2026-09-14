<?php
defined('MOODLE_INTERNAL') || die();

/** @var \moodle_page $PAGE */
/** @var mixed $OUTPUT */
/** @var \stdClass $SITE */
/** @var \stdClass $CFG */

require_once($CFG->libdir . '/behat/lib.php');
require_once($CFG->dirroot . '/course/lib.php');

$addblockbutton = $OUTPUT->addblockbutton();

if (isloggedin()) {
    $courseindexopen = (get_user_preferences('drawer-open-index', true) == true);
    $blockdraweropen = (get_user_preferences('drawer-open-block') == true);
} else {
    $courseindexopen = false;
    $blockdraweropen = false;
}

if (defined('BEHAT_SITE_RUNNING') && get_user_preferences('behat_keep_drawer_closed') != 1) {
    $blockdraweropen = true;
}

$extraclasses = ['uses-drawers'];
if ($courseindexopen) {
    $extraclasses[] = 'drawer-open-index';
}

$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false || !empty($addblockbutton));
if (!$hasblocks) {
    $blockdraweropen = false;
}
$courseindex = core_course_drawer();
if (!$courseindex) {
    $courseindexopen = false;
}

$forceblockdraweropen = $OUTPUT->firstview_fakeblocks();

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
$buildregionmainsettings = !$PAGE->include_region_main_settings_in_header_actions() && !$PAGE->has_secondary_navigation();
$regionmainsettingsmenu = $buildregionmainsettings ? $OUTPUT->region_main_settings_menu() : false;

$header = $PAGE->activityheader;
$headercontent = $header->export_for_template($renderer);

$hassidepre = $PAGE->blocks->is_known_region('side-pre');
$portalshell = method_exists($OUTPUT, 'get_portal_shell_context') ? $OUTPUT->get_portal_shell_context() : null;

if ($portalshell !== null) {
    $courseindexopen = false;
    $blockdraweropen = false;
    $courseindex = '';
    $hasblocks = false;
    $forceblockdraweropen = false;
    $blockshtml = '';
    $extraclasses = array_values(array_filter($extraclasses, static fn(string $c): bool => $c !== 'drawer-open-index'));
    $extraclasses[] = 'ulms-dashboard-shell';
    $extraclasses[] = 'ulms-portal-suppress-native-drawers';
    $bodyattributes = $OUTPUT->body_attributes($extraclasses);
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
        'sidepreblocks' => '',
        'hasblocks' => false,
        'bodyattributes' => $bodyattributes,
        'courseindexopen' => false,
        'blockdraweropen' => false,
        'courseindex' => '',
        'primarymoremenu' => $primarymenu['moremenu'],
        'secondarymoremenu' => $secondarynavigation ?: false,
        'mobileprimarynav' => $primarymenu['mobileprimarynav'],
        'usermenu' => $usermenu,
        'langmenu' => $primarymenu['lang'],
        'forceblockdraweropen' => false,
        'regionmainsettingsmenu' => $regionmainsettingsmenu,
        'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
        'overflow' => $overflow,
        'headercontent' => $headercontent,
        'addblockbutton' => '',
        'portalnav' => $portalshell,
    ];

    echo $OUTPUT->render_from_template('theme_ulms_university/drawers_shell', $templatecontext);
} else {
    $bodyattributes = $OUTPUT->body_attributes($extraclasses);
    $templatecontext = [
        'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
        'output' => $OUTPUT,
        'sidepreblocks' => $blockshtml,
        'hasblocks' => $hasblocks,
        'bodyattributes' => $bodyattributes,
        'courseindexopen' => $courseindexopen,
        'blockdraweropen' => $blockdraweropen,
        'courseindex' => $courseindex,
        'primarymoremenu' => $primarymenu['moremenu'],
        'secondarymoremenu' => $secondarynavigation ?: false,
        'mobileprimarynav' => $primarymenu['mobileprimarynav'],
        'usermenu' => $primarymenu['user'],
        'langmenu' => $primarymenu['lang'],
        'forceblockdraweropen' => $forceblockdraweropen,
        'regionmainsettingsmenu' => $regionmainsettingsmenu,
        'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
        'overflow' => $overflow,
        'headercontent' => $headercontent,
        'addblockbutton' => $addblockbutton
    ];

    echo $OUTPUT->render_from_template('theme_boost/drawers', $templatecontext);
}
