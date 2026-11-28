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

    $settings->add(new admin_setting_heading(
        'local_ulms_auth/ratelimitsettings',
        get_string('ratelimitsettings', 'local_ulms_auth'),
        get_string('ratelimitsettingsdesc', 'local_ulms_auth')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_auth/enableratelimit',
        get_string('enableratelimit', 'local_ulms_auth'),
        get_string('enableratelimitdesc', 'local_ulms_auth'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'local_ulms_auth/ratelimitbackend',
        get_string('ratelimitbackend', 'local_ulms_auth'),
        get_string('ratelimitbackenddesc', 'local_ulms_auth'),
        'auto',
        [
            'auto' => get_string('ratelimitbackendauto', 'local_ulms_auth'),
            'redis' => get_string('ratelimitbackendredis', 'local_ulms_auth'),
            'file' => get_string('ratelimitbackendfile', 'local_ulms_auth'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_auth/ratelimitfallback',
        get_string('ratelimitfallback', 'local_ulms_auth'),
        get_string('ratelimitfallbackdesc', 'local_ulms_auth'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_auth/ratelimitsecuritylogging',
        get_string('ratelimitsecuritylogging', 'local_ulms_auth'),
        get_string('ratelimitsecurityloggingdesc', 'local_ulms_auth'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitcombolimit',
        get_string('ratelimitcombolimit', 'local_ulms_auth'),
        get_string('ratelimitcombolimitdesc', 'local_ulms_auth'),
        5,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitcombowindow',
        get_string('ratelimitcombowindow', 'local_ulms_auth'),
        get_string('ratelimitcombowindowdesc', 'local_ulms_auth'),
        180,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitaccountlimit',
        get_string('ratelimitaccountlimit', 'local_ulms_auth'),
        get_string('ratelimitaccountlimitdesc', 'local_ulms_auth'),
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitaccountwindow',
        get_string('ratelimitaccountwindow', 'local_ulms_auth'),
        get_string('ratelimitaccountwindowdesc', 'local_ulms_auth'),
        900,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitabuseiplimit',
        get_string('ratelimitabuseiplimit', 'local_ulms_auth'),
        get_string('ratelimitabuseiplimitdesc', 'local_ulms_auth'),
        300,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitabuseipwindow',
        get_string('ratelimitabuseipwindow', 'local_ulms_auth'),
        get_string('ratelimitabuseipwindowdesc', 'local_ulms_auth'),
        300,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_ulms_auth/ratelimitabuseipmode',
        get_string('ratelimitabuseipmode', 'local_ulms_auth'),
        get_string('ratelimitabuseipmodedesc', 'local_ulms_auth'),
        'detect',
        [
            'detect' => get_string('ratelimitabuseipmodedetect', 'local_ulms_auth'),
            'block' => get_string('ratelimitabuseipmodeblock', 'local_ulms_auth'),
            'off' => get_string('ratelimitabuseipmodeoff', 'local_ulms_auth'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitprogressivelimit',
        get_string('ratelimitprogressivelimit', 'local_ulms_auth'),
        get_string('ratelimitprogressivelimitdesc', 'local_ulms_auth'),
        2,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitabuseiphardlimit',
        get_string('ratelimitabuseiphardlimit', 'local_ulms_auth'),
        get_string('ratelimitabuseiphardlimitdesc', 'local_ulms_auth'),
        0,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitminimumretry',
        get_string('ratelimitminimumretry', 'local_ulms_auth'),
        get_string('ratelimitminimumretrydesc', 'local_ulms_auth'),
        15,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/ratelimitmaximumretry',
        get_string('ratelimitmaximumretry', 'local_ulms_auth'),
        get_string('ratelimitmaximumretrydesc', 'local_ulms_auth'),
        900,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_ulms_auth/ratelimitdegradedmode',
        get_string('ratelimitdegradedmode', 'local_ulms_auth'),
        get_string('ratelimitdegradedmodedesc', 'local_ulms_auth'),
        'failopen',
        [
            'failopen' => get_string('ratelimitdegradedmodefailopen', 'local_ulms_auth'),
            'failclosed' => get_string('ratelimitdegradedmodefailclosed', 'local_ulms_auth'),
        ]
    ));

    $settings->add(new admin_setting_heading(
        'local_ulms_auth/ratelimitredisheading',
        get_string('ratelimitredisheading', 'local_ulms_auth'),
        get_string('ratelimitredisheadingdesc', 'local_ulms_auth')
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/redishost',
        get_string('redishost', 'local_ulms_auth'),
        get_string('redishostdesc', 'local_ulms_auth'),
        '127.0.0.1',
        PARAM_HOST
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/redisport',
        get_string('redisport', 'local_ulms_auth'),
        get_string('redisportdesc', 'local_ulms_auth'),
        6379,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/redisacluser',
        get_string('redisacluser', 'local_ulms_auth'),
        get_string('redisacluserdesc', 'local_ulms_auth'),
        'ulms_auth',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/rediscredentialfile',
        get_string('rediscredentialfile', 'local_ulms_auth'),
        get_string('rediscredentialfiledesc', 'local_ulms_auth'),
        '/etc/ulms/redis-auth.secret',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/redisconnecttimeout',
        get_string('redisconnecttimeout', 'local_ulms_auth'),
        get_string('redisconnecttimeoutdesc', 'local_ulms_auth'),
        '1.5',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/redisreadtimeout',
        get_string('redisreadtimeout', 'local_ulms_auth'),
        get_string('redisreadtimeoutdesc', 'local_ulms_auth'),
        '1.5',
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_heading(
        'local_ulms_auth/ratelimitproxyheading',
        get_string('ratelimitproxyheading', 'local_ulms_auth'),
        get_string('ratelimitproxyheadingdesc', 'local_ulms_auth')
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_ulms_auth/trustedproxies',
        get_string('trustedproxies', 'local_ulms_auth'),
        get_string('trustedproxiesdesc', 'local_ulms_auth'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_auth/trustedproxyheaders',
        get_string('trustedproxyheaders', 'local_ulms_auth'),
        get_string('trustedproxyheadersdesc', 'local_ulms_auth'),
        'cf-connecting-ip,x-real-ip,x-forwarded-for',
        PARAM_TEXT
    ));

    $ADMIN->add('localplugins', $settings);
}
