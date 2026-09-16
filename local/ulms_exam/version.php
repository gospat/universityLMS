<?php
defined('MOODLE_INTERNAL') || die();

if (!isset($plugin)) {
    $plugin = new stdClass();
}

/** @var stdClass $plugin */
$plugin->version   = 2026091601;
$plugin->requires  = 2024100700;
$plugin->component = 'local_ulms_exam';
$plugin->release   = '1.1.1';
$plugin->maturity  = MATURITY_STABLE;
$plugin->dependencies = [
    'local_ulms_academics' => 2026091601,
    'local_ulms_dashboard' => 2026091602,
    'local_ulms_auth'      => 2026082601,
];
