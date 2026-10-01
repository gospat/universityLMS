<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_deleted',
        'callback'  => 'local_ulms_privacy_user_deleted',
        'includefile' => '/local/ulms_privacy/lib.php',
        'priority'  => 200,
        'internal'  => false,
    ],
];
