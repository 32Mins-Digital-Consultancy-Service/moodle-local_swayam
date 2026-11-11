<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\core\event\user_loggedin',
        'callback'  => '\local_swayam\observer::user_loggedin',
        'priority'  => 200,
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_module_completion_updated',
        'callback'  => '\local_swayam\observer::module_completed',
        'priority'  => 200,
        'internal'  => false,
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback'  => '\local_swayam\observer::course_completed',
        'priority'  => 200,
        'internal'  => false,
    ],
];
