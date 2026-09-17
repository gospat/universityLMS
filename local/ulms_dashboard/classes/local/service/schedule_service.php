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

namespace local_ulms_dashboard\local\service;

use context_course;
use moodle_exception;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/enrollib.php');
require_once __DIR__ . '/../../../../../lib/enrollib.php';
require_once __DIR__ . '/../../../../../course/lib.php';

/**
 * Class schedule_service
 *
 * T17 core service for class sessions, timetable grids, live-classroom join URL
 * resolution, attendance marking and CSV export.
 *
 * @package local_ulms_dashboard
 */
class schedule_service {

    private const SESSION_TABLE = 'local_ulms_dashboard_session';
    private const ATTENDANCE_TABLE = 'local_ulms_dashboard_attendance';

    public const DELIVERY_MODES = ['lecture', 'tutorial', 'lab', 'seminar', 'workshop', 'office_hour', 'online_live'];
    public const STATUSES = ['scheduled', 'cancelled', 'completed', 'rescheduled'];
    public const ATTENDANCE_STATUSES = ['present', 'absent', 'late', 'excused'];
    public const LOCATION_MODES = ['physical', 'online'];
    public const PROVIDERS = ['bigbluebutton', 'lti_zoom', 'lti_msft_teams', 'custom_url'];

    public static function instance(): self {
        static $singleton = null;
        if ($singleton === null) {
            $singleton = new self();
        }
        return $singleton;
    }

    private function actor_is_lecturer(int $actor_userid): bool {
        /** @var \context $syscontext */
        $syscontext = \context_system::instance();
        if (has_capability('moodle/site:config', $syscontext, $actor_userid)) {
            return false;
        }
        if (has_capability('local/ulms_dashboard:viewanyschedule', $syscontext, $actor_userid)) {
            return false;
        }
        return has_capability('local/ulms_dashboard:managesessions', $syscontext, $actor_userid) ||
               has_capability('moodle/course:manageactivities', $syscontext, $actor_userid, false) ||
               user_has_role_assignment($actor_userid, 'editingteacher', \context_system::instance()->id);
    }

    private function resolve_allocated_courseids(int $actor_userid): array {
        static $cache = [];
        if (isset($cache[$actor_userid])) {
            return $cache[$actor_userid];
        }
        try {
            if (class_exists(\local_ulms_kortext\local\service\adoption_service::class)) {
                $svc = new \local_ulms_kortext\local\service\adoption_service();
                if (method_exists($svc, 'resolve_courseids_for_user')) {
                    $result = $svc->resolve_courseids_for_user($actor_userid, 'lecturer');
                    if (is_array($result) && count($result) > 0) {
                        $ids = array_values(array_unique(array_map('intval', $result)));
                        $cache[$actor_userid] = $ids;
                        return $ids;
                    }
                }
            }
        } catch (\Throwable $_e) {
        }
        $fallback = [];
        foreach (enrol_get_all_users_courses($actor_userid, false, ['id']) as $rec) {
            $fallback[] = (int)($rec->id ?? 0);
        }
        $cache[$actor_userid] = array_values(array_unique(array_filter($fallback)));
        return $cache[$actor_userid];
    }

