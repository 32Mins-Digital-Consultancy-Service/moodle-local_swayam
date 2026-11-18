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
 * Ad-hoc task: enrolment sync for one learner after sign-in.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_swayam\task;

/**
 * Fetches the learner's Swayam Plus enrollments and enrols them in the
 * matching Moodle courses. Queued by the user_loggedin observer.
 */
class sync_user extends \core\task\adhoc_task {

    /**
     * Task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('tasksyncuser', 'local_swayam');
    }

    /**
     * Runs the sync.
     */
    public function execute(): void {
        $data   = (array)$this->get_custom_data();
        $userid = (int)($data['userid'] ?? 0);
        if ($userid <= 0) {
            return;
        }
        \local_swayam\observer::sync_user($userid);
    }
}
