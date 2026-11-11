<?php
namespace local_swayam;
defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for local_swayam.
 *
 * Handles:
 *  - user_loggedin                    → auto-enrolment
 *  - course_module_completion_updated → push progress % to Swayam Plus
 *  - course_completed                 → push completion to Swayam Plus
 *
 * Swayam Plus API base: https://swayamplus.education.gov.in
 */
class observer {

    public static function get_token_for_task(): ?string {
        return self::token();
    }

    // =========================================================================
    // TOKEN  (cached 540s — under the 600s token lifetime)
    // =========================================================================

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

        $base   = rtrim(get_config('local_swayam', 'apibase'), '/');
        $res    = get_config('local_swayam', 'apiresource');
        $cid    = get_config('local_swayam', 'clientid');
        $secret = get_config('local_swayam', 'clientsecret');

        if (empty($base) || empty($res) || empty($cid) || empty($secret)) {
            logger::error('TOKEN_MINT_FAILED', ['reason' => 'Plugin not fully configured — check Site Admin > Swayam Plus Integration']);
            return null;
        }

        // MUST use application/x-www-form-urlencoded — Swayam Plus rejects multipart.
        $postbody = http_build_query([
            'grant_type' => 'client_credentials',
            'scope'      => 'partner.enrollments:read partner.completions:write',
            'resource'   => $res,
        ], '', '&', PHP_QUERY_RFC3986);

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader('Authorization: Basic ' . base64_encode("{$cid}:{$secret}"));
        $curl->setHeader('Content-Type: application/x-www-form-urlencoded');

        $body = $curl->post("{$base}/oidc/token", $postbody);
        $info = $curl->get_info();
        $json = json_decode($body, true);

        if (empty($json['access_token'])) {
            logger::error('TOKEN_MINT_FAILED', [
                'http'     => $info['http_code'] ?? 0,
                'response' => $body,
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

    private static function api_get(string $path, string $token): ?array {
        $base = rtrim(get_config('local_swayam', 'apibase'), '/');

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader("Authorization: Bearer {$token}");
        $curl->setHeader('Accept: application/json');

        $raw  = $curl->get($base . $path);
        $info = $curl->get_info();
        $http = (int)($info['http_code'] ?? 0);

        logger::info('API_GET', [
            'path' => $path,
            'http' => $http,
            'body' => substr($raw, 0, 500),
        ]);

        if ($http !== 200) {
            logger::error('API_GET_FAILED', ['path' => $path, 'http' => $http, 'body' => $raw]);
            return null;
        }

        return json_decode($raw, true);
    }

    private static function api_post(string $path, string $token, array $payload): array {
        $base = rtrim(get_config('local_swayam', 'apibase'), '/');

        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader("Authorization: Bearer {$token}");
        $curl->setHeader('Content-Type: application/json');
        $curl->setHeader('Accept: application/json');

        $json_body = json_encode($payload);
        $response  = $curl->post($base . $path, $json_body);
        $info      = $curl->get_info();
        $http      = (int)($info['http_code'] ?? 0);

        if ($http === 204) {
            logger::success('API_POST_OK', ['path' => $path, 'http' => $http, 'payload' => $json_body]);
        } else {
            logger::error('API_POST_FAILED', [
                'path'     => $path,
                'http'     => $http,
                'payload'  => $json_body,
                'response' => $response,
            ]);
        }

        return [$http, $response];
    }

    // =========================================================================
    // SUB RESOLUTION  (profile field → oauth2_linked_login fallback)
    // =========================================================================

    private static function get_swayam_sub(int $userid): ?string {
        global $DB;

        $shortname = get_config('local_swayam', 'subfield') ?: 'swayamsub';

        // Layer 1: read from custom profile field.
        $sql = "SELECT uid.data
                  FROM {user_info_data} uid
                  JOIN {user_info_field} f ON f.id = uid.fieldid
                 WHERE f.shortname = :shortname
                   AND uid.userid  = :userid";
        $sub = $DB->get_field_sql($sql, ['shortname' => $shortname, 'userid' => $userid]);

        if (!empty(trim((string)$sub))) {
            logger::info('SUB_RESOLVED', ['userid' => $userid, 'sub' => $sub, 'source' => 'profile_field']);
            return trim($sub);
        }

        // Layer 2: fall back to mdl_auth_oauth2_linked_login.
        $apibase = rtrim(get_config('local_swayam', 'apibase'), '/');
        $issuer  = $DB->get_record_sql(
            "SELECT id FROM {oauth2_issuer} WHERE " . $DB->sql_like('baseurl', ':baseurl', false),
            ['baseurl' => $DB->sql_like_escape($apibase) . '%']
        );

        if (!$issuer) {
            logger::info('SUB_NOT_FOUND', ['userid' => $userid, 'reason' => 'No OAuth2 issuer matching Swayam Plus base URL']);
            return null;
        }

        $linked = $DB->get_record('auth_oauth2_linked_login', ['userid' => $userid, 'issuerid' => $issuer->id]);

        if (!$linked || empty($linked->username)) {
            logger::info('SUB_NOT_FOUND', ['userid' => $userid, 'reason' => 'No linked login — not a Swayam Plus SSO user']);
            return null;
        }

        $sub = trim($linked->username);
        self::write_sub_to_profile($userid, $sub, $shortname);
        logger::info('SUB_RESOLVED', ['userid' => $userid, 'sub' => $sub, 'source' => 'linked_login_fallback']);

        return $sub;
    }

    public static function write_sub_to_profile(int $userid, string $sub, string $shortname = 'swayamsub'): void {
        global $DB;

        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);
        if (!$fieldid) {
            return;
        }

        $existing = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid]);
        if ($existing) {
            if ($existing->data !== $sub) {
                $DB->update_record('user_info_data', (object)['id' => $existing->id, 'data' => $sub]);
            }
        } else {
            $DB->insert_record('user_info_data', (object)[
                'userid' => $userid, 'fieldid' => $fieldid, 'data' => $sub, 'dataformat' => 0,
            ]);
        }
    }

