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
 * Ad-hoc task: send one learner's progress or completion to Swayam Plus.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_swayam\task;

/**
 * Queued by the activity and course completion observers.
 */
class push_user_progress extends \core\task\adhoc_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskpushuserprogress', 'local_swayam');
    }

    /**
     * Sends the update.
     */
    public function execute(): void {
        $data     = (array)$this->get_custom_data();
        $userid   = (int)($data['userid'] ?? 0);
        $courseid = (int)($data['courseid'] ?? 0);
        if ($userid <= 0 || $courseid <= 0) {
            return;
        }
        \local_swayam\observer::push_user_progress($userid, $courseid, 'completion_event');
    }
}
