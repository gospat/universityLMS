<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/ulms_privacy:manageprivacy' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/ulms_privacy:exportowndata' => [
        'riskbitmask' => 0,
        'captype' => 'read',
        'contextlevel' => CONTEXT_USER,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
    'local/ulms_privacy:viewpiiaccesslog' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
