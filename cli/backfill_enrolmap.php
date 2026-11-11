<?php
// local/swayam/cli/backfill_enrolmap.php
//
// One-time backfill: creates local_swayam_enrolmap rows for existing
// Swayam Plus SSO users who are already enrolled in Moodle but were
// never mapped (e.g. bulk/manual enrolment, or enrolment that predates
// this plugin). Without this, module_completed/course_completed find
// no enrolmap row and silently skip pushing progress to Swayam Plus.
//
// Usage:
//   php local/swayam/cli/backfill_enrolmap.php            (dry run)
//   php local/swayam/cli/backfill_enrolmap.php --execute   (apply)

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
$shortname = get_config('local_swayam', 'subfield') ?: 'swayamsub';

// Find every user who has a Swayam Plus sub set on their profile, OR a
// linked OAuth2 login against the Swayam Plus issuer — same two layers
// observer::get_swayam_sub() checks, so we cover both cases here too.
$apibase = rtrim(get_config('local_swayam', 'apibase'), '/');

$sql = "SELECT DISTINCT u.id
          FROM {user} u
          LEFT JOIN {user_info_data} uid
                 ON uid.userid = u.id
          LEFT JOIN {user_info_field} f
                 ON f.id = uid.fieldid AND f.shortname = :shortname
          LEFT JOIN {auth_oauth2_linked_login} lin
                 ON lin.userid = u.id
          LEFT JOIN {oauth2_issuer} iss
                 ON iss.id = lin.issuerid AND " . $DB->sql_like('iss.baseurl', ':baseurl', false) . "
         WHERE u.deleted = 0
           AND (
                (uid.data IS NOT NULL AND uid.data <> '')
                OR lin.id IS NOT NULL
               )";

$params = [
    'shortname' => $shortname,
    'baseurl'   => $DB->sql_like_escape($apibase) . '%',
];

$userids = $DB->get_fieldset_sql($sql, $params);

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

    // sync_enrollments_for_user() calls the Swayam Plus API, resolves
    // each enrollment's course via idnumber, and upserts enrolmap rows.
    // It is safe to re-run for users who are already fully mapped.
    \local_swayam\observer::sync_enrollments_for_user((int)$userid);
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
