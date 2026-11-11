<?php
// local/swayam/db/upgrade.php
//
// Upgrade steps for local_swayam. ADDITIVE ONLY: new columns are added with
// default values. No existing table, column or row is dropped or changed.

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
        upgrade_plugin_savepoint(true, 2026111000, 'local', 'swayam');
    }

    return true;
}
