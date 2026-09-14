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

namespace local_ulms_academics;

defined('MOODLE_INTERNAL') || die();

use advanced_testcase;
use local_ulms_academics\form\academic_entity_form;
use local_ulms_auth\local\service\landing_page_service;

require_once(__DIR__ . '/../../../config.php');
require_once(__DIR__ . '/../locallib.php');

/**
 * Tests academics management route-state helpers.
 *
 * @coversNothing
 */
final class manage_routing_test extends advanced_testcase {
    public function test_manage_context_routes_preserve_entity_for_all_supported_entities(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $entities = ['faculties', 'departments', 'programmes', 'sessions', 'semesters'];

        foreach ($entities as $entity) {
            $contextparams = local_ulms_academics_build_manage_context_params([
                'entity' => $entity,
                'search' => 'science',
                'status' => 'active',
                'sort' => 'name',
                'dir' => 'ASC',
                'page' => 2,
                'perpage' => 20,
            ]);

            $url = $routingservice->get_url_for_route('management.academicsmanage', $contextparams);

            $this->assertSame('/management/academics/manage/', $url->get_path());
            $this->assertSame($entity, $url->get_param('entity'), 'Missing entity for ' . $entity);
            $this->assertSame('science', $url->get_param('search'));
            $this->assertSame('active', $url->get_param('status'));
            $this->assertSame('2', $url->get_param('page'));
            $this->assertSame('20', $url->get_param('perpage'));
        }
    }

    public function test_manage_edit_routes_preserve_entity_and_record_id_for_all_supported_entities(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $entities = ['faculties', 'departments', 'programmes', 'sessions', 'semesters'];

        foreach ($entities as $index => $entity) {
            $contextparams = local_ulms_academics_build_manage_context_params([
                'entity' => $entity,
                'search' => '',
                'status' => '',
                'sort' => 'name',
                'dir' => 'ASC',
                'page' => 1,
                'perpage' => 50,
            ]);
            $formparams = local_ulms_academics_build_manage_form_params($contextparams, $index + 10);
            $url = $routingservice->get_url_for_route('management.academicsmanage', $formparams);

            $this->assertSame('/management/academics/manage/', $url->get_path());
            $this->assertSame($entity, $url->get_param('entity'), 'Missing entity for ' . $entity);
            $this->assertSame((string)($index + 10), $url->get_param('id'));
            $this->assertSame('1', $url->get_param('page'));
            $this->assertSame('50', $url->get_param('perpage'));
        }
    }

    public function test_mapping_context_params_exclude_transient_edit_state(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $contextparams = local_ulms_academics_build_mapping_context_params([
            'search' => 'CSC',
            'facultyfilter' => 1,
            'departmentfilter' => 2,
            'programmefilter' => 3,
            'semesterfilter' => 4,
            'coursetypefilter' => 'core',
            'sort' => 'course',
            'dir' => 'DESC',
            'page' => 3,
            'perpage' => 100,
        ]);
        $formparams = local_ulms_academics_build_mapping_form_params($contextparams, 44);

        $contexturl = $routingservice->get_url_for_route('management.academicsmappings', $contextparams);
        $formurl = $routingservice->get_url_for_route('management.academicsmappings', $formparams);

        $this->assertSame('/management/academics/course-mappings/', $contexturl->get_path());
        $this->assertSame('/management/academics/course-mappings/', $formurl->get_path());
        $this->assertNull($contexturl->get_param('editid'));
        $this->assertSame('44', $formurl->get_param('editid'));
        $this->assertSame('3', $contexturl->get_param('page'));
        $this->assertSame('100', $formurl->get_param('perpage'));
    }

    public function test_academic_entity_form_action_can_keep_entity_in_the_query_string(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $formurl = $routingservice->get_url_for_route('management.academicsmanage', [
            'entity' => 'faculties',
            'search' => 'science',
            'sort' => 'name',
            'dir' => 'ASC',
        ]);
        $form = new academic_entity_form($formurl->out(false), [
            'entity' => 'faculties',
            'parentoptions' => [],
        ]);

        ob_start();
        $form->display();
        $html = (string)ob_get_clean();

        $this->assertStringContainsString('action="' . s($formurl->out(false)) . '"', $html);
        $this->assertStringContainsString('/management/academics/manage/?entity=faculties', $html);
        $this->assertStringNotContainsString('/management/academics/manage?entity=faculties', $html);
        $this->assertStringContainsString('name="entity"', $html);
        $this->assertStringContainsString('value="faculties"', $html);
    }

    public function test_academic_entity_edit_form_action_keeps_trailing_slash_and_record_context(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $formurl = $routingservice->get_url_for_route('management.academicsmanage', [
            'entity' => 'departments',
            'id' => 42,
            'search' => 'science',
            'sort' => 'name',
            'dir' => 'ASC',
        ]);
        $form = new academic_entity_form($formurl->out(false), [
            'entity' => 'departments',
            'parentoptions' => [1 => 'College of Science'],
        ]);

        ob_start();
        $form->display();
        $html = (string)ob_get_clean();

        $this->assertStringContainsString('action="' . s($formurl->out(false)) . '"', $html);
        $this->assertStringContainsString('/management/academics/manage/?entity=departments', $html);
        $this->assertStringContainsString('id=42', $html);
        $this->assertStringNotContainsString('/management/academics/manage?entity=departments', $html);
    }

    public function test_manage_filter_actions_keep_trailing_slash_for_all_supported_entities(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $entities = ['faculties', 'departments', 'programmes', 'sessions', 'semesters'];

        foreach ($entities as $entity) {
            $filterurl = $routingservice->get_url_for_route('management.academicsmanage', ['entity' => $entity])->out(false);

            $this->assertStringContainsString('/management/academics/manage/?entity=' . $entity, $filterurl);
            $this->assertStringNotContainsString('/management/academics/manage?entity=' . $entity, $filterurl);
        }
    }

    public function test_academics_delete_urls_keep_trailing_slash_and_entity_context(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $url = $routingservice->get_url_for_route('management.academicsmanage', [
            'entity' => 'departments',
            'action' => 'delete',
            'deleteid' => 42,
            'sesskey' => 'abc123',
        ]);

        $this->assertSame('/management/academics/manage/', $url->get_path());
        $this->assertStringContainsString('entity=departments', $url->out(false));
        $this->assertStringContainsString('/management/academics/manage/?', $url->out(false));
        $this->assertStringNotContainsString('/management/academics/manage?', $url->out(false));
    }

    public function test_course_mapping_form_routes_keep_trailing_slash(): void {
        $this->resetAfterTest(true);

        $routingservice = new landing_page_service();
        $formurl = $routingservice->get_url_for_route('management.academicsmappings', [
            'search' => 'CSC',
            'facultyfilter' => 1,
            'editid' => 6,
        ]);

        $this->assertSame('/management/academics/course-mappings/', $formurl->get_path());
        $this->assertStringContainsString('/management/academics/course-mappings/?', $formurl->out(false));
        $this->assertStringNotContainsString('/management/academics/course-mappings?', $formurl->out(false));
    }
}
