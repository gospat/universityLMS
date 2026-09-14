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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

global $PAGE, $OUTPUT;

require_login();

$context = \context::instance_by_id(context_system::instance()->id);
require_capability('local/ulms_academics:viewreports', $context);

$export = optional_param('export', '', PARAM_ALPHA);
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routeparams = [];
if ($export !== '') {
    $routeparams['export'] = $export;
}
$routingservice->maybe_redirect_legacy_request('management.academicsreports', $routeparams);
$url = $routingservice->get_url_for_route('management.academicsreports', $routeparams);

$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('summaryreport', 'local_ulms_academics'));
$PAGE->set_heading(get_string('summaryreport', 'local_ulms_academics'));
$PAGE->set_pagelayout('ulmsdashboard');

$service = new \local_ulms_academics\local\service\academic_structure_service();
$summary = $service->get_summary_counts();

if ($export === 'csv') {
    $filename = 'ulms-academic-summary.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    foreach ($service->get_summary_export_rows() as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

echo $OUTPUT->header();
echo html_writer::start_div('ulms-page');
$headercontext = local_ulms_dashboard_get_management_header_context('academics');
$headercontext['showtitle'] = false;
echo $OUTPUT->render_from_template('theme_ulms_university/student_portal_context_header', $headercontext);
echo html_writer::start_div('ulms-page-header');
echo html_writer::start_div('ulms-page-header__content');
echo html_writer::start_div('ulms-page-header__main');
echo html_writer::tag('h1', get_string('summaryreport', 'local_ulms_academics'), ['class' => 'ulms-page-header__title']);
echo html_writer::tag('p', get_string('summaryreportdesc', 'local_ulms_academics'), ['class' => 'ulms-page-header__meta']);
echo html_writer::end_div();
echo html_writer::div(
    html_writer::link(
        $routingservice->get_url_for_route('management.academicsreports', ['export' => 'csv']),
        get_string('exportsummarycsv', 'local_ulms_academics'),
        ['class' => 'btn btn-outline-secondary']
    ),
    'ulms-page-header__actions'
);
echo html_writer::end_div();
echo html_writer::end_div();

$table = new html_table();
$table->head = [
    get_string('summaryentity', 'local_ulms_academics'),
    get_string('summarycount', 'local_ulms_academics'),
];

foreach ($summary as $row) {
    $table->data[] = [
        format_string($row['label']),
        $row['count'],
    ];
}

echo html_writer::start_div('ulms-panel');
echo html_writer::start_div('ulms-panel__body');
echo html_writer::div(html_writer::table($table), 'ulms-table-wrap');
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
