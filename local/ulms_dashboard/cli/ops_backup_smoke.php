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

define('CLI_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/cronlib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

global $DB;

$rawopts = getopt('', ['courseid:', 'destination:']);
$courseid = isset($rawopts['courseid']) ? (int)$rawopts['courseid'] : 2;
$destination = isset($rawopts['destination']) ? (string)$rawopts['destination'] : null;

if ($courseid <= 0) {
    cli_error('Invalid courseid supplied.', 2);
}

$course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname', IGNORE_MISSING);
if (!$course) {
    cli_error("Course {$courseid} does not exist in the local Moodle database.", 2);
}

$adminuserid = (int)($CFG->siteadmins ? explode(',', (string)$CFG->siteadmins)[0] : 2);
if (!$DB->record_exists('user', ['id' => $adminuserid, 'deleted' => 0])) {
    global $CFG;
    $localhostid = (int)($CFG->mnet_localhost_id ?? 1);
    $adminuserid = (int)$DB->get_field_sql("SELECT id FROM {user} WHERE deleted = 0 AND mnethostid = ? ORDER BY id ASC LIMIT 1", [$localhostid]);
}

try {
    $controller = new backup_controller(
        backup::TYPE_1COURSE,
        $courseid,
        backup::FORMAT_MOODLE,
        backup::INTERACTIVE_NO,
        backup::MODE_GENERAL,
        $adminuserid
    );

    $plan = $controller->get_plan();
    $plan->execute();

    $results = $controller->get_results();
    if (!isset($results['backup_destination'])) {
        throw new moodle_exception('errorbackuppath', 'error');
    }

    $file = $results['backup_destination'];
    $filesize = (int)$file->get_filesize();
    if (!$file || $filesize <= 0) {
        throw new moodle_exception('errorbackupfile', 'error');
    }

    $filename = $file->get_filename();
    $contenthash = method_exists($file, 'get_contenthash') ? $file->get_contenthash() : md5($filename . '|' . $filesize);

    if ($destination !== null && is_dir($destination) && is_writable($destination)) {
        $target = rtrim($destination, '/') . '/' . $filename;
        $copied = $file->copy_content_to($target);
        if (!$copied) {
            cli_error("Failed to copy backup file to {$destination}.", 2);
        }
    }

    echo json_encode([
        'ok' => true,
        'courseid' => $courseid,
        'shortname' => $course->shortname,
        'filename' => $filename,
        'filesize_bytes' => $filesize,
        'filesize_min_bytes' => 51200,
        'filesize_pass' => $filesize >= 51200,
        'contenthash' => $contenthash,
        'storage' => method_exists($file, 'get_storage') ? ($file->get_storage() ?? 'unknown') : 'stored_file_pool',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

    $controller->destroy();

    if ($filesize < 51200) {
        exit(2);
    }
    exit(0);
} catch (\Throwable $exception) {
    if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'ops_backup_smoke::backup_run', ['ctx' => basename(__FILE__)]); }
    if (!empty($controller)) {
        try {
            $controller->destroy();
        } catch (\Throwable $exception) {
            if (function_exists('local_ulms_dashboard_log_operational_error')) { local_ulms_dashboard_log_operational_error($exception, 'ops_backup_smoke::restore_verify', ['ctx' => basename(__FILE__)]); }
        }
    }
    echo json_encode([
        'ok' => false,
        'courseid' => $courseid,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit(2);
}
