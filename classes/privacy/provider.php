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
 * Privacy provider for local_swayam.
 *
 * Data held:
 *  - local_swayam_enrolmap  (course context): user + course to Swayam Plus enrollment.
 *  - local_swayam_log       (user context):   integration log entries about the user.
 *  - local_swayam_entitle   (user context):   access received by webhook, keyed by Swayam Plus ID.
 *  - local_swayam_webhook   (course context): processed deliveries, keyed by enrollment ID.
 * Data sent: progress, completion (with final grade) and the Swayam Plus ID to Swayam Plus.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_swayam\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_swayam\observer;

/**
 * Privacy provider for local_swayam.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describes the personal data stored and sent by the plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_swayam_enrolmap', [
            'userid'             => 'privacy:metadata:enrolmap:userid',
            'courseid'           => 'privacy:metadata:enrolmap:courseid',
            'enrollmentid'       => 'privacy:metadata:enrolmap:enrollmentid',
            'swayamsub'          => 'privacy:metadata:enrolmap:swayamsub',
            'status'             => 'privacy:metadata:enrolmap:status',
            'completionsent'     => 'privacy:metadata:enrolmap:completionsent',
            'timecompletionsent' => 'privacy:metadata:enrolmap:timecompletionsent',
            'timecreated'        => 'privacy:metadata:enrolmap:timecreated',
            'timemodified'       => 'privacy:metadata:enrolmap:timemodified',
        ], 'privacy:metadata:enrolmap');

        $collection->add_database_table('local_swayam_entitle', [
            'enrollmentid'    => 'privacy:metadata:entitle:enrollmentid',
            'swayamsub'       => 'privacy:metadata:entitle:swayamsub',
            'partnercourseid' => 'privacy:metadata:entitle:partnercourseid',
            'status'          => 'privacy:metadata:entitle:status',
            'timecreated'     => 'privacy:metadata:entitle:timecreated',
        ], 'privacy:metadata:entitle');

        $collection->add_database_table('local_swayam_webhook', [
            'enrollmentid' => 'privacy:metadata:webhook:enrollmentid',
            'eventtype'    => 'privacy:metadata:webhook:eventtype',
            'timecreated'  => 'privacy:metadata:webhook:timecreated',
        ], 'privacy:metadata:webhook');

        $collection->add_database_table('local_swayam_log', [
            'userid'      => 'privacy:metadata:log:userid',
            'event'       => 'privacy:metadata:log:event',
            'data'        => 'privacy:metadata:log:data',
            'timecreated' => 'privacy:metadata:log:timecreated',
        ], 'privacy:metadata:log');

        $collection->add_external_location_link('swayamplus', [
            'swayamsub'    => 'privacy:metadata:swayamplus:swayamsub',
            'enrollmentid' => 'privacy:metadata:swayamplus:enrollmentid',
            'progress'     => 'privacy:metadata:swayamplus:progress',
            'finalgrade'   => 'privacy:metadata:swayamplus:finalgrade',
        ], 'privacy:metadata:swayamplus');

        return $collection;
    }

    /**
     * Contexts holding data for a user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_swayam_enrolmap} m ON m.courseid = ctx.instanceid AND ctx.contextlevel = :courselevel
                 WHERE m.userid = :userid";
        $contextlist->add_from_sql($sql, ['courselevel' => CONTEXT_COURSE, 'userid' => $userid]);

        if (self::has_user_context_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Users with data in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();

        if ($context instanceof \context_course) {
            $userlist->add_from_sql('userid',
                "SELECT userid FROM {local_swayam_enrolmap} WHERE courseid = :courseid",
                ['courseid' => $context->instanceid]);
        } else if ($context instanceof \context_user) {
            if (self::has_user_context_data((int)$context->instanceid)) {
                $userlist->add_user((int)$context->instanceid);
            }
        }
    }

    /**
     * Exports the user's data.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = (int)$contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                $maps = $DB->get_records('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $context->instanceid]);
                $out = [];
                foreach ($maps as $map) {
                    $out[] = [
                        'enrollmentid'       => $map->enrollmentid,
                        'swayamsub'          => $map->swayamsub,
                        'status'             => $map->status,
                        'completionsent'     => transform::yesno($map->completionsent),
                        'timecompletionsent' => $map->timecompletionsent ? transform::datetime($map->timecompletionsent) : '',
                        'timecreated'        => transform::datetime($map->timecreated),
                        'timemodified'       => transform::datetime($map->timemodified),
                    ];
                }
                if ($out) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:enrolments', 'local_swayam')], (object)['enrolments' => $out]);
                }
            } else if ($context instanceof \context_user && (int)$context->instanceid === $userid) {
                $logs = [];
                foreach ($DB->get_records('local_swayam_log', ['userid' => $userid], 'id') as $log) {
                    $logs[] = [
                        'time'    => transform::datetime($log->timecreated),
                        'level'   => $log->loglevel,
                        'event'   => $log->event,
                        'details' => $log->data,
                    ];
                }
                $ents = [];
                foreach (self::get_entitlements($userid) as $ent) {
                    $ents[] = [
                        'enrollmentid'    => $ent->enrollmentid,
                        'swayamsub'       => $ent->swayamsub,
                        'partnercourseid' => $ent->partnercourseid,
                        'status'          => $ent->status,
                        'timecreated'     => transform::datetime($ent->timecreated),
                    ];
                }
                if ($logs || $ents) {
                    writer::with_context($context)->export_data(
                        [get_string('privacy:path:log', 'local_swayam')], (object)['log' => $logs, 'entitlements' => $ents]);
                }
            }
        }
    }

    /**
     * Deletes all data in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if ($context instanceof \context_course) {
            foreach ($DB->get_fieldset_select('local_swayam_enrolmap', 'userid', 'courseid = ?', [$context->instanceid])
                    as $userid) {
                self::delete_course_data((int)$userid, (int)$context->instanceid);
            }
        } else if ($context instanceof \context_user) {
            self::delete_user_context_data((int)$context->instanceid);
        }
    }

    /**
     * Deletes one user's data in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof \context_course) {
                self::delete_course_data($userid, (int)$context->instanceid);
            } else if ($context instanceof \context_user && (int)$context->instanceid === $userid) {
                self::delete_user_context_data($userid);
            }
        }
    }

    /**
     * Deletes the data of several users in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        $context = $userlist->get_context();
        foreach ($userlist->get_userids() as $userid) {
            if ($context instanceof \context_course) {
                self::delete_course_data((int)$userid, (int)$context->instanceid);
            } else if ($context instanceof \context_user && (int)$context->instanceid === (int)$userid) {
                self::delete_user_context_data((int)$userid);
            }
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Deletes the user's mapping for a course, with the webhook rows and
     * entitlement for the same enrollments.
     *
     * @param int $userid
     * @param int $courseid
     */
    private static function delete_course_data(int $userid, int $courseid): void {
        global $DB;

        $enrollmentids = $DB->get_fieldset_select('local_swayam_enrolmap', 'enrollmentid',
            'userid = ? AND courseid = ?', [$userid, $courseid]);
        if ($enrollmentids) {
            $DB->delete_records_list('local_swayam_webhook', 'enrollmentid', $enrollmentids);
            $DB->delete_records_list('local_swayam_entitle', 'enrollmentid', $enrollmentids);
        }
        $DB->delete_records('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);
    }

    /**
     * Deletes the user's log entries and webhook entitlements.
     *
     * @param int $userid
     */
    private static function delete_user_context_data(int $userid): void {
        global $DB;

        $DB->delete_records('local_swayam_log', ['userid' => $userid]);
        $subs = self::get_user_subs($userid);
        if ($subs) {
            $DB->delete_records_list('local_swayam_entitle', 'swayamsub', $subs);
        }
    }

    /**
     * Whether the user has log entries or entitlements.
     *
     * @param int $userid
     * @return bool
     */
    private static function has_user_context_data(int $userid): bool {
        global $DB;
        return $DB->record_exists('local_swayam_log', ['userid' => $userid])
            || (bool)self::get_entitlements($userid);
    }

    /**
     * Webhook entitlements for the user's Swayam Plus IDs.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    private static function get_entitlements(int $userid): array {
        global $DB;
        $subs = self::get_user_subs($userid);
        if (!$subs) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($subs);
        return $DB->get_records_select('local_swayam_entitle', "swayamsub {$insql}", $params, 'id');
    }

    /**
     * Every verified Swayam Plus ID of the user: mapping rows, the Swayam Plus
     * linked login, and the profile field copy under the same rules as
     * observer::get_swayam_sub() (locked field, Swayam Plus sign-in user).
     *
     * @param int $userid
     * @return string[]
     */
    private static function get_user_subs(int $userid): array {
        global $DB;

        $subs = $DB->get_fieldset_select('local_swayam_enrolmap', 'swayamsub', 'userid = ?', [$userid]);

        $issuerids = observer::get_issuer_ids();
        if ($issuerids) {
            [$insql, $params] = $DB->get_in_or_equal($issuerids, SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $linked = $DB->get_fieldset_select('auth_oauth2_linked_login', 'username',
                "userid = :userid AND issuerid {$insql}", $params);
            $subs = array_merge($subs, $linked);

            $field = observer::get_sub_field();
            if ($linked && $field && !empty($field->locked)) {
                $subs[] = (string)$DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $field->id]);
            }
        }

        $subs = array_map(function ($sub) {
            return strtolower(trim((string)$sub));
        }, $subs);
        return array_values(array_unique(array_filter($subs, [observer::class, 'is_uuid'])));
    }
}
