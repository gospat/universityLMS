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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/ulms_dashboard/classes/local/service/dashboard_service.php');

/**
 * Starter dashboard widget block for ULMS.
 */
class block_ulms_dashboard_widgets extends block_base {
    /**
     * Initializes the block title.
     */
    public function init(): void {
        $this->title = get_string('pluginname', 'block_ulms_dashboard_widgets');
    }

    /**
     * Returns block content.
     *
     * @return stdClass
     */
    public function get_content(): stdClass {
        if ($this->content !== null) {
            return $this->content;
        }

        $service = new \local_ulms_dashboard\local\service\dashboard_service();
        $snapshot = $service->get_current_user_snapshot();
        $items = [];

        $items[] = html_writer::tag(
            'strong',
            get_string('welcomeuser', 'block_ulms_dashboard_widgets', format_string($snapshot['fullname']))
        );
        $items[] = get_string('rolelabel', 'block_ulms_dashboard_widgets', format_string($snapshot['roleshortname']));
        $items[] = get_string('coursecountlabel', 'block_ulms_dashboard_widgets', $snapshot['coursecount']);
        $items[] = get_string('notificationcountlabel', 'block_ulms_dashboard_widgets', $snapshot['notificationcount']);
        $items[] = get_string(
            'completioncountlabel',
            'block_ulms_dashboard_widgets',
            $snapshot['completion']['completed'] . '/' . $snapshot['completion']['total']
        );

        foreach ($snapshot['courses'] as $course) {
            $items[] = html_writer::link($course['url'], format_string($course['fullname']));
        }

        foreach ($snapshot['deadlines'] as $deadline) {
            $items[] = get_string('deadlinelabel', 'block_ulms_dashboard_widgets') . ': ' .
                html_writer::link($deadline['url'], format_string($deadline['title'])) .
                ' (' . s($deadline['time']) . ')';
        }

        $items[] = html_writer::link(
            $snapshot['calendarurl'],
            get_string('viewcalendar', 'block_ulms_dashboard_widgets')
        );

        $this->content = new stdClass();
        $this->content->text = html_writer::alist($items);
        $this->content->footer = '';

        return $this->content;
    }

    /**
     * Limits pages where the block may appear.
     *
     * @return array
     */
    public function applicable_formats(): array {
        return [
            'my' => true,
            'site-index' => true,
            'course-view' => true,
        ];
    }

    /**
     * Allows multiple instances.
     *
     * @return bool
     */
    public function instance_allow_multiple(): bool {
        return true;
    }
}
