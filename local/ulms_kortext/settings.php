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

global $hassiteconfig, $ADMIN;

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_ulms_kortext_settings', get_string('pluginname', 'local_ulms_kortext'));

    $settings->add(new admin_setting_configselect(
        'local_ulms_kortext/adapter_mode',
        get_string('settings_adapter_mode', 'local_ulms_kortext'),
        get_string('settings_adapter_mode_desc', 'local_ulms_kortext'),
        'mock',
        [
            'mock'       => get_string('settings_adapter_mode_mock', 'local_ulms_kortext'),
            'production' => get_string('settings_adapter_mode_production', 'local_ulms_kortext'),
        ]
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/oauth2_client_id',
        get_string('settings_oauth2_client_id', 'local_ulms_kortext'),
        get_string('settings_oauth2_client_id_desc', 'local_ulms_kortext'),
        '',
        PARAM_ALPHANUMEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_ulms_kortext/oauth2_client_secret',
        get_string('settings_oauth2_client_secret', 'local_ulms_kortext'),
        get_string('settings_oauth2_client_secret_desc', 'local_ulms_kortext'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/oauth2_token_endpoint',
        get_string('settings_oauth2_token_endpoint', 'local_ulms_kortext'),
        get_string('settings_oauth2_token_endpoint_desc', 'local_ulms_kortext'),
        'https://api.kortext.com/oauth2/token',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/kortext_rest_base',
        get_string('settings_kortext_rest_base', 'local_ulms_kortext'),
        get_string('settings_kortext_rest_base_desc', 'local_ulms_kortext'),
        'https://api.kortext.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/adapter_timeout_sec',
        get_string('settings_adapter_timeout', 'local_ulms_kortext'),
        get_string('settings_adapter_timeout_desc', 'local_ulms_kortext'),
        '5',
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_kortext/sendpii',
        get_string('settings_sendpii', 'local_ulms_kortext'),
        get_string('settings_sendpii_desc', 'local_ulms_kortext'),
        '0'
    ));

    $settings->add(new admin_setting_heading(
        'local_ulms_kortext/heading_lti',
        get_string('settings_heading_lti', 'local_ulms_kortext'),
        get_string('settings_heading_lti_desc', 'local_ulms_kortext')
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/lti_tool_name',
        get_string('settings_lti_tool_name', 'local_ulms_kortext'),
        get_string('settings_lti_tool_name_desc', 'local_ulms_kortext'),
        'Kortext',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/lti_tool_url',
        get_string('settings_lti_tool_url', 'local_ulms_kortext'),
        get_string('settings_lti_tool_url_desc', 'local_ulms_kortext'),
        'https://vle.kortext.com',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/lti_version',
        get_string('settings_lti_version', 'local_ulms_kortext'),
        get_string('settings_lti_version_desc', 'local_ulms_kortext'),
        'LTI 1.3',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/lti_public_keyset_url',
        get_string('settings_lti_jwks', 'local_ulms_kortext'),
        get_string('settings_lti_jwks_desc', 'local_ulms_kortext'),
        'https://vle.kortext.com/api/v1/lti/v1.3/jwks',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/lti_initiate_login_url',
        get_string('settings_lti_initiate_login', 'local_ulms_kortext'),
        get_string('settings_lti_initiate_login_desc', 'local_ulms_kortext'),
        'https://vle.kortext.com/api/v1/lti/v1.3/auth',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_ulms_kortext/lti_redirection_url',
        get_string('settings_lti_redirection', 'local_ulms_kortext'),
        get_string('settings_lti_redirection_desc', 'local_ulms_kortext'),
        'https://vle.kortext.com/api/v1/lti/v1.3/launch',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_kortext/lti_deep_linking',
        get_string('settings_lti_deep_linking', 'local_ulms_kortext'),
        get_string('settings_lti_deep_linking_desc', 'local_ulms_kortext'),
        '1'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_ulms_kortext/lti_share_pii_always',
        get_string('settings_lti_share_pii', 'local_ulms_kortext'),
        get_string('settings_lti_share_pii_desc', 'local_ulms_kortext'),
        '1'
    ));

    $ADMIN->add('localplugins', $settings);
}