    public function save_session(array $payload, int $actor_userid): array {
        global $DB, $USER;

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        $errors = [];

        $fkfields = ['facultyid', 'departmentid', 'programmeid', 'sessionid', 'semesterid', 'levelid', 'moodlecourseid', 'lecturer_userid'];
        foreach ($fkfields as $f) {
            $v = isset($payload[$f]) ? (int)$payload[$f] : 0;
            if ($v <= 0) {
                $errors[$f] = 'required';
            }
        }

        $title = trim((string)($payload['title'] ?? ''));
        if ($title === '') {
            $errors['title'] = 'required';
        }

        $delivery = (string)($payload['delivery_mode'] ?? 'lecture');
        if (!in_array($delivery, self::DELIVERY_MODES, true)) {
            $errors['delivery_mode'] = 'invalid';
        }

        $weekday = (int)($payload['weekday'] ?? 0);
        if ($weekday < 1 || $weekday > 7) {
            $errors['weekday'] = 'invalid';
        }

        $startmin = (int)($payload['start_minutes'] ?? -1);
        if ($startmin < 0 || $startmin > 1439) {
            $errors['start_minutes'] = 'invalid';
        }
        $dur = (int)($payload['duration_minutes'] ?? 0);
        if ($dur < 15 || $dur > 480 || ($dur % 15 !== 0)) {
            $errors['duration_minutes'] = 'invalid';
        }

        $locmode = (string)($payload['location_mode'] ?? 'physical');
        if (!in_array($locmode, self::LOCATION_MODES, true)) {
            $errors['location_mode'] = 'invalid';
        }

        $provider = (string)($payload['provider_key'] ?? 'bigbluebutton');
        if (!in_array($provider, self::PROVIDERS, true)) {
            $errors['provider_key'] = 'invalid';
        }

        $status = (string)($payload['status'] ?? 'scheduled');
        if (!in_array($status, self::STATUSES, true)) {
            $errors['status'] = 'invalid';
        }

        if ($this->actor_is_lecturer($actor_userid)) {
            $allowed = $this->resolve_allocated_courseids($actor_userid);
            $cid = (int)($payload['moodlecourseid'] ?? 0);
            if (!in_array($cid, $allowed, true)) {
                $errors['moodlecourseid'] = 'Course not allocated to this lecturer';
            }
        }

        if (count($errors) > 0) {
            return ['success' => false, 'id' => 0, 'message' => 'validation_failed', 'errors' => $errors];
        }

        $now = time();
        $obj = new stdClass();
        foreach ($fkfields as $f) {
            $obj->$f = (int)$payload[$f];
        }
        $obj->title = $title;
        $obj->delivery_mode = $delivery;
        $obj->weekday = $weekday;
        $obj->start_minutes = $startmin;
        $obj->duration_minutes = $dur;
        $obj->term_start_date = (int)($payload['term_start_date'] ?? 0);
        $obj->term_end_date = (int)($payload['term_end_date'] ?? 0);
        if (isset($payload['skipdates']) && is_array($payload['skipdates'])) {
            $obj->skipdates = json_encode(array_values($payload['skipdates']));
        } else {
            $obj->skipdates = $payload['skipdates'] ?? null;
        }
        $obj->location_mode = $locmode;
        $obj->location_label = trim((string)($payload['location_label'] ?? ''));
        $obj->provider_key = $provider;
        $obj->cmid = !empty($payload['cmid']) ? (int)$payload['cmid'] : null;
        $customurl = trim((string)($payload['join_url_custom'] ?? ''));
        $obj->join_url_custom = $customurl !== '' ? $customurl : null;
        $obj->recurrence = in_array(($payload['recurrence'] ?? 'weekly'), ['weekly','once_off'], true) ? (string)$payload['recurrence'] : 'weekly';
        $obj->status = $status;
        $obj->notes_public  = trim((string)($payload['notes_public'] ?? '')) !== '' ? trim((string)$payload['notes_public']) : null;
        $obj->notes_private = trim((string)($payload['notes_private'] ?? '')) !== '' ? trim((string)$payload['notes_private']) : null;
        $obj->eventid = !empty($payload['eventid']) ? (int)$payload['eventid'] : null;

        if ($id > 0) {
            $obj->id = $id;
            $obj->timemodified = $now;
            $obj->usermodified = $actor_userid > 0 ? $actor_userid : (int)$USER->id;
            $DB->update_record(self::SESSION_TABLE, $obj);
        } else {
            $obj->timecreated = $now;
            $obj->timemodified = $now;
            $obj->usermodified = $actor_userid > 0 ? $actor_userid : (int)$USER->id;
            $id = (int)$DB->insert_record(self::SESSION_TABLE, $obj, true);
        }

        try {
            $this->sync_calendar_event($id);
        } catch (\Throwable $_e) {
        }

        return ['success' => true, 'id' => $id, 'message' => 'ok'];
    }

