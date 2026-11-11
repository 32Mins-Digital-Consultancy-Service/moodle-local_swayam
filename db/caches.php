<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'token' => [
        'mode'                   => cache_store::MODE_APPLICATION,
        'ttl'                    => 540,
        'simplekeys'             => true,
        'simpledata'             => true,
        'staticacceleration'     => true,
        'staticaccelerationsize' => 1,
    ],
];