    // =========================================================================
    // ENROL HELPER
    // =========================================================================

    private static function enrol_user_into_course(int $userid, int $courseid): void {
        global $DB;

        $plugin = enrol_get_plugin('manual');
        if (!$plugin) {
            logger::error('ENROL_NO_PLUGIN', ['userid' => $userid, 'courseid' => $courseid]);
            return;
        }

        $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
        if (!$instance) {
            $course = get_course($courseid);
            $plugin->add_default_instance($course);
            $instance = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
        }

        if (!$instance) {
            logger::error('ENROL_NO_INSTANCE', ['userid' => $userid, 'courseid' => $courseid]);
            return;
        }

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'student']);
        $plugin->enrol_user($instance, $userid, $roleid);

        logger::success('ENROL_USER', ['userid' => $userid, 'courseid' => $courseid]);
    }

    // =========================================================================
    // PROGRESS CALCULATION
    // =========================================================================

    public static function calculate_progress(int $userid, int $courseid): int {
        $course = get_course($courseid);
        if (!$course) {
            return -1;
        }

        $completion = new \completion_info($course);
        if (!$completion->is_enabled()) {
            logger::error('PROGRESS_COMPLETION_DISABLED', [
                'courseid' => $courseid,
                'fix'      => 'Enable completion tracking: Course > Edit Settings > Completion tracking > YES',
            ]);
            return -1;
        }

        $modules = $completion->get_activities();
        if (empty($modules)) {
            logger::error('PROGRESS_NO_ACTIVITIES', [
                'courseid' => $courseid,
                'fix'      => 'Set completion conditions on each activity: Activity > Edit > Activity completion',
            ]);
            return 0;
        }

        $total = count($modules);
        $done  = 0;

        foreach ($modules as $cm) {
            $data = $completion->get_data($cm, false, $userid);
            if (in_array((int)$data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
                $done++;
            }
        }

        $percent = (int) round(100 * $done / $total);

        logger::info('PROGRESS_CALCULATED', [
            'userid'   => $userid,
            'courseid' => $courseid,
            'done'     => $done,
            'total'    => $total,
            'percent'  => $percent . '%',
        ]);

        return $percent;
    }

    // =========================================================================
    // EVENT: user_loggedin  →  auto-enrolment
    // =========================================================================

    public static function user_loggedin(\core\event\user_loggedin $event): void {
        $userid = (int)$event->objectid;
        logger::info('LOGIN_EVENT', ['userid' => $userid]);
        self::sync_enrollments_for_user($userid);
    }

    /**
     * Fetches this user's enrollments from Swayam Plus and upserts
     * local_swayam_enrolmap rows for each matched course. Shared by
     * the user_loggedin observer and the backfill CLI script — safe
     * to call repeatedly for a user who is already enrolled/mapped.
     */
    public static function sync_enrollments_for_user(int $userid): void {
        global $DB;

        $sub = self::get_swayam_sub($userid);
        if (!$sub) {
            logger::info('LOGIN_SKIP', ['userid' => $userid, 'reason' => 'No Swayam Plus sub — not a Swayam Plus user']);
            return;
        }

        $token = self::token();
        if (!$token) {
            logger::error('LOGIN_NO_TOKEN', ['userid' => $userid]);
            return;
        }

        $path = '/api/v1/partner/students/' . urlencode($sub) . '/enrollments';
        $data = self::api_get($path, $token);

        if (!is_array($data)) {
            logger::error('LOGIN_ENROLLMENT_FETCH_FAILED', ['userid' => $userid, 'sub' => $sub]);
            return;
        }

        $enrollments = $data['enrollments'] ?? [];

        if (empty($enrollments)) {
            logger::info('LOGIN_NO_ENROLLMENTS', [
                'userid' => $userid,
                'sub'    => $sub,
                'fix'    => 'Ask Swayam Plus team to set partnerCourseId on the course',
            ]);
            return;
        }

        logger::info('LOGIN_ENROLLMENTS_FOUND', ['userid' => $userid, 'count' => count($enrollments)]);

        foreach ($enrollments as $enrollment) {

            $status            = $enrollment['status']               ?? '';
            $partner_course_id = trim($enrollment['partnerCourseId'] ?? '');
            $enrollment_id     = trim($enrollment['enrollmentId']    ?? '');

            if (!in_array($status, ['ACCESS_PROVISIONED', 'IN_PROGRESS'], true)) {
                logger::info('LOGIN_SKIP_ENROLLMENT', ['enrollmentId' => $enrollment_id, 'status' => $status]);
                continue;
            }

            if ($partner_course_id === '' || $enrollment_id === '') {
                logger::error('LOGIN_MISSING_IDS', ['enrollment' => json_encode($enrollment)]);
                continue;
            }

            $course = $DB->get_record('course', ['idnumber' => $partner_course_id]);
            if (!$course) {
                logger::error('LOGIN_COURSE_NOT_FOUND', [
                    'partnerCourseId' => $partner_course_id,
                    'fix'             => "Set Course ID number = '{$partner_course_id}' in Moodle course Edit Settings",
                ]);
                continue;
            }

            $courseid = (int)$course->id;
            self::enrol_user_into_course($userid, $courseid);

            $existing = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);
            $now      = time();

            if ($existing) {
                $DB->update_record('local_swayam_enrolmap', (object)[
                    'id'           => $existing->id,
                    'enrollmentid' => $enrollment_id,
                    'swayamsub'    => $sub,
                    'status'       => $status,
                    'timemodified' => $now,
                ]);
            } else {
                $DB->insert_record('local_swayam_enrolmap', (object)[
                    'userid'       => $userid,
                    'courseid'     => $courseid,
                    'enrollmentid' => $enrollment_id,
                    'swayamsub'    => $sub,
                    'status'       => $status,
                    'timecreated'  => $now,
                    'timemodified' => $now,
                ]);
            }

            logger::success('LOGIN_ENROLLED', [
                'userid'       => $userid,
                'courseid'     => $courseid,
                'enrollmentId' => $enrollment_id,
                'status'       => $status,
                'course'       => $course->fullname,
            ]);
        }
    }

    // =========================================================================
    // EVENT: course_module_completion_updated  →  POST /progress
    // =========================================================================

    public static function module_completed(\core\event\course_module_completion_updated $event): void {
        global $DB;

        $completionstate = (int)($event->other['completionstate'] ?? 0);
        $userid          = (int)$event->relateduserid;
        $courseid        = (int)$event->courseid;

        logger::info('MODULE_COMPLETION_EVENT', [
            'userid'          => $userid,
            'courseid'        => $courseid,
            'completionstate' => $completionstate,
        ]);

        if (!in_array($completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
            logger::info('MODULE_SKIP', ['reason' => 'State not COMPLETE — state=' . $completionstate]);
            return;
        }

        $map = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$map) {
            logger::error('MODULE_NO_ENROLMAP', [
                'userid'   => $userid,
                'courseid' => $courseid,
                'fix'      => 'Student must log in via Swayam Plus SSO first to create the enrolmap row',
            ]);
            return;
        }

        // Only push progress when enrollment is IN_PROGRESS.
        // ACCESS_PROVISIONED → student has not clicked "Continue on IITM" yet.
        // Swayam Plus API returns 409 INVALID_HANDOFF_TRANSITION for PROVISIONING status.
        if ($map->status !== 'IN_PROGRESS') {
            logger::info('MODULE_SKIP_NOT_IN_PROGRESS', [
                'userid'         => $userid,
                'courseid'       => $courseid,
                'enrollmentId'   => $map->enrollmentid,
                'current_status' => $map->status,
                'fix'            => 'Student must click "Continue on IITM" on Swayam Plus dashboard to move enrollment to IN_PROGRESS, then log in again',
            ]);
            return;
        }

        logger::info('MODULE_ENROLMAP_FOUND', [
            'userid'       => $userid,
            'courseid'     => $courseid,
            'enrollmentId' => $map->enrollmentid,
            'status'       => $map->status,
        ]);

        $percent = self::calculate_progress($userid, $courseid);
        if ($percent < 0) {
            return;
        }

        $token = self::token();
        if (!$token) {
            logger::error('MODULE_NO_TOKEN', ['userid' => $userid]);
            return;
        }

        // At 100% — force Moodle course completion so course_completed event fires → /completion POST.
        if ($percent >= 100) {
            $course      = get_course($courseid);
            $completion  = new \completion_info($course);
            $ccompletion = $DB->get_record('course_completions', ['userid' => $userid, 'course' => $courseid]);

            if (!$ccompletion || is_null($ccompletion->timecompleted)) {
                $completion->mark_course_completions([$userid]);
                logger::info('MODULE_FORCED_COURSE_COMPLETE', ['userid' => $userid, 'courseid' => $courseid]);
            }
            return;
        }

        // POST /progress (0–99 only).
        $path = "/api/v1/partner/enrollments/{$map->enrollmentid}/progress";
        [$http, $response] = self::api_post($path, $token, ['percent' => $percent]);

        if ($http === 204) {
            logger::success('PROGRESS_PUSHED', [
                'userid'       => $userid,
                'courseid'     => $courseid,
                'enrollmentId' => $map->enrollmentid,
                'percent'      => $percent . '%',
            ]);
        } else {
            logger::error('PROGRESS_PUSH_FAILED', [
                'userid'       => $userid,
                'enrollmentId' => $map->enrollmentid,
                'percent'      => $percent . '%',
                'http'         => $http,
                'response'     => $response,
            ]);
        }

        $DB->update_record('local_swayam_enrolmap', (object)['id' => $map->id, 'timemodified' => time()]);
    }

    // =========================================================================
    // EVENT: course_completed  →  POST /completion
    // =========================================================================

    public static function course_completed(\core\event\course_completed $event): void {
        global $DB;

        $userid   = (int)$event->relateduserid;
        $courseid = (int)$event->courseid;

        logger::info('COURSE_COMPLETED_EVENT', ['userid' => $userid, 'courseid' => $courseid]);

        $map = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$map) {
            logger::error('COMPLETION_NO_ENROLMAP', ['userid' => $userid, 'courseid' => $courseid]);
            return;
        }

        $token = self::token();
        if (!$token) {
            logger::error('COMPLETION_NO_TOKEN', ['userid' => $userid]);
            return;
        }

        // Include final grade in note if available.
        $sql   = "SELECT gg.finalgrade FROM {grade_grades} gg
                  JOIN {grade_items} gi ON gi.id = gg.itemid
                  WHERE gi.courseid = :courseid AND gi.itemtype = 'course' AND gg.userid = :userid";
        $grade = $DB->get_field_sql($sql, ['courseid' => $courseid, 'userid' => $userid]);
        $grade = ($grade !== false && $grade !== null) ? round((float)$grade, 1) : null;

        $note = $grade !== null
            ? "Completed on Moodle LMS, final grade {$grade}%"
            : 'Completed on Moodle LMS';

        $path = "/api/v1/partner/enrollments/{$map->enrollmentid}/completion";
        [$http, $response] = self::api_post($path, $token, ['note' => $note]);

        if ($http === 204) {
            logger::success('COMPLETION_POSTED', [
                'userid'       => $userid,
                'enrollmentId' => $map->enrollmentid,
                'note'         => $note,
                'result'       => 'Certificate triggered on Swayam Plus',
            ]);
        } else {
            logger::error('COMPLETION_FAILED', [
                'userid'       => $userid,
                'enrollmentId' => $map->enrollmentid,
                'http'         => $http,
                'response'     => $response,
            ]);
        }

        $DB->update_record('local_swayam_enrolmap', (object)['id' => $map->id, 'timemodified' => time()]);
    }
}