    public function sync_calendar_event(int $sessionid): bool {
        global $DB;
        $session = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        if (!$session) {
            return false;
        }
        if (!empty($session->eventid)) {
            $exists = $DB->record_exists('event', ['id' => (int)$session->eventid]);
            if (!$exists) {
                $session->eventid = null;
            }
        }

        if ((string)$session->status === 'cancelled') {
            if (!empty($session->eventid)) {
                try {
                    $event = \calendar_event::load((int)$session->eventid);
                    $event->delete();
                } catch (\Throwable $_e) {
                }
            }
            $DB->set_field(self::SESSION_TABLE, 'eventid', null, ['id' => (int)$session->id]);
            return true;
        }

        $startts = $this->next_occurrence_timestamp($session);
        $event = new stdClass();
        $event->name = trim($session->title . ' (' . $session->delivery_mode . ')');
        $event->description = format_text((string)($session->notes_public ?? ''), FORMAT_HTML);
        $event->format = FORMAT_HTML;
        $event->courseid = (int)$session->moodlecourseid;
        $event->groupid = 0;
        $event->userid = (int)$session->lecturer_userid;
        $event->eventtype = 'course';
        $event->timestart = $startts > 0 ? $startts : (int)$session->term_start_date;
        $event->timeduration = max(15, (int)$session->duration_minutes) * 60;
        $event->visible = 1;
        $event->modulename = 0;
        $event->instance = 0;
        $event->timemodified = time();

        if (!empty($session->eventid)) {
            $event->id = (int)$session->eventid;
            try {
                $loaded = \calendar_event::load((int)$session->eventid);
                $props = (array)$event;
                foreach ($props as $k => $v) {
                    if ($k === 'id') {
                        continue;
                    }
                    if (property_exists($loaded, $k)) {
                        $loaded->$k = $v;
                    }
                }
                /** @noinspection PhpParamsInspection */
                $loaded->update([]);
            } catch (\Throwable $_e) {
                $eventobj = \calendar_event::create($event, false);
                $DB->set_field(self::SESSION_TABLE, 'eventid', (int)$eventobj->id, ['id' => (int)$sessionid]);
            }
        } else {
            $eventobj = \calendar_event::create($event, false);
            $DB->set_field(self::SESSION_TABLE, 'eventid', (int)$eventobj->id, ['id' => (int)$sessionid]);
        }

        return true;
    }

    private function next_occurrence_timestamp(stdClass $session): int {
        $ts = max(time(), (int)$session->term_start_date);
        if ($ts <= 0) {
            return 0;
        }
        $ts += 86400 - ($ts % 86400);
        for ($i = 0; $i < 365; $i++) {
            $d = getdate($ts);
            $wd = (int)$d['wday'];
            $wdmap = [0 => 7, 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6];
            $cur = $wdmap[$wd];
            if ($cur === (int)$session->weekday && $ts <= (int)$session->term_end_date) {
                return $ts + (int)$session->start_minutes * 60;
            }
            $ts += 86400;
            if ($ts > (int)$session->term_end_date) {
                break;
            }
        }
        return (int)$session->term_start_date + (int)$session->start_minutes * 60;
    }

    public function delete_session(int $sessionid, int $actor_userid): bool {
        global $DB;
        $session = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        if (!$session) {
            return false;
        }
        if ($this->actor_is_lecturer($actor_userid)) {
            $allowed = $this->resolve_allocated_courseids($actor_userid);
            if (!in_array((int)$session->moodlecourseid, $allowed, true)) {
                return false;
            }
        }
        try {
            if (!empty($session->eventid)) {
                try {
                    $event = \calendar_event::load((int)$session->eventid);
                    $event->delete();
                } catch (\Throwable $_e) {
                }
            }
        } catch (\Throwable $_e) {
        }
        return $DB->delete_records(self::SESSION_TABLE, ['id' => (int)$sessionid]);
    }

