<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_swayam',
        get_string('pluginname', 'local_swayam')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_configtext(
        'local_swayam/apibase',
        get_string('apibase', 'local_swayam'),
        get_string('apibase_desc', 'local_swayam'),
        'https://swayamplus.education.gov.in',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_swayam/apiresource',
        get_string('apiresource', 'local_swayam'),
        get_string('apiresource_desc', 'local_swayam'),
        'https://swayamplus.education.gov.in/api',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configtext(
        'local_swayam/clientid',
        get_string('clientid', 'local_swayam'),
        get_string('clientid_desc', 'local_swayam'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_swayam/clientsecret',
        get_string('clientsecret', 'local_swayam'),
        get_string('clientsecret_desc', 'local_swayam'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'local_swayam/subfield',
        get_string('subfield', 'local_swayam'),
        get_string('subfield_desc', 'local_swayam'),
        'swayamsub',
        PARAM_ALPHANUMEXT
    ));
}
