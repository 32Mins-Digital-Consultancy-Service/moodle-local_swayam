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
 * Scheduled task: safety-net progress and completion sync.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_swayam\task;

/**
 * Every 6 hours, for each IN_PROGRESS enrollment not yet completed:
 * progress below 100% -> POST /progress; at 100% -> POST /completion
 * (retries completions that failed or were missed).
 */
class push_progress extends \core\task\scheduled_task {

    /** Stop retrying a completion after this many failed attempts (about 7.5 days at 6h). */
    const MAX_COMPLETION_ATTEMPTS = 30;

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskpushprogress', 'local_swayam');
    }

    /**
     * Runs the sync.
     */
    public function execute(): void {
        global $DB;

        $maps = $DB->get_records('local_swayam_enrolmap', ['status' => 'IN_PROGRESS', 'completionsent' => 0]);
        if (empty($maps)) {
            mtrace('local_swayam push_progress: nothing to send');
            return;
        }

        mtrace('local_swayam push_progress: checking ' . count($maps) . ' enrollment(s)');
        $counts = ['progress' => 0, 'completed' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($maps as $map) {
            $userid   = (int)$map->userid;
            $courseid = (int)$map->courseid;

            if ((int)$map->completionattempts >= self::MAX_COMPLETION_ATTEMPTS) {
                mtrace("  [SKIP] userid={$userid} courseid={$courseid}: completion failed "
                    . self::MAX_COMPLETION_ATTEMPTS . " times, run cli/resend_completion.php");
                $counts['skipped']++;
                continue;
            }

            try {
                $result = \local_swayam\observer::push_user_progress($userid, $courseid, 'scheduled_task');
            } catch (\Throwable $e) {
                $result = 'failed';
                mtrace("  [ERR] userid={$userid} courseid={$courseid}: " . $e->getMessage());
            }
            $counts[$result]++;
            if ($result !== 'skipped') {
                mtrace("  [{$result}] userid={$userid} courseid={$courseid}");
            }
        }

        mtrace("local_swayam push_progress: done. progress={$counts['progress']} completions={$counts['completed']} "
            . "skipped={$counts['skipped']} errors={$counts['failed']}");
    }
}
