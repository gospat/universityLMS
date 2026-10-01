<?php
defined('MOODLE_INTERNAL') || die();

/** @var stdClass $plugin */
$plugin->component = 'local_ulms_privacy';
$plugin->version = 2026100100;
$plugin->requires = 2024042200;
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0.0';
$plugin->dependencies = [
    'local_ulms_dashboard' => 2026091703,
    'local_ulms_auth' => 2026091601,
];
