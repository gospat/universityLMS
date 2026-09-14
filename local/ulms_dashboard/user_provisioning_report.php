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
require_once($CFG->dirroot . '/local/ulms_dashboard/lib.php');

/** @var moodle_page $PAGE */
/** @var core_renderer $OUTPUT */
/** @var stdClass $USER */
/** @noinspection PhpUndefinedVariableInspection */

require_login();

$dashboardservice = new \local_ulms_dashboard\local\service\dashboard_service();
$dashboardservice->enforce_admin_feature_access();
$dashboardservice->require_admin_permissions();

$token = required_param('token', PARAM_ALPHANUMEXT);
$routingservice = new \local_ulms_auth\local\service\landing_page_service();
$routingservice->maybe_redirect_legacy_request('management.bulkreport', ['token' => $token, 'section' => optional_param('section', 'bulk', PARAM_ALPHA)]);
$service = new \local_ulms_dashboard\local\service\user_provisioning_service();
$report = $service->get_report($token);

if ($report === null || empty($report['rows'])) {
    throw new \moodle_exception('invaliddata');
}

$filename = clean_filename((string)($report['filename'] ?? 'user-provisioning-report.csv'));
$rows = $report['rows'];
$headers = array_keys(reset($rows));

local_ulms_dashboard_emit_security_headers();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');
fputcsv($output, $headers);
foreach ($rows as $row) {
    $ordered = [];
    foreach ($headers as $header) {
        $ordered[] = $row[$header] ?? '';
    }
    fputcsv($output, $ordered);
}
fclose($output);
exit;
