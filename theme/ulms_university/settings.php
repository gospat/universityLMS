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

global $ADMIN, $settings;

if ($ADMIN && $settings && $ADMIN->fulltree) {
    $name = 'theme_ulms_university/brandcolor';
    $title = get_string('brandcolor', 'theme_ulms_university');
    $description = get_string('brandcolordesc', 'theme_ulms_university');
    $default = '#0f4c81';
    $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
    $settings->add($setting);

    $name = 'theme_ulms_university/enable_darkmode';
    $title = get_string('enabledarkmode', 'theme_ulms_university');
    $description = get_string('enabledarkmodedesc', 'theme_ulms_university');
    $default = 1;
    $setting = new admin_setting_configcheckbox($name, $title, $description, $default);
    $settings->add($setting);

    $name = 'theme_ulms_university/dashboardtitle';
    $title = get_string('dashboardtitle', 'theme_ulms_university');
    $description = get_string('dashboardtitledesc', 'theme_ulms_university');
    $default = get_string('dashboardtitledefault', 'theme_ulms_university');
    $setting = new admin_setting_configtext($name, $title, $description, $default, PARAM_TEXT);
    $settings->add($setting);
}
