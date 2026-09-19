<?php
require_once(__DIR__ . '/../../local/ulms_auth/clean_route_entry.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');
require_once($CFG->libdir . '/enrollib.php');

require_login();

global $USER;
$context = \context::instance_by_id(\context_system::instance()->id);
$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_dashboard_access('student');
require_capability('local/ulms_dashboard:viewstudentdashboard', $context);

$bulk = optional_param('bulk', 0, PARAM_BOOL);
$courseid = $bulk ? 0 : required_param('courseid', PARAM_INT);
$returnurl = new \moodle_url('/student/catalog', ['view' => 'catalog']);

require_sesskey();

$svc = \local_ulms_dashboard\local\service\portal_overview_service::class;

if ($bulk) {
    $count = 0;
    try {
        $count = (int)$svc::enrol_student_all_programme_courses((int)$USER->id);
    } catch (\Throwable $e) {
        \core\notification::add(
            'Bulk enrolment failed: ' . $e->getMessage(),
            \core\notification::ERROR
        );
        redirect($returnurl);
    }
    if ($count <= 0) {
        \core\notification::add(
            'All programme courses are already enrolled — no new enrolments needed.',
            \core\notification::INFO
        );
    } else {
        \core\notification::add(
            "Successfully enrolled in {$count} new programme courses.",
            \core\notification::SUCCESS
        );
    }
    redirect($returnurl);
}

if ($courseid <= 0) {
    \core\notification::add('Invalid course selected.', \core\notification::ERROR);
    redirect($returnurl);
}

$result = ['ok' => false, 'msg' => 'Enrolment failed.'];
try {
    $result = $svc::self_enrol_student_in_programme_course((int)$USER->id, $courseid);
} catch (\Throwable $e) {
    $result = ['ok' => false, 'already' => false, 'msg' => 'Enrolment error: ' . $e->getMessage()];
}

if (!empty($result['ok'])) {
    if (!empty($result['already'])) {
        \core\notification::add((string)($result['msg'] ?? 'You are already enrolled in this course.'), \core\notification::INFO);
    } else {
        \core\notification::add((string)($result['msg'] ?? 'Enrolment successful.'), \core\notification::SUCCESS);
    }
} else {
    \core\notification::add((string)($result['msg'] ?? 'Enrolment failed.'), \core\notification::ERROR);
}

redirect($returnurl);