    public function get_timetable_cells_for_user(int $userid, string $roleshortname, int $week_start_monday_ts): array {
        global $DB;
        $sun = strtotime('+6 days 23:59:59', $week_start_monday_ts);
        $rows = [];
        /** @var \context $syscontext */
        $syscontext = \context_system::instance();
        $any = has_capability('local/ulms_dashboard:viewanyschedule', $syscontext, $userid, false) ||
               is_siteadmin($userid);

        $where = [];
        $params = [];
        if (!$any) {
            if ($roleshortname === 'student' || $roleshortname === 'user') {
                try {
                    $profile = $DB->get_record('local_ulms_user_profile', ['userid' => $userid], 'programmeid, studylevel', IGNORE_MISSING);
                    $programmeid = 0;
                    $levelcode = '';
                    if ($profile) {
                        $programmeid = (int)($profile->programmeid ?? 0);
                        $levelcode = trim((string)($profile->studylevel ?? ''));
                    }
                    $levelid = 0;
                    if ($levelcode !== '' && $DB->get_manager()->table_exists('local_ulms_levels')) {
                        $l = $DB->get_record('local_ulms_levels', ['code' => $levelcode], 'id');
                        if ($l) {
                            $levelid = (int)$l->id;
                        }
                    }
                    $enrolledcourses = [];
                    foreach (enrol_get_all_users_courses($userid, false, ['id']) as $c) {
                        $enrolledcourses[] = (int)$c->id;
                    }
                    if ($programmeid > 0) {
                        $where[] = 's.programmeid = :pid';
                        $params['pid'] = $programmeid;
                    }
                    if ($levelid > 0) {
                        $where[] = 's.levelid = :lid';
                        $params['lid'] = $levelid;
                    }
                    if (count($enrolledcourses) > 0) {
                        [$insql, $inparams] = $DB->get_in_or_equal($enrolledcourses, SQL_PARAMS_NAMED, 'cid');
                        $where[] = 's.moodlecourseid ' . $insql;
                        $params = array_merge($params, $inparams);
                    }
                } catch (\Throwable $_e) {
                }
            } else {
                $mycourses = $this->resolve_allocated_courseids($userid);
                if (count($mycourses) === 0) {
                    return [];
                }
                [$insql, $inparams] = $DB->get_in_or_equal($mycourses, SQL_PARAMS_NAMED, 'cid');
                $where[] = '(s.lecturer_userid = :luid OR s.moodlecourseid ' . $insql . ')';
                $params['luid'] = $userid;
                $params = array_merge($params, $inparams);
            }
        }

        $where[] = "s.status <> 'cancelled'";
        $where[] = '(s.term_end_date = 0 OR s.term_start_date <= :weekend)';
        $where[] = '(s.term_start_date = 0 OR s.term_end_date >= :weekstart OR (s.term_start_date <= :weekstart AND s.term_end_date >= :weekstart2))';
        $params['weekend'] = $sun;
        $params['weekstart'] = $week_start_monday_ts;
        $params['weekstart2'] = $week_start_monday_ts;

        $sql = "SELECT s.* FROM {" . self::SESSION_TABLE . "} s";
        if (count($where) > 0) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY s.weekday ASC, s.start_minutes ASC, s.duration_minutes ASC";

        try {
            $rs = $DB->get_records_sql($sql, $params);
        } catch (\Throwable $_e) {
            return [];
        }

        foreach ($rs as $r) {
            $o = (array)$r;
            if ($roleshortname === 'student') {
                unset($o['notes_private']);
            }
            $rows[] = $o;
        }
        return $rows;
    }

