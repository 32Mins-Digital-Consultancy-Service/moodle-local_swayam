<?php
// local/swayam/cli/resend_completion.php
//
// Finds Swayam Plus learners who are at 100% in Moodle but whose completion
// was never accepted by Swayam Plus (the "98% on the portal" problem), and
// sends /completion for them. Read-only on Moodle data; it only updates the
// plugin's own completionsent / completionattempts columns.
//
// Usage:
//   php local/swayam/cli/resend_completion.php                       (dry run, all)
//   php local/swayam/cli/resend_completion.php --execute             (send for all)
//   php local/swayam/cli/resend_completion.php --userid=123 --courseid=45 --execute
//   add --force to resend even if completionsent = 1

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

list($options, $unrecognized) = cli_get_params(
    ['execute' => false, 'force' => false, 'userid' => 0, 'courseid' => 0, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln("Send Swayam Plus /completion for learners at 100% in Moodle.");
    cli_writeln("  --execute         Actually call the API (default: dry run)");
    cli_writeln("  --userid=ID       Only this Moodle user");
    cli_writeln("  --courseid=ID     Only this Moodle course");
    cli_writeln("  --force           Resend even if already marked as sent");
    exit(0);
}

$execute = (bool)$options['execute'];
$force   = (bool)$options['force'];

$params = [];
if (!empty($options['userid']))   { $params['userid']   = (int)$options['userid']; }
if (!empty($options['courseid'])) { $params['courseid'] = (int)$options['courseid']; }

$maps = $DB->get_records('local_swayam_enrolmap', $params);
cli_writeln('Checking ' . count($maps) . ' mapped enrollment(s). Mode: ' . ($execute ? 'EXECUTE' : 'DRY RUN'));

$candidates = 0; $sent = 0; $failed = 0;

foreach ($maps as $map) {
    $userid   = (int)$map->userid;
    $courseid = (int)$map->courseid;
    $label    = "userid={$userid} courseid={$courseid} enrollmentId={$map->enrollmentid} status={$map->status}";

    if (!$force && !empty($map->completionsent)) {
        continue;
    }

    $percent = \local_swayam\observer::calculate_progress($userid, $courseid);
    if ($percent < 100) {
        if (!empty($params)) {
            cli_writeln("  [--] {$label} — Moodle progress {$percent}%, not complete");
        }
        continue;
    }

    $candidates++;
    if ($map->status !== 'IN_PROGRESS') {
        cli_writeln("  [!!] {$label} — at 100% but status is not IN_PROGRESS; Swayam Plus may reject it");
    }

    if (!$execute) {
        cli_writeln("  [DRY] {$label} — 100% in Moodle, completion would be sent");
        continue;
    }

    if (\local_swayam\observer::send_completion($map, $userid, $courseid, 'cli', $force)) {
        $sent++;
        cli_writeln("  [OK]  {$label} — completion sent");
    } else {
        $failed++;
        cli_writeln("  [ERR] {$label} — failed, see local/swayam/view_logs.php");
    }
}

cli_writeln('');
cli_writeln("Learners at 100% needing completion: {$candidates}" .
    ($execute ? "  sent={$sent} failed={$failed}" : '  (dry run — add --execute to send)'));
