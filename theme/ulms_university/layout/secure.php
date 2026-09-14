<?php
defined('MOODLE_INTERNAL') || die();

/** @var \moodle_page $PAGE */
/** @var mixed $OUTPUT */
/** @var \stdClass $SITE */
/** @var \stdClass $CFG */

$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = strpos($blockshtml, 'data-block=') !== false;

$portalshell = method_exists($OUTPUT, 'get_portal_shell_context') ? $OUTPUT->get_portal_shell_context() : null;

if (empty($PAGE->layout_options['noactivityheader'])) {
    $header = $PAGE->activityheader;
    $renderer = $PAGE->get_renderer('core');
    $headercontent = $header->export_for_template($renderer);
} else {
    $headercontent = null;
}

if ($portalshell !== null) {
    $extraclasses = ['ulms-dashboard-shell', 'ulms-portal-suppress-native-drawers'];
    $bodyattributes = $OUTPUT->body_attributes($extraclasses);
    $usermenu = method_exists($OUTPUT, 'ulms_dashboard_user_menu') ? $OUTPUT->ulms_dashboard_user_menu() : [];

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
        'usermenu' => $usermenu,
        'portalnav' => $portalshell,
        'sidepreblocks' => '',
        'hasblocks' => false,
        'headercontent' => $headercontent,
    ];

    echo $OUTPUT->render_from_template('theme_ulms_university/secure_shell', $templatecontext);
} else {
    $bodyattributes = $OUTPUT->body_attributes();
    $templatecontext = [
        'sitename' => format_string($SITE->shortname, true, ['context' => context_course::instance(SITEID), "escape" => false]),
        'output' => $OUTPUT,
        'bodyattributes' => $bodyattributes,
        'sidepreblocks' => $blockshtml,
        'hasblocks' => $hasblocks
    ];
    if ($headercontent !== null) {
        $templatecontext['headercontent'] = $headercontent;
    }

    echo $OUTPUT->render_from_template('theme_boost/secure', $templatecontext);
}
