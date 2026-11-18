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
 * Upgrade steps for local_swayam.
 *
 * Additive only: new columns and tables are added; no existing table,
 * column or row of mapping data is dropped.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade steps.
 *
 * @param int $oldversion version the site is upgrading from
 * @return bool
 */
function xmldb_local_swayam_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // Build 2026111001 repeats every step of 2026111000. Sites that had an
    // earlier v1.0.3 build (2026111000, before the webhook was added) never
    // ran the webhook steps. Every step checks before it adds, so re-running
    // on a fully upgraded site changes nothing.
    if ($oldversion < 2026111001) {
        $table = new xmldb_table('local_swayam_enrolmap');

        $fields = [
            // Sites first installed before v1.0.2 have no status column
            // (v1.0.2 added it to install.xml without an upgrade step).
            new xmldb_field('status', XMLDB_TYPE_CHAR, '30', null,
                XMLDB_NOTNULL, null, 'ACCESS_PROVISIONED', 'swayamsub'),
            new xmldb_field('completionsent', XMLDB_TYPE_INTEGER, '1', null,
                XMLDB_NOTNULL, null, '0', 'timemodified'),
            new xmldb_field('timecompletionsent', XMLDB_TYPE_INTEGER, '10', null,
                XMLDB_NOTNULL, null, '0', 'completionsent'),
            new xmldb_field('completionattempts', XMLDB_TYPE_INTEGER, '5', null,
                XMLDB_NOTNULL, null, '0', 'timecompletionsent'),
        ];

        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        // Existing rows get completionsent = 0, so the scheduled task will
        // (re)send /completion for every IN_PROGRESS learner already at 100%.

        // Webhook: entitlements + last applied sequence per enrollment.
        $table = new xmldb_table('local_swayam_entitle');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('enrollmentid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('swayamsub', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('partnercourseid', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, 'ACCESS_PROVISIONED');
            $table->add_field('reason', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sequence', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sandbox', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('enrollmentid', XMLDB_INDEX_UNIQUE, ['enrollmentid']);
            $table->add_index('swayamsub', XMLDB_INDEX_NOTUNIQUE, ['swayamsub']);
            $dbman->create_table($table);
        }

        // Webhook: processed deliveries (duplicate detection).
        $table = new xmldb_table('local_swayam_webhook');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('deliveryid', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('eventtype', XMLDB_TYPE_CHAR, '40', null, XMLDB_NOTNULL, null, null);
            $table->add_field('enrollmentid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('sequence', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('sandbox', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('outcome', XMLDB_TYPE_CHAR, '30', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('deliveryid', XMLDB_INDEX_UNIQUE, ['deliveryid']);
            $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026111001, 'local', 'swayam');
    }

    if ($oldversion < 2026111800) {
        // Integration log moves from moodledata/local_swayam/swayam.log to a table.
        $table = new xmldb_table('local_swayam_log');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('loglevel', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('event', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('data', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);
            $table->add_index('loglevel', XMLDB_INDEX_NOTUNIQUE, ['loglevel']);
            $dbman->create_table($table);
        }

        // The Swayam Plus ID profile field must not be editable by learners
        // (and need not be visible to them): lock it and hide it.
        $shortname = get_config('local_swayam', 'subfield') ?: 'swayamsub';
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id, locked, visible');
        if ($field && (empty($field->locked) || (int)$field->visible !== 0)) {
            $DB->update_record('user_info_field', (object)['id' => $field->id, 'locked' => 1, 'visible' => 0]);
        }

        upgrade_plugin_savepoint(true, 2026111800, 'local', 'swayam');
    }

    return true;
}
