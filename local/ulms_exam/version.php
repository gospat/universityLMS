<?php
defined('MOODLE_INTERNAL') || die();

/** @var stdClass $plugin */
$plugin->version   = 2026091101;
$plugin->requires  = 2024100700;
$plugin->component = 'local_ulms_exam';
$plugin->release   = '1.0.0';
$plugin->maturity  = MATURITY_STABLE;
$plugin->dependencies = [
    'local_ulms_academics' => 2026080100,
    'local_ulms_dashboard' => 2026080100,
    'local_ulms_auth'      => 2026080100,
];
