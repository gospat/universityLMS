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

global $ADMIN, $hassiteconfig;
$routingservice = new \local_ulms_auth\local\service\landing_page_service();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_ulms_academics',
        get_string('pluginname', 'local_ulms_academics')
    );

    $settings->add(new admin_setting_heading(
        'local_ulms_academics/generalheading',
        get_string('generalsettings', 'local_ulms_academics'),
        get_string('generalsettingsdesc', 'local_ulms_academics')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_academics/enableacademics',
        get_string('enableacademics', 'local_ulms_academics'),
        get_string('enableacademicsdesc', 'local_ulms_academics'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_academics/currentsessioncode',
        get_string('currentsessioncode', 'local_ulms_academics'),
        get_string('currentsessioncodedesc', 'local_ulms_academics'),
        '',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ulms_academics_manage',
        get_string('manageacademics', 'local_ulms_academics'),
        $routingservice->get_url_for_route('management.academics'),
        'local/ulms_academics:manageacademics'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ulms_academics_reports',
        get_string('managereports', 'local_ulms_academics'),
        $routingservice->get_url_for_route('management.academicsreports'),
        'local/ulms_academics:viewreports'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ulms_academics_import',
        get_string('manageimport', 'local_ulms_academics'),
        $routingservice->get_url_for_route('management.academicsimport'),
        'local/ulms_academics:manageacademics'
    ));

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_ulms_academics_course_mappings',
        get_string('managecoursemappings', 'local_ulms_academics'),
        $routingservice->get_url_for_route('management.academicsmappings'),
        'local/ulms_academics:manageacademics'
    ));
}
