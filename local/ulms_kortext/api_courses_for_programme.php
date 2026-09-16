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

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

global $DB, $PAGE, $USER;

require_login();

/** @var mixed $syscontext */
$syscontext = \context_system::instance();
require_capability('local/ulms_kortext:manageadoptions', $syscontext);

$programmeid = required_param('programmeid', PARAM_INT);
$levelid = optional_param('levelid', 0, PARAM_INT);
$semesterid = optional_param('semesterid', 0, PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);

$PAGE->set_context($syscontext);
$PAGE->set_url('/local/ulms_kortext/api_courses_for_programme.php', [
    'programmeid' => $programmeid,
    'levelid' => $levelid,
    'semesterid' => $semesterid,
    'sessionid' => $sessionid,
]);

header('Content-Type: application/json; charset=utf-8');

$out = ['success' => true, 'courses' => []];

if ($programmeid <= 0) {
    echo json_encode($out);
    exit;
}

try {
    $where = ["programmeid = :pid"];
    $params = ['pid' => $programmeid];
    if ($levelid > 0) {
        $where[] = "(levelid = :lvl OR levelid IS NULL OR levelid = 0)";
        $params['lvl'] = $levelid;
    }
    if ($semesterid > 0) {
        $where[] = "(semesterid = :sem OR semesterid IS NULL OR semesterid = 0)";
        $params['sem'] = $semesterid;
    }
    if ($sessionid > 0) {
        $where[] = "EXISTS (SELECT 1 FROM {local_ulms_semesters} s2 WHERE s2.id = semesterid AND s2.sessionid = :sess)";
        $params['sess'] = $sessionid;
    }
    $wheresql = implode(' AND ', $where);
    $courseids = $DB->get_fieldset_sql(
        "SELECT DISTINCT moodlecourseid FROM {local_ulms_programme_courses} WHERE {$wheresql}",
        $params
    );
    if (count($courseids) > 0) {
        [$in, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $rs = $DB->get_records_sql(
            "SELECT id, shortname, fullname FROM {course} WHERE id {$in} ORDER BY shortname ASC",
            $inparams
        );
        foreach ($rs as $c) {
            $out['courses'][] = [
                'id' => (int)$c->id,
                'shortname' => (string)$c->shortname,
                'fullname' => (string)$c->fullname,
                'label' => trim((string)$c->shortname) . ' — ' . trim((string)$c->fullname),
            ];
        }
    }
} catch (\Throwable) {
    $out['success'] = false;
    $out['courses'] = [];
}

echo json_encode($out);
exit;
