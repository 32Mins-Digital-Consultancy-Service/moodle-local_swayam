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

    // ---- Webhook (Swayam Plus -> Moodle) ------------------------------------
    $settings->add(new admin_setting_heading(
        'local_swayam/webhookheading',
        get_string('webhookheading', 'local_swayam'),
        get_string('webhookheading_desc', 'local_swayam', $CFG->wwwroot . '/local/swayam/webhook.php')
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_swayam/webhooksecret',
        get_string('webhooksecret', 'local_swayam'),
        get_string('webhooksecret_desc', 'local_swayam'),
        ''
    ));

    $settings->add(new admin_setting_configselect(
        'local_swayam/revokeaction',
        get_string('revokeaction', 'local_swayam'),
        get_string('revokeaction_desc', 'local_swayam'),
        'suspend',
        [
            'suspend' => get_string('revokeaction_suspend', 'local_swayam'),
            'unenrol' => get_string('revokeaction_unenrol', 'local_swayam'),
        ]
    ));
}
