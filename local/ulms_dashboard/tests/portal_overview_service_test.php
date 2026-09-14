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

namespace local_ulms_dashboard;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use local_ulms_dashboard\local\service\portal_overview_service;

require_once(__DIR__ . '/../../../config.php');

/**
 * Tests course action links exposed through the admin portal overview.
 *
 * @covers \local_ulms_dashboard\local\service\portal_overview_service
 */
final class portal_overview_service_test extends advanced_testcase {
    public function test_admin_course_actions_use_real_category_ids(): void {
        $this->resetAfterTest(true);

        $category = $this->getDataGenerator()->create_category();
        set_config('defaultrequestcategory', $category->id);

        $manager = $this->getDataGenerator()->create_user();
        $this->assign_system_role($manager, 'manager');
        $this->setUser($manager);

        $service = new portal_overview_service();
        $data = $service->get_admin_overview_data('courses');
        $items = $data['secondarypanels'][0]['items'] ?? [];

        $urls = [];
        foreach ($items as $item) {
            if (!empty($item['url']) && $item['url'] instanceof \moodle_url) {
                $urls[$item['title']] = $item['url'];
            }
        }

        $this->assertArrayHasKey(get_string('addnewcourse'), $urls);
        $this->assertSame('/course/edit.php', $urls[get_string('addnewcourse')]->get_path());
        $this->assertSame((string)$category->id, $urls[get_string('addnewcourse')]->get_param('category'));
        $this->assertSame('catmanage', $urls[get_string('addnewcourse')]->get_param('returnto'));
        $this->assertArrayHasKey(get_string('coursemgmt', 'admin'), $urls);
        $this->assertSame('/course/management.php', $urls[get_string('coursemgmt', 'admin')]->get_path());
        $this->assertSame((string)$category->id, $urls[get_string('coursemgmt', 'admin')]->get_param('categoryid'));
    }

    public function test_student_progress_overview_builds_without_course_lookup_errors(): void {
        $this->resetAfterTest(true);

        $student = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->enrol_user_in_course($student, $course, 'student');
        $this->setUser($student);

        $service = new portal_overview_service();
        $data = $service->get_student_overview_data('progress');
        $items = $data['mainpanel']['items'] ?? [];

        $this->assertCount(1, $items);
        $this->assertSame(format_string($course->fullname), $items[0]['title']);
        $this->assertStringContainsString('0%', $items[0]['meta']);
        $this->assertInstanceOf(\moodle_url::class, $items[0]['url']);
    }

    public function test_student_messages_overview_uses_localised_message_label(): void {
        $this->resetAfterTest(true);

        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $service = new portal_overview_service();
        $data = $service->get_student_overview_data('messages');
        $item = $data['mainpanel']['items'][0] ?? null;

        $this->assertNotNull($item);
        $this->assertSame(get_string('studentmessagespage', 'local_ulms_dashboard'), $item['title']);
    }

    public function test_lecturer_announcements_and_messages_use_lecturer_copy(): void {
        $this->resetAfterTest(true);

        $lecturer = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->enrol_user_in_course($lecturer, $course, 'editingteacher');
        $this->setUser($lecturer);

        $service = new portal_overview_service();

        $announcementdata = $service->get_lecturer_overview_data('announcements');
        $this->assertSame(
            get_string('lecturerannouncementsdesc', 'local_ulms_dashboard'),
            $announcementdata['mainpanel']['subtitle']
        );
        $this->assertSame(
            get_string('lecturerannouncementsdesc', 'local_ulms_dashboard'),
            $announcementdata['summarycards'][0]['description']
        );

        $messagesdata = $service->get_lecturer_overview_data('messages');
        $messageitem = $messagesdata['mainpanel']['items'][0] ?? null;
        $this->assertNotNull($messageitem);
        $this->assertSame(get_string('lecturermessagespage', 'local_ulms_dashboard'), $messageitem['title']);
    }

    /**
     * Assigns a role to a user at system level, creating the role if needed.
     *
     * @param \stdClass $user
     * @param string $roleshortname
     * @return void
     */
    private function assign_system_role(\stdClass $user, string $roleshortname): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id');
        $roleid = $role ? (int)$role->id : create_role(ucfirst($roleshortname), $roleshortname, ucfirst($roleshortname) . ' role');
        role_assign($roleid, $user->id, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();
    }

    /**
     * Enrols a user into a course with the requested role.
     *
     * @param \stdClass $user
     * @param \stdClass $course
     * @param string $roleshortname
     * @return void
     */
    private function enrol_user_in_course(\stdClass $user, \stdClass $course, string $roleshortname): void {
        global $DB;

        $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $roleshortname], MUST_EXIST);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $roleid);
    }
}