    public function find_conflicts(int $facultyid = 0, int $_week_start_ts = 0): array {
        global $DB;
        $out = [];
        $whereextra = '';
        $params = [];
        if ($facultyid > 0) {
            $whereextra = 'AND a.facultyid = :fid';
            $params['fid'] = $facultyid;
        }
        $sql = "SELECT a.id AS aid, b.id AS bid,
                       a.lecturer_userid AS lec_a, b.lecturer_userid AS lec_b,
                       a.location_label AS loc_a, b.location_label AS loc_b,
                       a.location_mode AS locmode_a, b.location_mode AS locmode_b,
                       a.weekday, a.start_minutes, a.duration_minutes,
                       a.moodlecourseid AS course_a, b.moodlecourseid AS course_b
                  FROM {" . self::SESSION_TABLE . "} a
                  JOIN {" . self::SESSION_TABLE . "} b ON b.id > a.id
                 WHERE a.status <> 'cancelled' AND b.status <> 'cancelled'
                   AND a.weekday = b.weekday
                   AND a.start_minutes < (b.start_minutes + b.duration_minutes)
                   AND b.start_minutes < (a.start_minutes + a.duration_minutes)
                   AND (
                     (a.lecturer_userid = b.lecturer_userid AND a.lecturer_userid <> 0)
                     OR
                     (a.location_mode = 'physical' AND b.location_mode = 'physical'
                      AND a.location_label <> '' AND b.location_label <> ''
                      AND a.location_label = b.location_label)
                   )
                   $whereextra";
        try {
            $rs = $DB->get_records_sql($sql, $params);
        } catch (\Throwable $_e) {
            return [];
        }
        foreach ($rs as $r) {
            $type = ((int)$r->lec_a === (int)$r->lec_b) ? 'lecturer_double_book' : 'room_double_book';
            $entity_id = $type === 'lecturer_double_book' ? (int)$r->lec_a : (int)$r->course_a;
            if ($type === 'lecturer_double_book') {
                $u = \core_user::get_user((int)$r->lec_a);
                $label = fullname($u);
            } else {
                $label = (string)$r->loc_a;
            }
            $h = intdiv((int)$r->start_minutes, 60);
            $m = ((int)$r->start_minutes) % 60;
            $end = ((int)$r->start_minutes + (int)$r->duration_minutes);
            $h2 = intdiv($end, 60);
            $m2 = $end % 60;
            $wdnames = ['','Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
            $out[] = [
                'conflict_type' => $type,
                'entity_id' => $entity_id,
                'entity_label' => $label,
                'timeslot_label' => ($wdnames[(int)$r->weekday] ?? '?') . ' ' .
                    sprintf('%02d:%02d–%02d:%02d', $h, $m, $h2, $m2),
                'session_a_id' => (int)$r->aid,
                'session_b_id' => (int)$r->bid,
                'course_a' => (int)$r->course_a,
                'course_b' => (int)$r->course_b,
            ];
        }
        return $out;
    }

    public function resolve_join_url(int $sessionid, string $_actor_role): array {
        global $DB;
        $s = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        if (!$s) {
            return ['url' => '', 'target' => '_blank', 'rel' => 'noopener', 'needs_provision' => false];
        }
        $url = '';
        $needs = false;
        if ((string)$s->location_mode === 'online') {
            $provider = (string)$s->provider_key;
            if ($provider === 'bigbluebutton') {
                if (!empty($s->cmid)) {
                    $cm = $DB->get_record('course_modules', ['id' => (int)$s->cmid], 'id, course, module');
                    $mod = $cm ? $DB->get_record('modules', ['id' => (int)$cm->module], 'name') : null;
                    if ($mod) {
                        $url = (string)(new \moodle_url('/mod/' . $mod->name . '/view.php', ['id' => (int)$s->cmid]));
                    }
                }
                if ($url === '') {
                    try {
                        $cmid = $this->ensure_bbb_course_module((int)$s->moodlecourseid, (string)$s->title);
                        if ($cmid > 0) {
                            $DB->set_field(self::SESSION_TABLE, 'cmid', $cmid, ['id' => (int)$sessionid]);
                            $url = (string)(new \moodle_url('/mod/bigbluebuttonbn/view.php', ['id' => $cmid]));
                            $needs = true;
                        }
                    } catch (\Throwable $_e) {
                    }
                    if ($url === '') {
                        $url = (string)(new \moodle_url('/course/view.php', ['id' => (int)$s->moodlecourseid]));
                    }
                }
            } elseif ($provider === 'lti_zoom' || $provider === 'lti_msft_teams') {
                if (!empty($s->cmid)) {
                    $url = (string)(new \moodle_url('/mod/lti/view.php', ['id' => (int)$s->cmid]));
                }
                if ($url === '') {
                    $mod = $DB->get_record('modules', ['name' => 'lti']);
                    if ($mod) {
                        $cm = $DB->get_record_sql(
                            "SELECT id FROM {course_modules} WHERE course = :c AND module = :m LIMIT 1",
                            ['c' => (int)$s->moodlecourseid, 'm' => (int)$mod->id]
                        );
                        if ($cm) {
                            $url = (string)(new \moodle_url('/mod/lti/view.php', ['id' => (int)$cm->id]));
                        }
                    }
                    if ($url === '') {
                        $url = (string)(new \moodle_url('/course/view.php', ['id' => (int)$s->moodlecourseid]));
                    }
                }
            } else {
                if (!empty($s->join_url_custom)) {
                    $url = trim((string)$s->join_url_custom);
                }
                if ($url === '') {
                    $url = (string)(new \moodle_url('/course/view.php', ['id' => (int)$s->moodlecourseid]));
                }
            }
        } else {
            $url = (string)(new \moodle_url('/course/view.php', ['id' => (int)$s->moodlecourseid]));
        }
        return ['url' => $url, 'target' => '_blank', 'rel' => 'noopener', 'needs_provision' => $needs];
    }

    /** @noinspection PhpUndefinedFunctionInspection */
    private function ensure_bbb_course_module(int $moodlecourseid, string $session_title): int {
        global $DB;
        $mod = $DB->get_record('modules', ['name' => 'bigbluebuttonbn'], 'id, name', IGNORE_MISSING);
        if (!$mod) {
            $urlmod = $DB->get_record('modules', ['name' => 'url'], 'id, name');
            if (!$urlmod) {
                return 0;
            }
            $course = $DB->get_record('course', ['id' => $moodlecourseid]);
            if (!$course) {
                return 0;
            }
            $section = 1;
            $data = new stdClass();
            $data->course = $moodlecourseid;
            $data->section = $section;
            $data->module = (int)$urlmod->id;
            $data->modulename = 'url';
            $data->name = 'Live Classroom: ' . trim($session_title);
            $data->intro = 'Placeholder for live classroom access.';
            $data->introformat = FORMAT_HTML;
            $data->externalurl = 'about:blank#bbbmock';
            try {
                /** @noinspection PhpUndefinedFunctionInspection */
                $cmid = \course_create_module($data, false);
                return (int)$cmid;
            } catch (\Throwable $_e) {
                return 0;
            }
        }
        $existing = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :c AND m.name = 'bigbluebuttonbn'
              LIMIT 1",
            ['c' => $moodlecourseid]
        );
        if ($existing) {
            return (int)$existing->id;
        }
        $course = $DB->get_record('course', ['id' => $moodlecourseid]);
        if (!$course) {
            return 0;
        }
        $data = new stdClass();
        $data->course = $moodlecourseid;
        $data->section = 1;
        $data->module = (int)$mod->id;
        $data->modulename = 'bigbluebuttonbn';
        $data->name = 'Live Classroom: ' . trim($session_title);
        $data->intro = 'Auto-provisioned live classroom.';
        $data->introformat = FORMAT_HTML;
        try {
            /** @noinspection PhpUndefinedFunctionInspection */
            $cmid = \course_create_module($data, false);
            return (int)$cmid;
        } catch (\Throwable $_e) {
            $urlmod = $DB->get_record('modules', ['name' => 'url'], 'id, name');
            if (!$urlmod) {
                return 0;
            }
            try {
                $urldata = new stdClass();
                $urldata->course = $moodlecourseid;
                $urldata->section = 1;
                $urldata->module = (int)$urlmod->id;
                $urldata->modulename = 'url';
                $urldata->name = 'Live Classroom: ' . trim($session_title);
                $urldata->intro = 'Fallback link (BigBlueButton unavailable).';
                $urldata->introformat = FORMAT_HTML;
                $urldata->externalurl = 'about:blank#bbbmock';
                /** @noinspection PhpUndefinedFunctionInspection */
                return (int)\course_create_module($urldata, false);
            } catch (\Throwable $_e2) {
                return 0;
            }
        }
    }

