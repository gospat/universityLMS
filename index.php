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
 * Moodle frontpage.
 *
 * @package    core
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!file_exists('./config.php')) {
    header('Location: install.php');
    die;
}

require_once('config.php');
require_once($CFG->dirroot .'/course/lib.php');
require_once($CFG->libdir .'/filelib.php');

/** @var stdClass $SITE */
/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */

redirect_if_major_upgrade_required();

// Redirect logged-in users to homepage if required.
$redirect = optional_param('redirect', 1, PARAM_BOOL);

$urlparams = array();
if (!empty($CFG->defaulthomepage) &&
        ($CFG->defaulthomepage == HOMEPAGE_MY || $CFG->defaulthomepage == HOMEPAGE_MYCOURSES) &&
        $redirect === 0
) {
    $urlparams['redirect'] = 0;
}
$PAGE->set_url('/', $urlparams);
$PAGE->set_pagelayout('frontpage');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_other_editing_capability('moodle/course:update');
$PAGE->set_other_editing_capability('moodle/course:manageactivities');
$PAGE->set_other_editing_capability('moodle/course:activityvisibility');

// Prevent caching of this page to stop confusion when changing page after making AJAX changes.
$PAGE->set_cacheable(false);

require_course_login($SITE);
// #region debug-point D:root-index-entry
$__dbgurl = 'http://127.0.0.1:7777/event'; $__dbgsession = 'auth-landing-flow'; $__dbgenv = @file_get_contents($CFG->dirroot . '/.dbg/auth-landing-flow.env'); if ($__dbgenv !== false) { foreach (preg_split('/\r\n|\r|\n/', $__dbgenv) as $__dbgline) { if (str_starts_with($__dbgline, 'DEBUG_SERVER_URL=')) { $__dbgurl = trim(substr($__dbgline, 17)); } else if (str_starts_with($__dbgline, 'DEBUG_SESSION_ID=')) { $__dbgsession = trim(substr($__dbgline, 17)); } } } @file_get_contents($__dbgurl, false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['sessionId' => $__dbgsession, 'runId' => 'pre-fix', 'hypothesisId' => 'D', 'location' => 'index.php:frontpage', 'msg' => '[DEBUG] Root index request reached Moodle frontpage', 'data' => ['requesturi' => (string)($_SERVER['REQUEST_URI'] ?? ''), 'isLoggedIn' => isloggedin(), 'isGuest' => isguestuser(), 'defaultHomepage' => $CFG->defaulthomepage ?? null, 'alternateLoginUrl' => $CFG->alternateloginurl ?? '', 'forgottenPasswordUrl' => $CFG->forgottenpasswordurl ?? ''], 'ts' => (int)round(microtime(true) * 1000)]), 'timeout' => 1]]));
// #endregion

if (class_exists(\local_ulms_auth\local\service\landing_page_service::class) && isloggedin() && !isguestuser()) {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();
    $routingservice->redirect_to_current_user_dashboard('', \core\output\notification::NOTIFY_INFO, 302);
}

if (class_exists(\local_ulms_auth\local\service\landing_page_service::class) && (!isloggedin() || isguestuser())) {
    $routingservice = new \local_ulms_auth\local\service\landing_page_service();
    $routingservice->redirect_to_url($routingservice->get_public_portal_landing_url(), '', \core\output\notification::NOTIFY_INFO, 302);
}

$systemcontext = context::instance_by_id(context_system::instance()->id);
$hasmaintenanceaccess = has_capability('moodle/site:maintenanceaccess', $systemcontext);

// If the site is currently under maintenance, then print a message.
if (!empty($CFG->maintenance_enabled) and !$hasmaintenanceaccess) {
    print_maintenance_message();
}

$hassiteconfig = has_capability('moodle/site:config', $systemcontext);

if ($hassiteconfig && moodle_needs_upgrading()) {
    redirect($CFG->wwwroot .'/'. $CFG->admin .'/index.php');
}

// If site registration needs updating, redirect.
\core\hub\registration::registration_reminder('/index.php');

$homepage = get_home_page();
if ($homepage != HOMEPAGE_SITE) {
    if (optional_param('setdefaulthome', false, PARAM_BOOL) && confirm_sesskey()) {
        set_user_preference('user_home_page_preference', HOMEPAGE_SITE);
        redirect($PAGE->url);
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_MY) && $redirect === 1) {
        // At this point, dashboard is enabled so we don't need to check for it (otherwise, get_home_page() won't return it).
        redirect($CFG->wwwroot .'/my/');
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_MYCOURSES) && $redirect === 1) {
        redirect($CFG->wwwroot .'/my/courses.php');
    } else if ($homepage == HOMEPAGE_URL) {
        redirect(get_default_home_page_url());
    } else if (!empty($CFG->defaulthomepage) && ($CFG->defaulthomepage == HOMEPAGE_USER)) {
        $frontpagenode = $PAGE->settingsnav->find('frontpage', null);
        if ($frontpagenode) {
            $frontpagenode->add(
                get_string('makethismyhome'),
                new moodle_url('/', ['setdefaulthome' => 1, 'sesskey' => sesskey()]),
                navigation_node::TYPE_SETTING,
            );
        } else {
            $frontpagenode = $PAGE->settingsnav->add(get_string('frontpagesettings'), null, navigation_node::TYPE_SETTING, null);
            $frontpagenode->force_open();
            $frontpagenode->add(
                get_string('makethismyhome'),
                new moodle_url('/', ['setdefaulthome' => 1, 'sesskey' => sesskey()]),
                navigation_node::TYPE_SETTING,
            );
        }
    }
}

// Trigger event.
course_view(context_course::instance(SITEID));

$PAGE->set_pagetype('site-index');
$PAGE->set_docs_path('');
$editing = $PAGE->user_is_editing();
$PAGE->set_title(get_string('home'));
$PAGE->set_heading($SITE->fullname);
$PAGE->set_secondary_active_tab('coursehome');

$courserenderer = $PAGE->get_renderer('core', 'course');
assert($courserenderer instanceof core_course_renderer);

if ($hassiteconfig) {
    $editurl = new moodle_url('/course/view.php', ['id' => SITEID, 'sesskey' => sesskey()]);
    $editbutton = $OUTPUT->edit_button($editurl);
    $PAGE->set_button($editbutton);
}

echo $OUTPUT->header();

$siteformatoptions = course_get_format($SITE)->get_format_options();
$modinfo = get_fast_modinfo($SITE);
$modnamesused = $modinfo->get_used_module_names();

// Print Section or custom info.
if (!empty($CFG->customfrontpageinclude)) {
    // Pre-fill some variables that custom front page might use.
    $modnames = get_module_types_names();
    $modnamesplural = get_module_types_names(true);
    $mods = $modinfo->get_cms();

    include($CFG->customfrontpageinclude);

} else if ($siteformatoptions['numsections'] > 0) {
    echo $courserenderer->frontpage_section1();
}
// Include course AJAX.
include_course_ajax($SITE, $modnamesused);

echo $courserenderer->frontpage();

if ($editing && has_capability('moodle/course:create', $systemcontext)) {
    echo $courserenderer->add_new_course_button();
}
echo $OUTPUT->footer();
