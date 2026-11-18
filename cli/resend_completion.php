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
 * Send /completion for learners at 100% in Moodle whose completion was never
 * accepted by Swayam Plus. Only updates the plugin's own columns.
 *
 * Usage:
 *   php local/swayam/cli/resend_completion.php                       (dry run, all)
 *   php local/swayam/cli/resend_completion.php --execute             (send for all)
 *   php local/swayam/cli/resend_completion.php --userid=123 --courseid=45 --execute
 *   add --force to resend even if already sent
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

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
