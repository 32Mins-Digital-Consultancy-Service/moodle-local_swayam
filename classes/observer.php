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
 * Event observers and Swayam Plus API helpers.
 *
 * The observers only check the plugin's own mapping table and queue an
 * ad-hoc task; every API call and enrolment change runs in a task (or in
 * launch.php, a page the learner opens from Swayam Plus).
 *
 *  - user_loggedin                    -> task\sync_user (enrolment sync)
 *  - course_module_completion_updated -> task\push_user_progress (POST /progress)
 *  - course_completed                 -> task\push_user_progress (POST /completion)
 *
 * Inbound webhooks (access granted / ended / completed): see webhook.php.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_swayam;

/**
 * Event observers and Swayam Plus API helpers.
 */
class observer {

    /** Swayam Plus learner and enrollment IDs are UUIDs. */
    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Access token for the scheduled and ad-hoc tasks.
     *
     * @return string|null
     */
    public static function get_token_for_task(): ?string {
        return self::token();
    }

    // =========================================================================
    // TOKEN  (cached 540s, under the 600s token lifetime)
    // =========================================================================

    /**
     * Mints (or returns the cached) client_credentials access token.
     *
     * @return string|null
     */
    private static function token(): ?string {
        $cache = \cache::make('local_swayam', 'token');

        $cached = $cache->get('m2m');
        if ($cached !== false && $cached !== null) {
            $parts = explode('.', $cached);
            if (isset($parts[1])) {
                $padded  = str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4, '=');
                $payload = json_decode(base64_decode($padded), true);
                if (!empty($payload['scope']) && strpos($payload['scope'], 'partner.enrollments:read') !== false) {
                    return $cached;
                }
            }
            $cache->delete('m2m');
            logger::info('TOKEN_CACHE_CLEARED', ['reason' => 'Stale or missing scope']);
        }

        $base   = rtrim((string)get_config('local_swayam', 'apibase'), '/');
        $res    = get_config('local_swayam', 'apiresource');
        $cid    = get_config('local_swayam', 'clientid');
        $secret = get_config('local_swayam', 'clientsecret');

        if (empty($base) || empty($res) || empty($cid) || empty($secret)) {
            logger::error('TOKEN_MINT_FAILED', [
                'reason' => 'Plugin not fully configured: check Site administration > Plugins > Local plugins > Swayam Plus',
            ]);
            return null;
        }

