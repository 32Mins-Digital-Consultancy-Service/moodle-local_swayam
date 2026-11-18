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
 * Backfill local_swayam_enrolmap for existing Swayam Plus sign-in users.
 *
 * Creates mapping rows for learners who are enrolled in Moodle but were
 * never mapped (for example enrolled by hand before the plugin was installed).
 *
 * Usage:
 *   php local/swayam/cli/backfill_enrolmap.php            (dry run)
 *   php local/swayam/cli/backfill_enrolmap.php --execute  (apply)
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['execute' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln("Backfill local_swayam_enrolmap for existing Swayam Plus SSO users.");
    cli_writeln("");
    cli_writeln("Options:");
    cli_writeln("  --execute   Actually write changes (default is dry run).");
    cli_writeln("  -h, --help  Print this help.");
    exit(0);
}

$execute = (bool)$options['execute'];

// Users who signed in with the Swayam Plus OAuth 2 service at least once.
$issuerids = \local_swayam\observer::get_issuer_ids();
if (!$issuerids) {
    cli_error('No OAuth 2 service whose base URL starts with the Swayam Plus API base URL setting.');
}
[$insql, $params] = $DB->get_in_or_equal($issuerids);
$userids = $DB->get_fieldset_sql(
    "SELECT DISTINCT lin.userid
       FROM {auth_oauth2_linked_login} lin
       JOIN {user} u ON u.id = lin.userid
      WHERE u.deleted = 0 AND lin.issuerid {$insql}", $params);

cli_writeln('Found ' . count($userids) . ' candidate Swayam Plus SSO user(s).');
cli_writeln($execute ? 'Mode: EXECUTE (writing changes)' : 'Mode: DRY RUN (pass --execute to apply)');
cli_writeln('');

if (empty($userids)) {
    cli_writeln('Nothing to do.');
    exit(0);
}

$before = $DB->count_records('local_swayam_enrolmap');

foreach ($userids as $userid) {
    $existingcount = $DB->count_records('local_swayam_enrolmap', ['userid' => $userid]);

    if (!$execute) {
        // Dry run: just report whether this user currently has any
        // mapping rows at all, without calling the API or writing.
        cli_writeln("  userid={$userid} — existing enrolmap rows: {$existingcount}");
        continue;
    }

    // Calls the Swayam Plus API, resolves each enrollment's course by
    // ID number and upserts mapping rows. Safe to re-run.
    \local_swayam\observer::sync_user((int)$userid);
}

if ($execute) {
    $after = $DB->count_records('local_swayam_enrolmap');
    cli_writeln('');
    cli_writeln("Done. enrolmap rows before={$before} after={$after} (new=" . ($after - $before) . ')');
    cli_writeln('Check local/swayam/view_logs.php for any LOGIN_COURSE_NOT_FOUND or LOGIN_NO_ENROLLMENTS entries from this run.');
} else {
    cli_writeln('');
    cli_writeln('Dry run complete — re-run with --execute to actually create missing mappings.');
}
