<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_swayam\task\push_progress',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '*/6',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