        // Swayam Plus accepts application/x-www-form-urlencoded only.
        $postbody = http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'partner.enrollments:read partner.completions:write',
            'resource'   => $res,
        ], '', '&', PHP_QUERY_RFC3986);

        $curl = self::client();
        $curl->setHeader('Authorization: Basic ' . base64_encode("{$cid}:{$secret}"));
        $curl->setHeader('Content-Type: application/x-www-form-urlencoded');

        $body = $curl->post("{$base}/oidc/token", $postbody);
        $info = $curl->get_info();
        $json = json_decode((string)$body, true);

        if (empty($json['access_token'])) {
            logger::error('TOKEN_MINT_FAILED', [
                'http'     => $info['http_code'] ?? 0,
                'error'    => self::response_error($body, $curl),
                'endpoint' => "{$base}/oidc/token",
            ]);
            return null;
        }

        logger::info('TOKEN_MINTED', [
            'scope'      => $json['scope'] ?? 'unknown',
            'expires_in' => $json['expires_in'] ?? 'unknown',
        ]);

        $cache->set('m2m', $json['access_token']);
        return $json['access_token'];
    }

    // =========================================================================
    // HTTP HELPERS
    // =========================================================================

    /**
     * cURL client. The core security helper stays on, so the site's blocked
     * hosts and allowed ports settings apply to every Swayam Plus request.
     *
     * @return \curl
     */
    private static function client(): \curl {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        return new \curl();
    }

    /**
     * Short error text for the log: the cURL error (for example a URL blocked
     * by the site's HTTP security settings) or the error code from the JSON
     * response. Response bodies are never logged.
     *
     * @param string|null|bool $raw response body
     * @param \curl $curl
     * @return string
     */
    private static function response_error($raw, \curl $curl): string {
        if (!empty($curl->error)) {
            return \core_text::substr((string)$curl->error, 0, 200)
                . ' (if the host is blocked, check Site administration > General > Security > HTTP security)';
        }
        $json  = json_decode((string)$raw, true);
        $parts = [];
        if (is_array($json)) {
            foreach (['error', 'code', 'title'] as $key) {
                if (isset($json[$key]) && is_scalar($json[$key])) {
                    $parts[] = (string)$json[$key];
                }
            }
        }
        return \core_text::substr(implode(' / ', $parts), 0, 200);
    }

    /**
     * GET request to the Swayam Plus API.
     *
     * @param string $path path below the API base URL
     * @param string $token access token
     * @param string $label what is being fetched, for the log (no learner IDs)
     * @return array|null decoded JSON, or null on failure
     */
    private static function api_get(string $path, string $token, string $label): ?array {
        $base = rtrim((string)get_config('local_swayam', 'apibase'), '/');

        $curl = self::client();
        $curl->setHeader("Authorization: Bearer {$token}");
        $curl->setHeader('Accept: application/json');

        $raw  = $curl->get($base . $path);
        $info = $curl->get_info();
        $http = (int)($info['http_code'] ?? 0);

        if ($http !== 200) {
            logger::error('API_GET_FAILED', ['api' => $label, 'http' => $http, 'error' => self::response_error($raw, $curl)]);
            return null;
        }

        logger::info('API_GET', ['api' => $label, 'http' => $http]);
        $json = json_decode((string)$raw, true);
        return is_array($json) ? $json : null;
    }

    /**
     * POST request to the Swayam Plus API.
     *
     * @param string $path path below the API base URL
     * @param string $token access token
     * @param array $payload JSON body
     * @return array [int http code, string short error text]
     */
    public static function api_post(string $path, string $token, array $payload): array {
        $base = rtrim((string)get_config('local_swayam', 'apibase'), '/');

        $curl = self::client();
        $curl->setHeader("Authorization: Bearer {$token}");
        $curl->setHeader('Content-Type: application/json');
        $curl->setHeader('Accept: application/json');

        $response = $curl->post($base . $path, json_encode($payload));
        $info     = $curl->get_info();
        $http     = (int)($info['http_code'] ?? 0);
        $error    = $http === 204 ? '' : self::response_error($response, $curl);

        if ($http !== 204) {
            logger::error('API_POST_FAILED', ['path' => $path, 'http' => $http, 'error' => $error]);
        }
        return [$http, $error];
    }

    // =========================================================================
    // SWAYAM PLUS IDENTITY
    // =========================================================================

    /**
     * Whether a string is a Swayam Plus ID (UUID).
     *
     * @param string $value
     * @return bool
     */
    public static function is_uuid(string $value): bool {
        return (bool)preg_match(self::UUID, $value);
    }

    /**
     * IDs of the OAuth 2 services whose base URL starts with the API base URL
     * setting (the Swayam Plus issuer).
     *
     * @return int[]
     */
    public static function get_issuer_ids(): array {
        global $DB;

        $apibase = rtrim((string)get_config('local_swayam', 'apibase'), '/');
        if ($apibase === '') {
            return [];
        }
        $ids = $DB->get_fieldset_sql(
            "SELECT id FROM {oauth2_issuer} WHERE " . $DB->sql_like('baseurl', ':baseurl', false) . " ORDER BY id",
            ['baseurl' => $DB->sql_like_escape($apibase) . '%']
        );
        return array_map('intval', $ids);
    }

    /**
     * Whether the user has signed in with the Swayam Plus OAuth 2 service at
     * least once (a verified linked login exists).
     *
     * @param int $userid
     * @return bool
     */
    public static function has_swayam_login(int $userid): bool {
        global $DB;

        $issuerids = self::get_issuer_ids();
        if (!$issuerids) {
            return false;
        }
        [$insql, $params] = $DB->get_in_or_equal($issuerids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        return $DB->record_exists_select('auth_oauth2_linked_login', "userid = :userid AND issuerid {$insql}", $params);
    }

    /**
     * The custom profile field that holds a copy of the Swayam Plus ID.
     *
     * @return \stdClass|null id, shortname, locked, visible
     */
    public static function get_sub_field(): ?\stdClass {
        global $DB;
        $shortname = get_config('local_swayam', 'subfield') ?: 'swayamsub';
        $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id, shortname, locked, visible');
        return $field ?: null;
    }

    /**
     * Returns the Swayam Plus learner ID (OIDC sub) of a Moodle user.
     *
     * Only identities verified by the Swayam Plus sign-in are trusted:
     *  1. The user must have a linked login for the Swayam Plus OAuth 2
     *     service. Accounts that never signed in with Swayam Plus get null.
     *  2. If that linked login's username is the sub (the service maps
     *     "sub" to "username"), it is used, and it always wins.
     *  3. Otherwise the profile field filled by the OAuth 2 field mapping
     *     ("sub" to the profile field) is used, but only while the field is
     *     locked, so learners cannot change it.
     *
     * @param int $userid
     * @return string|null lower-case UUID
     */
    public static function get_swayam_sub(int $userid): ?string {
        global $DB;

        $issuerids = self::get_issuer_ids();
        if (!$issuerids) {
            logger::error('SUB_NOT_FOUND', [
                'userid' => $userid,
                'reason' => 'No OAuth 2 service whose base URL starts with the Swayam Plus API base URL',
            ]);
            return null;
        }

        [$insql, $params] = $DB->get_in_or_equal($issuerids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $linked = $DB->get_records_select('auth_oauth2_linked_login',
            "userid = :userid AND issuerid {$insql}", $params, 'id', 'id, username');

        if (!$linked) {
            logger::info('SUB_NOT_FOUND', ['userid' => $userid, 'reason' => 'Not a Swayam Plus sign-in user']);
            return null;
        }

        foreach ($linked as $login) {
            $sub = strtolower(trim((string)$login->username));
            if (self::is_uuid($sub)) {
                self::write_sub_to_profile($userid, $sub);
                return $sub;
            }
        }

        $field = self::get_sub_field();
        if (!$field) {
            logger::error('SUB_FIELD_MISSING', [
                'userid' => $userid,
                'fix'    => 'Create the Swayam Plus ID profile field and map "sub" to it on the OAuth 2 service (see README)',
            ]);
            return null;
        }
        if (empty($field->locked)) {
            logger::error('SUB_FIELD_NOT_LOCKED', [
                'userid' => $userid,
                'field'  => $field->shortname,
                'fix'    => 'Edit the profile field and set Locked = Yes (and Visible = Not visible). '
                          . 'An unlocked field can be changed by the learner, so it is not used.',
            ]);
            return null;
        }

        $data = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $field->id]);
        $sub  = strtolower(trim((string)$data));
        if (!self::is_uuid($sub)) {
            logger::info('SUB_NOT_FOUND', [
                'userid' => $userid,
                'reason' => 'Profile field is empty or not a Swayam Plus ID: map "sub" to it on the OAuth 2 service',
            ]);
            return null;
        }
        return $sub;
    }

    /**
     * Reverse lookup: Swayam Plus sub to Moodle user id (0 if no such user yet).
     * Trusts the same sources as get_swayam_sub(). Used by the webhook.
     *
     * @param string $sub
     * @return int
     */
    public static function find_userid_by_sub(string $sub): int {
        global $DB;

        $sub = strtolower(trim($sub));
        $issuerids = self::get_issuer_ids();
        if (!self::is_uuid($sub) || !$issuerids) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($issuerids, SQL_PARAMS_NAMED);

        // 1. Linked login whose username is the sub.
        $sql = "SELECT u.id
                  FROM {auth_oauth2_linked_login} lin
                  JOIN {user} u ON u.id = lin.userid
                 WHERE " . $DB->sql_equal('lin.username', ':sub', false) . "
                   AND lin.issuerid {$insql}
                   AND u.deleted = 0
              ORDER BY u.id";
        $userid = $DB->get_field_sql($sql, ['sub' => $sub] + $inparams, IGNORE_MULTIPLE);
        if ($userid) {
            return (int)$userid;
        }

        // 2. Locked profile field, for users who signed in with Swayam Plus.
        $field = self::get_sub_field();
        if (!$field || empty($field->locked)) {
            return 0;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($issuerids, SQL_PARAMS_NAMED);
        $sql = "SELECT u.id
                  FROM {user_info_data} uid
                  JOIN {user} u ON u.id = uid.userid
                 WHERE uid.fieldid = :fieldid
                   AND " . $DB->sql_compare_text('uid.data', 40) . " = :sub
                   AND u.deleted = 0
                   AND EXISTS (SELECT 1 FROM {auth_oauth2_linked_login} lin
                                WHERE lin.userid = u.id AND lin.issuerid {$insql})
              ORDER BY u.id";
        $userid = $DB->get_field_sql($sql, ['fieldid' => $field->id, 'sub' => $sub] + $inparams, IGNORE_MULTIPLE);

        return (int)$userid;
    }

    /**
     * Keeps the profile field copy in step with the verified linked-login sub.
     *
     * @param int $userid
     * @param string $sub
     */
    public static function write_sub_to_profile(int $userid, string $sub): void {
        global $DB;

        $field = self::get_sub_field();
        if (!$field) {
            return;
        }

        $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $field->id]);
        if ($existing) {
            if ($existing->data !== $sub) {
                $DB->update_record('user_info_data', (object)['id' => $existing->id, 'data' => $sub]);
            }
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $userid, 'fieldid' => $field->id, 'data' => $sub, 'dataformat' => 0,
            ]);
        }
    }

    // =========================================================================
    // ENROL HELPERS
    // =========================================================================

    /**
     * Enrols a user into a course with the manual enrolment method.
     *
     * @param int $userid
     * @param int $courseid
     * @param bool $reactivate true = also lift a suspension (used only when the
     *                         suspension was applied by a Swayam Plus revocation)
     */
    public static function enrol_user_into_course(int $userid, int $courseid, bool $reactivate = false): void {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            logger::error('ENROL_NO_PLUGIN', ['userid' => $userid, 'courseid' => $courseid]);
            return;
        }

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance) {
            $course = get_course($courseid);
            $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        }

        if (!$instance) {
            logger::error('ENROL_NO_INSTANCE', ['userid' => $userid, 'courseid' => $courseid]);
            return;
        }

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        // Status null = keep the current status of an existing enrolment.
        $plugin->enrol_user($instance, $userid, $roleid, 0, 0, $reactivate ? ENROL_USER_ACTIVE : null);

        logger::success('ENROL_USER', ['userid' => $userid, 'courseid' => $courseid]);
    }

    /**
     * Ends a learner's access after a Swayam Plus revocation (webhook).
     * Only touches manual enrolments, the method this plugin enrols with.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $action 'suspend' (keeps progress and grades) or 'unenrol'
     */
    public static function remove_user_from_course(int $userid, int $courseid, string $action = 'suspend'): void {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            logger::error('ENROL_NO_PLUGIN', ['userid' => $userid, 'courseid' => $courseid]);
            return;
        }

        $done = 0;
        foreach ($DB->get_records('enrol', ['courseid' => $courseid, 'enrol' => 'manual']) as $instance) {
            if (!$DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid])) {
                continue;
            }
            if ($action === 'unenrol') {
                $plugin->unenrol_user($instance, $userid);
            } else {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }
            $done++;
        }

        logger::success('ACCESS_REMOVED', [
            'userid' => $userid, 'courseid' => $courseid, 'action' => $action, 'enrolments' => $done,
        ]);
    }

    // =========================================================================
    // PROGRESS CALCULATION
    // =========================================================================

    /**
     * Returns the course progress percentage (0-100) for a user, or -1 on error.
     *
     * Uses the same calculation as Moodle's course progress bar
     * (\core_completion\progress), so the number sent to Swayam Plus matches
     * what the learner sees. A course Moodle marks complete is always 100.
     *
     * @param int $userid
     * @param int $courseid
     * @return int
     */
    public static function calculate_progress(int $userid, int $courseid): int {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        try {
            $course = get_course($courseid);
        } catch (\Throwable $e) {
            logger::error('PROGRESS_COURSE_NOT_FOUND', ['courseid' => $courseid, 'error' => $e->getMessage()]);
            return -1;
        }

        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            logger::error('PROGRESS_COMPLETION_DISABLED', [
                'courseid' => $courseid,
                'fix'      => 'Enable completion tracking: Course > Settings > Completion tracking > Yes',
            ]);
            return -1;
        }

        if ($completion->is_course_complete($userid)) {
            return 100;
        }

        $modules = $completion->get_activities();
        if (empty($modules)) {
            logger::error('PROGRESS_NO_ACTIVITIES', [
                'courseid' => $courseid,
                'fix'      => 'Set completion conditions on each activity: Activity > Settings > Completion conditions',
            ]);
            return 0;
        }

        $source = 'core_progress';
        $raw    = \core_completion\progress::get_course_progress_percentage($course, $userid);

        if ($raw === null) {
            // Core returns null when the user is not a tracked user (for
            // example no student role). Fall back to the plugin's own count.
            $source = 'plugin_count';
            $done   = 0;
            foreach ($modules as $cm) {
                $data = $completion->get_data($cm, true, $userid);
                if (in_array((int)$data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                    $done++;
                }
            }
            $raw = 100 * $done / count($modules);
        }

        // Floor, not round: never report 100% until everything is done.
        $percent = (int) floor((float)$raw + 1e-9);
        $percent = max(0, min(100, $percent));

        logger::info('PROGRESS_CALCULATED', [
            'userid'   => $userid,
            'courseid' => $courseid,
            'percent'  => $percent . '%',
            'source'   => $source,
        ]);

        return $percent;
    }

    // =========================================================================
    // PROGRESS AND COMPLETION SENDER  (ad-hoc task, scheduled task, CLI)
    // =========================================================================

    /**
     * Sends the learner's current progress for one mapped course: POST
     * /completion at 100%, otherwise POST /progress.
     *
     * @param int $userid
     * @param int $courseid
     * @param string $trigger what started this, for the log
     * @return string progress | completed | failed | skipped
     */
    public static function push_user_progress(int $userid, int $courseid, string $trigger): string {
        global $DB;

        $map = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$map || !empty($map->completionsent) || in_array($map->status, ['TERMINATED', 'COMPLETED'], true)) {
            return 'skipped';
        }

        $percent = self::calculate_progress($userid, $courseid);
        if ($percent < 0) {
            return 'skipped';
        }

        if ($percent >= 100) {
            return self::send_completion($map, $userid, $courseid, $trigger) ? 'completed' : 'failed';
        }

        // Swayam Plus accepts progress only for IN_PROGRESS enrollments: the
        // learner has opened the course from Swayam Plus at least once.
        if ($map->status !== 'IN_PROGRESS') {
            logger::info('PROGRESS_SKIP_NOT_IN_PROGRESS', [
                'userid'       => $userid,
                'courseid'     => $courseid,
                'enrollmentId' => $map->enrollmentid,
                'status'       => $map->status,
                'fix'          => 'The learner must open the course from the Swayam Plus dashboard once',
            ]);
            return 'skipped';
        }

        $token = self::token();
        if (!$token) {
            return 'failed';
        }

        [$http, $error] = self::api_post("/api/v1/partner/enrollments/{$map->enrollmentid}/progress",
            $token, ['percent' => $percent]);

        $DB->update_record('local_swayam_enrolmap', (object)['id' => $map->id, 'timemodified' => time()]);

        if ($http === 204) {
            logger::success('PROGRESS_PUSHED', [
                'userid'       => $userid,
                'courseid'     => $courseid,
                'enrollmentId' => $map->enrollmentid,
                'percent'      => $percent . '%',
                'trigger'      => $trigger,
            ]);
            return 'progress';
        }

        logger::error('PROGRESS_PUSH_FAILED', [
            'userid'       => $userid,
            'enrollmentId' => $map->enrollmentid,
            'percent'      => $percent . '%',
            'http'         => $http,
            'error'        => $error,
        ]);
        return 'failed';
    }

    /**
     * POSTs /completion to Swayam Plus once per enrollment.
     *
     * Sets local_swayam_enrolmap.completionsent = 1 on HTTP 204 so it is
     * never sent twice. On failure completionsent stays 0 and the scheduled
     * task retries it. Does not write Moodle completion or grade data.
     *
     * @param \stdClass $map local_swayam_enrolmap row
     * @param int $userid
     * @param int $courseid
     * @param string $trigger what started this, for the log
     * @param bool $force send even if already sent
     * @return bool true if completion is (now or already) recorded on Swayam Plus
     */
    public static function send_completion(\stdClass $map, int $userid, int $courseid,
                                           string $trigger, bool $force = false): bool {
        global $DB;

        // Re-read the row so two triggers firing close together see the latest flag.
        $fresh = $DB->get_record('local_swayam_enrolmap', ['id' => $map->id]);
        if ($fresh) {
            $map = $fresh;
        }

        if (!$force && !empty($map->completionsent)) {
            return true;
        }

        $token = self::token();
        if (!$token) {
            logger::error('COMPLETION_NO_TOKEN', ['userid' => $userid, 'trigger' => $trigger]);
            return false;
        }

        // The final course grade goes into the completion note (declared in
        // the privacy provider). It is not written to the log.
        $sql   = "SELECT gg.finalgrade FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  WHERE gi.courseid = :courseid AND gi.itemtype = 'course' AND gg.userid = :userid";
        $grade = $DB->get_field_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
        $grade = ($grade !== false && $grade !== null) ? round((float)$grade, 1) : null;

        $note = $grade !== null
            ? "Completed on Moodle LMS, final grade {$grade}%"
            : 'Completed on Moodle LMS';

        [$http, $error] = self::api_post("/api/v1/partner/enrollments/{$map->enrollmentid}/completion",
            $token, ['note' => $note]);

        $now    = time();
        $update = (object)[
            'id'                 => $map->id,
            'timemodified'       => $now,
            'completionattempts' => (int)($map->completionattempts ?? 0) + 1,
        ];

        if ($http === 204) {
            $update->completionsent     = 1;
            $update->timecompletionsent = $now;
            logger::success('COMPLETION_POSTED', [
                'userid'       => $userid,
                'courseid'     => $courseid,
                'enrollmentId' => $map->enrollmentid,
                'trigger'      => $trigger,
            ]);
        } else {
            logger::error('COMPLETION_FAILED', [
                'userid'       => $userid,
                'enrollmentId' => $map->enrollmentid,
                'http'         => $http,
                'error'        => $error,
                'trigger'      => $trigger,
                'next'         => 'Retried by the scheduled task',
            ]);
        }

        $DB->update_record('local_swayam_enrolmap', $update);
        return $http === 204;
    }

    // =========================================================================
    // ENROLMENT SYNC  (ad-hoc task, launch.php, CLI)
    // =========================================================================

    /**
     * Full sync for one user: enrollments from the Swayam Plus API, then
     * entitlements stored by the webhook before the account existed.
     *
     * @param int $userid
     */
    public static function sync_user(int $userid): void {
        $sub = self::get_swayam_sub($userid);
        if (!$sub) {
            return;
        }
        self::sync_enrollments_for_user($userid, $sub);
        webhook::apply_entitlements_for_user($userid, $sub);
    }

    /**
     * Fetches this user's enrollments from Swayam Plus and upserts
     * local_swayam_enrolmap rows for each matched course. Safe to call
     * repeatedly for a user who is already enrolled and mapped.
     *
     * @param int $userid
     * @param string|null $sub Swayam Plus ID, looked up if null
     */
    public static function sync_enrollments_for_user(int $userid, ?string $sub = null): void {
        $sub = $sub ?? self::get_swayam_sub($userid);
        if (!$sub) {
            return;
        }

        $token = self::token();
        if (!$token) {
            logger::error('LOGIN_NO_TOKEN', ['userid' => $userid]);
            return;
        }

        $data = self::api_get('/api/v1/partner/students/' . rawurlencode($sub) . '/enrollments', $token, 'learner enrollments');

        if (!is_array($data)) {
            logger::error('LOGIN_ENROLLMENT_FETCH_FAILED', ['userid' => $userid]);
            return;
        }

        $enrollments = $data['enrollments'] ?? [];

        if (empty($enrollments)) {
            logger::info('LOGIN_NO_ENROLLMENTS', [
                'userid' => $userid,
                'fix'    => 'Ask the Swayam Plus team to set partnerCourseId on the course',
            ]);
            return;
        }

        foreach ($enrollments as $enrollment) {
            // One enrollment that cannot be saved must not block the others.
            try {
                self::sync_one_enrollment($userid, $sub, (array)$enrollment);
            } catch (\Throwable $e) {
                logger::error('LOGIN_ENROLLMENT_FAILED', [
                    'userid'       => $userid,
                    'enrollmentId' => (string)($enrollment['enrollmentId'] ?? ''),
                    'course'       => (string)($enrollment['partnerCourseId'] ?? ''),
                    'error'        => logger::describe($e),
                    'where'        => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        }
    }

    /**
     * Enrols the user for one enrollment returned by the Enrollments API and
     * saves the mapping row.
     *
     * @param int $userid
     * @param string $sub
     * @param array $enrollment
     */
    private static function sync_one_enrollment(int $userid, string $sub, array $enrollment): void {
        global $DB;

        $status          = (string)($enrollment['status'] ?? '');
        $partnercourseid = trim((string)($enrollment['partnerCourseId'] ?? ''));
        $enrollmentid    = strtolower(trim((string)($enrollment['enrollmentId'] ?? '')));

        if (!in_array($status, ['ACCESS_PROVISIONED', 'IN_PROGRESS'], true)) {
            logger::info('LOGIN_SKIP_ENROLLMENT', ['userid' => $userid, 'enrollmentId' => $enrollmentid, 'status' => $status]);
            return;
        }

        if ($partnercourseid === '' || $enrollmentid === '') {
            logger::error('LOGIN_MISSING_IDS', [
                'userid'          => $userid,
                'enrollmentId'    => $enrollmentid,
                'partnerCourseId' => $partnercourseid,
            ]);
            return;
        }

        $course = $DB->get_record('course', ['idnumber' => $partnercourseid], 'id, fullname', IGNORE_MULTIPLE);
        if (!$course) {
            logger::error('LOGIN_COURSE_NOT_FOUND', [
                'partnerCourseId' => $partnercourseid,
                'fix'             => "Set Course ID number = '{$partnercourseid}' in the Moodle course settings",
            ]);
            return;
        }

        $courseid = (int)$course->id;
        $existing = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);

        // Access was ended by a webhook revocation and Swayam Plus now
        // reports it active again: lift the suspension.
        self::enrol_user_into_course($userid, $courseid, $existing && $existing->status === 'TERMINATED');

        if (!self::save_map($userid, $courseid, $enrollmentid, $sub, $status, $existing ?: null)) {
            return;
        }

        logger::success('LOGIN_ENROLLED', [
            'userid'       => $userid,
            'courseid'     => $courseid,
            'enrollmentId' => $enrollmentid,
            'status'       => $status,
        ]);
    }

    /**
     * Inserts or updates the mapping row for one enrollment.
     *
     * local_swayam_enrolmap has two unique indexes: (userid, courseid) and
     * (enrollmentid). The row is looked up both ways so neither is violated
     * when the same Swayam Plus enrollment is already mapped to another
     * Moodle user or course (duplicate account, or the Course ID number was
     * moved to a different course).
     *
     * @param int $userid
     * @param int $courseid
     * @param string $enrollmentid
     * @param string $sub
     * @param string $status
     * @param \stdClass|null $bypair existing row for this user and course
     * @return bool false when the mapping could not be saved (logged)
     */
    private static function save_map(int $userid, int $courseid, string $enrollmentid, string $sub,
            string $status, ?\stdClass $bypair): bool {
        global $DB;

        $now     = time();
        $byenrol = $DB->get_record('local_swayam_enrolmap', ['enrollmentid' => $enrollmentid]);

        if ($byenrol && $bypair && (int)$byenrol->id !== (int)$bypair->id) {
            // Two different rows claim this user+course and this enrollment.
            // Nothing is overwritten or deleted automatically.
            logger::error('MAP_CONFLICT', [
                'userid'              => $userid,
                'courseid'            => $courseid,
                'enrollmentId'        => $enrollmentid,
                'row_for_user_course' => $bypair->id . ' (enrollmentId ' . $bypair->enrollmentid . ')',
                'row_for_enrollment'  => $byenrol->id . ' (userid ' . $byenrol->userid . ', courseid ' . $byenrol->courseid . ')',
                'fix'                 => 'Two mapping rows overlap. Remove the stale row in local_swayam_enrolmap.',
            ]);
            return false;
        }

        if ($byenrol && !$bypair) {
            // This enrollment is mapped to another Moodle user or course.
            // Swayam Plus says it belongs to this learner + course: move it.
            logger::info('MAP_MOVED', [
                'userid'       => $userid,
                'enrollmentId' => $enrollmentid,
                'from'         => 'userid ' . $byenrol->userid . ', courseid ' . $byenrol->courseid,
                'to'           => 'userid ' . $userid . ', courseid ' . $courseid,
            ]);
            $DB->update_record('local_swayam_enrolmap', (object)[
                'id'           => $byenrol->id,
                'userid'       => $userid,
                'courseid'     => $courseid,
                'swayamsub'    => $sub,
                'status'       => $status,
                'timemodified' => $now,
            ]);
            return true;
        }

        if ($bypair) {
            $DB->update_record('local_swayam_enrolmap', (object)[
                'id'           => $bypair->id,
                'enrollmentid' => $enrollmentid,
                'swayamsub'    => $sub,
                'status'       => $status,
                'timemodified' => $now,
            ]);
            return true;
        }

        $DB->insert_record('local_swayam_enrolmap', (object)[
            'userid'       => $userid,
            'courseid'     => $courseid,
            'enrollmentid' => $enrollmentid,
            'swayamsub'    => $sub,
            'status'       => $status,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
        return true;
    }

    // =========================================================================
    // EVENT OBSERVERS  (lightweight: queue an ad-hoc task and return)
    // =========================================================================

    /**
     * user_loggedin: queue the enrolment sync for Swayam Plus sign-in users.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event): void {
        try {
            $userid = (int)$event->objectid;
            if (!self::has_swayam_login($userid)) {
                return;
            }
            $task = new task\sync_user();
            $task->set_custom_data(['userid' => $userid]);
            \core\task\manager::queue_adhoc_task($task, true);
        } catch (\Throwable $e) {
            self::observer_failed('user_loggedin', $e);
        }
    }

    /**
     * course_module_completion_updated: queue a progress update for mapped learners.
     *
     * @param \core\event\course_module_completion_updated $event
     */
    public static function module_completed(\core\event\course_module_completion_updated $event): void {
        try {
            $state = (int)($event->other['completionstate'] ?? 0);
            if (!in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                return;
            }
            self::queue_progress((int)$event->relateduserid, (int)$event->courseid);
        } catch (\Throwable $e) {
            self::observer_failed('module_completed', $e);
        }
    }

    /**
     * course_completed: queue the completion for mapped learners.
     *
     * @param \core\event\course_completed $event
     */
    public static function course_completed(\core\event\course_completed $event): void {
        try {
            self::queue_progress((int)$event->relateduserid, (int)$event->courseid);
        } catch (\Throwable $e) {
            self::observer_failed('course_completed', $e);
        }
    }

    /**
     * Queues task\push_user_progress if the learner is mapped to a Swayam
     * Plus enrollment that still needs updates.
     *
     * @param int $userid
     * @param int $courseid
     */
    private static function queue_progress(int $userid, int $courseid): void {
        global $DB;

        $map = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid],
            'id, status, completionsent');
        if (!$map || !empty($map->completionsent) || in_array($map->status, ['TERMINATED', 'COMPLETED'], true)) {
            return;
        }
        $task = new task\push_user_progress();
        $task->set_custom_data(['userid' => $userid, 'courseid' => $courseid]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Logs an observer failure. Observers never break the user's request.
     *
     * @param string $handler
     * @param \Throwable $e
     */
    private static function observer_failed(string $handler, \Throwable $e): void {
        logger::error('OBSERVER_EXCEPTION', [
            'handler' => $handler,
            'error'   => logger::describe($e),
            'where'   => $e->getFile() . ':' . $e->getLine(),
        ]);
    }
}
