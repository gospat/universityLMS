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

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_ulms_auth',
        get_string('pluginname', 'local_ulms_auth')
    );

    $settings->add(new admin_setting_heading(
        'local_ulms_auth/generalheading',
        get_string('generalsettings', 'local_ulms_auth'),
        get_string('generalsettingsdesc', 'local_ulms_auth')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_auth/enablerolelanding',
        get_string('enablerolelanding', 'local_ulms_auth'),
        get_string('enablerolelandingdesc', 'local_ulms_auth'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_auth/enforceadminmfa',
        get_string('enforceadminmfa', 'local_ulms_auth'),
        get_string('enforceadminmfadesc', 'local_ulms_auth'),
        0
    ));

    $ADMIN->add('localplugins', $settings);
}