    public function mark_attendance(int $sessionid, int $session_occurrence_date, int $userid, string $status, int $marker_id, ?string $comment = null): array {
        global $DB;
        if (!in_array($status, self::ATTENDANCE_STATUSES, true)) {
            return ['success' => false, 'id' => 0, 'message' => 'invalid_status'];
        }
        $session = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        if (!$session) {
            return ['success' => false, 'id' => 0, 'message' => 'session_missing'];
        }
        /** @var \context $syscontext */
        $syscontext = \context_system::instance();
        $allowed_marker = is_siteadmin($marker_id) || has_capability('local/ulms_dashboard:markattendance', $syscontext, $marker_id, false);
        if (!$allowed_marker && $this->actor_is_lecturer($marker_id)) {
            $allowedcourses = $this->resolve_allocated_courseids($marker_id);
            if (!in_array((int)$session->moodlecourseid, $allowedcourses, true)) {
                return ['success' => false, 'id' => 0, 'message' => 'forbidden'];
            }
            $allowed_marker = has_capability('local/ulms_dashboard:markattendance', $syscontext, $marker_id, false);
            if (!$allowed_marker) {
                $allowed_marker = (int)$session->lecturer_userid === (int)$marker_id;
            }
        }
        if (!$allowed_marker) {
            return ['success' => false, 'id' => 0, 'message' => 'forbidden'];
        }
        $existing = $DB->get_record(self::ATTENDANCE_TABLE, [
            'sessionid' => $sessionid,
            'userid' => $userid,
            'session_occurrence_date' => $session_occurrence_date,
        ]);
        $now = time();
        if ($existing) {
            $o = new stdClass();
            $o->id = (int)$existing->id;
            $o->status = $status;
            $o->marked_by = $marker_id;
            $o->marked_at = $now;
            if ($comment !== null) {
                $o->comment = trim($comment) !== '' ? trim($comment) : null;
            }
            $DB->update_record(self::ATTENDANCE_TABLE, $o);
            return ['success' => true, 'id' => (int)$existing->id];
        }
        $o = new stdClass();
        $o->sessionid = $sessionid;
        $o->session_occurrence_date = $session_occurrence_date;
        $o->userid = $userid;
        $o->status = $status;
        $o->marked_by = $marker_id;
        $o->marked_at = $now;
        $o->comment = ($comment !== null && trim($comment) !== '') ? trim($comment) : null;
        try {
            $id = (int)$DB->insert_record(self::ATTENDANCE_TABLE, $o, true);
        } catch (\Throwable $_e) {
            $existing = $DB->get_record(self::ATTENDANCE_TABLE, [
                'sessionid' => $sessionid,
                'userid' => $userid,
                'session_occurrence_date' => $session_occurrence_date,
            ]);
            if (!$existing) {
                return ['success' => false, 'id' => 0, 'message' => 'db_error'];
            }
            return ['success' => true, 'id' => (int)$existing->id];
        }
        return ['success' => true, 'id' => $id];
    }

