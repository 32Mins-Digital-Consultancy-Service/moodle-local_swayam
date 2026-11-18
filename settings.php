<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin settings for local_swayam.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_swayam',
        get_string('pluginname', 'local_swayam')
    );
    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_swayam_logs',
        get_string('logs', 'local_swayam'),
        new moodle_url('/local/swayam/view_logs.php'),
        'moodle/site:config'
    ));

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

    // ---- Integration log -----------------------------------------------------
    $settings->add(new admin_setting_heading(
        'local_swayam/logheading',
        get_string('logs', 'local_swayam'),
        get_string('logheading_desc', 'local_swayam', (new moodle_url('/local/swayam/view_logs.php'))->out())
    ));

    $settings->add(new admin_setting_configselect(
        'local_swayam/logretention',
        get_string('logretention', 'local_swayam'),
        get_string('logretention_desc', 'local_swayam'),
        30,
        [
            7   => get_string('numdays', 'core', 7),
            30  => get_string('numdays', 'core', 30),
            90  => get_string('numdays', 'core', 90),
            180 => get_string('numdays', 'core', 180),
            365 => get_string('numdays', 'core', 365),
        ]
    ));
}
