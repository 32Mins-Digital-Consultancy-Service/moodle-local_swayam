<?php
// local/swayam/db/upgrade.php
//
// Upgrade steps for local_swayam. ADDITIVE ONLY: new columns and new
// tables are added. No existing table, column or row is dropped or changed.

defined('MOODLE_INTERNAL') || die();

function xmldb_local_swayam_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111000) {
        $table = new xmldb_table('local_swayam_enrolmap');

        $fields = [
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
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'swayam');
    }

    return true;
}