    /** @noinspection PhpUndefinedFunctionInspection */
    public function bulk_mark_all_present(int $sessionid, int $session_occurrence_date, int $marker_id): array {
        global $DB;
        $session = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        if (!$session) {
            return ['total_count' => 0, 'newly_marked' => 0, 'skipped_existing' => 0, 'message' => 'session_missing'];
        }
        $courseid = (int)$session->moodlecourseid;
        try {
            $ctx = context_course::instance($courseid);
            /** @noinspection PhpUndefinedFunctionInspection */
            $students = \enrol_get_enrolled_users($ctx, 'moodle/role:student');
        } catch (\Throwable $_e) {
            return ['total_count' => 0, 'newly_marked' => 0, 'skipped_existing' => 0, 'message' => 'course_context_missing'];
        }
        $newly = 0;
        $skipped = 0;
        $total = count($students);
        foreach ($students as $stu) {
            $uid = (int)$stu->id;
            $existing = $DB->get_record(self::ATTENDANCE_TABLE, [
                'sessionid' => $sessionid,
                'userid' => $uid,
                'session_occurrence_date' => $session_occurrence_date,
            ]);
            if ($existing && in_array((string)$existing->status, ['absent','excused'], true)) {
                $skipped++;
                continue;
            }
            $r = $this->mark_attendance($sessionid, $session_occurrence_date, $uid, 'present', $marker_id);
            if ($r['success']) {
                $newly++;
            } else {
                $skipped++;
            }
        }
        return ['total_count' => $total, 'newly_marked' => $newly, 'skipped_existing' => $skipped];
    }

    /** @noinspection PhpUndefinedFunctionInspection */
    public function export_attendance_csv(int $sessionid, int $session_occurrence_date): void {
        global $DB;
        if (!defined('CLI_SCRIPT')) {
            @header_remove('Content-Type');
            header('Content-Type: text/csv; charset=utf-8');
            $fname = sprintf('attendance-session-%d-%s.csv', $sessionid, date('Y-m-d', $session_occurrence_date ?: time()));
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Cache-Control: no-cache, must-revalidate');
        }
        $fp = fopen('php://output', 'w');
        if (!$fp) {
            throw new moodle_exception('csvopenfail', 'local_ulms_dashboard');
        }
        fputcsv($fp, ['student_id', 'name', 'status', 'marked_at', 'marked_by']);
        $session = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        $enrolled = [];
        if ($session) {
            try {
                $ctx = context_course::instance((int)$session->moodlecourseid);
                /** @noinspection PhpUndefinedFunctionInspection */
                $enrolled = \enrol_get_enrolled_users($ctx, 'moodle/role:student');
            } catch (\Throwable $_e) {
                $enrolled = [];
            }
        }
        $marks = [];
        if ($session) {
            $rs = $DB->get_records(self::ATTENDANCE_TABLE, [
                'sessionid' => $sessionid,
                'session_occurrence_date' => $session_occurrence_date,
            ]);
            foreach ($rs as $r) {
                $marks[(int)$r->userid] = $r;
            }
        }
        foreach ($enrolled as $u) {
            $uid = (int)$u->id;
            $name = fullname($u);
            if (isset($marks[$uid])) {
                $m = $marks[$uid];
                $status = (string)$m->status;
                $markedat = (int)$m->marked_at > 0 ? date('Y-m-d H:i:s', (int)$m->marked_at) : '';
                $marker = '';
                if ((int)$m->marked_by > 0) {
                    $mu = \core_user::get_user((int)$m->marked_by);
                    if ($mu) {
                        $marker = fullname($mu);
                    }
                }
            } else {
                $status = '';
                $markedat = '';
                $marker = '';
            }
            fputcsv($fp, [$uid, $name, $status, $markedat, $marker]);
        }
        fclose($fp);
    }

    /** @noinspection PhpUndefinedFunctionInspection */
    public function get_session_attendance_summary(int $sessionid, int $occurrence_ts): array {
        global $DB;
        $total = 0;
        $present = 0; $absent = 0; $late = 0; $excused = 0;
        $session = $DB->get_record(self::SESSION_TABLE, ['id' => $sessionid]);
        if ($session) {
            try {
                $ctx = context_course::instance((int)$session->moodlecourseid);
                /** @noinspection PhpUndefinedFunctionInspection */
                $total = count(\enrol_get_enrolled_users($ctx, 'moodle/role:student'));
            } catch (\Throwable $_e) {
                $total = 0;
            }
        }
        $rs = $DB->get_records(self::ATTENDANCE_TABLE, [
            'sessionid' => $sessionid,
            'session_occurrence_date' => $occurrence_ts,
        ]);
        foreach ($rs as $r) {
            switch ((string)$r->status) {
                case 'present': $present++; break;
                case 'absent':  $absent++; break;
                case 'late':    $late++; break;
                case 'excused': $excused++; break;
            }
        }
        $marked = $present + $absent + $late + $excused;
        if ($marked > 0) {
            $pct = number_format(($present / max(1, $total)) * 100, 1);
        } elseif ($total > 0) {
            $pct = number_format(0, 1);
        } else {
            $pct = '0.0';
        }
        return [
            'total' => $total,
            'marked' => $marked,
            'present' => $present,
            'absent' => $absent,
            'late' => $late,
            'excused' => $excused,
            'percent_present' => $pct,
        ];
    }

    public function get_student_attendance_history(int $userid): array {
        global $DB;
        $rows = $DB->get_records(self::ATTENDANCE_TABLE, ['userid' => $userid], 'session_occurrence_date DESC', '*', 0, 200);
        return array_values($rows);
    }

    public function get_dashboard_kpis(int $current_sessionid = 0): array {
        global $DB;
        $sessionsql = "SELECT COUNT(*) FROM {" . self::SESSION_TABLE . "} WHERE status <> 'cancelled'";
        $params = [];
        if ($current_sessionid > 0) {
            $sessionsql .= ' AND sessionid = :sid';
            $params['sid'] = $current_sessionid;
        }
        try {
            $sessioncount = (int)$DB->count_records_sql($sessionsql, $params);
        } catch (\Throwable $_e) {
            $sessioncount = 0;
        }
        $paramsatt = [];
        $attsql = "SELECT COUNT(*) FROM {" . self::ATTENDANCE_TABLE . "} a JOIN {" . self::SESSION_TABLE . "} s ON s.id = a.sessionid WHERE 1=1";
        if ($current_sessionid > 0) {
            $attsql .= ' AND s.sessionid = :sid';
            $paramsatt['sid'] = $current_sessionid;
        }
        try {
            $attcount = (int)$DB->count_records_sql($attsql, $paramsatt);
            $pctsql = "SELECT AVG(CASE WHEN a.status = 'present' THEN 1.0 ELSE 0.0 END) FROM {" . self::ATTENDANCE_TABLE . "} a JOIN {" . self::SESSION_TABLE . "} s ON s.id = a.sessionid WHERE 1=1";
            if ($current_sessionid > 0) {
                $pctsql .= ' AND s.sessionid = :sid2';
                $pctparams = ['sid2' => $current_sessionid];
            } else {
                $pctparams = [];
            }
            $avg = (float)$DB->get_field_sql($pctsql, $pctparams);
            $avgstr = number_format($avg * 100, 1);
        } catch (\Throwable $_e) {
            $attcount = 0;
            $avgstr = '0.0';
        }
        $conflicts = count($this->find_conflicts(0, time()));
        return [
            'session_count' => $sessioncount,
            'attendance_record_count' => $attcount,
            'avg_attendance_percent' => $avgstr,
            'conflict_count' => $conflicts,
        ];
    }
}
