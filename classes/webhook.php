<?php
namespace local_swayam;
defined('MOODLE_INTERNAL') || die();

/**
 * Swayam Plus webhook receiver logic (endpoint: local/swayam/webhook.php).
 *
 * Handles:
 *  - ping                  → acknowledged, no action
 *  - enrollment.activated  → store entitlement; enrol now if the learner has an account
 *  - enrollment.revoked    → suspend / unenrol the learner, stop progress pushes
 *  - enrollment.completed  → mark the mapping COMPLETED (no more /completion calls)
 *
 * Guarantees:
 *  - Signature verified before anything else (HMAC-SHA256, 5 minute tolerance).
 *  - Each delivery ID is applied once (local_swayam_webhook).
 *  - Events are applied in order per enrollment (local_swayam_entitle.sequence).
 *  - Does NOT write Moodle completion or grade data, and never deletes a user.
 */
class webhook {

    /** Reject signatures whose timestamp is further than this from now (seconds). */
    const TOLERANCE = 300;

    /** Largest request body accepted (bytes). Real events are well under 1 KB. */
    const MAX_BODY = 65536;

    /** Delivery log rows are kept this long (Swayam Plus keeps history 30 days). */
    const DELIVERY_RETENTION = 30 * 86400;

    const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    const EVENT_TYPES = ['enrollment.activated', 'enrollment.revoked', 'enrollment.completed'];

    // =========================================================================
    // SIGNATURE
    // =========================================================================

    /**
     * Verifies an X-Swayam-Signature header ("t=<unix>,v1=<hex>[,v1=<hex>]").
     *
     * The signed payload is "<t>.<raw body>". During a secret rotation the
     * header carries one v1 per secret, so a single match is enough.
     * Pure function — no Moodle dependencies.
     */
    public static function verify_signature(string $header, string $raw, string $secret, ?int $now = null): bool {
        if ($secret === '' || $header === '') {
            return false;
        }
        if (!preg_match('/(?:^|,)\s*t=(\d{1,12})(?:,|$)/', $header, $m)) {
            return false;
        }
        $t   = $m[1];
        $now = $now ?? time();
        if (abs($now - (int)$t) > self::TOLERANCE) {
            return false;
        }
        if (!preg_match_all('/(?:^|,)\s*v1=([0-9a-fA-F]{64})(?=,|$)/', $header, $mm)) {
            return false;
        }

        $expected = hash_hmac('sha256', $t . '.' . $raw, $secret);
        $ok = false;
        foreach ($mm[1] as $candidate) {
            // No early exit: compare every candidate in constant time.
            if (hash_equals($expected, strtolower($candidate))) {
                $ok = true;
            }
        }
        return $ok;
    }

    // =========================================================================
    // DISPATCH
    // =========================================================================

    /**
     * Applies one verified delivery. Returns a short outcome string for the
     * log / HTTP response. Throws on a real failure so the endpoint answers
     * 5xx and Swayam Plus retries the delivery.
     */
    public static function handle(string $deliveryid, array $event): string {
        global $DB;

        $type     = (string)($event['type'] ?? '');
        $sequence = (int)($event['sequence'] ?? 0);
        $sandbox  = !empty($event['sandbox']) ? 1 : 0;
        $data     = is_array($event['data'] ?? null) ? $event['data'] : [];

        if ($DB->record_exists('local_swayam_webhook', ['deliveryid' => $deliveryid])) {
            logger::info('WEBHOOK_DUPLICATE', ['delivery' => $deliveryid, 'type' => $type]);
            return 'duplicate';
        }

        if ($type === 'ping') {
            self::record_delivery($deliveryid, $type, '', $sequence, $sandbox, 'ping');
            logger::success('WEBHOOK_PING', ['delivery' => $deliveryid]);
            return 'ping';
        }

        $enrollmentid = strtolower(trim((string)($data['enrollmentId'] ?? '')));

        if (!in_array($type, self::EVENT_TYPES, true) || !preg_match(self::UUID, $enrollmentid)) {
            // Unknown event type or unusable payload: acknowledge so it is not
            // retried for 22 hours, but take no action.
            self::record_delivery($deliveryid, $type, '', $sequence, $sandbox, 'ignored');
            logger::info('WEBHOOK_IGNORED', [
                'delivery' => $deliveryid, 'type' => $type, 'reason' => 'Unknown event type or missing enrollmentId',
            ]);
            return 'ignored';
        }

        // Swayam Plus sends up to four deliveries at once: serialise per enrollment.
        $factory = \core\lock\lock_config::get_lock_factory('local_swayam_webhook');
        $lock    = $factory->get_lock($enrollmentid, 8);
        if (!$lock) {
            throw new \RuntimeException("Could not lock enrollment {$enrollmentid}");
        }

        try {
            if ($DB->record_exists('local_swayam_webhook', ['deliveryid' => $deliveryid])) {
                return 'duplicate';
            }

            // One transaction: if the action fails nothing is saved (neither the
            // sequence nor the delivery ID), so the retry is applied cleanly.
            $tx = $DB->start_delegated_transaction();
            try {
                $outcome = self::apply($type, $enrollmentid, $sequence, $sandbox, $data);
                self::record_delivery($deliveryid, $type, $enrollmentid, $sequence, $sandbox, $outcome);
                $tx->allow_commit();
            } catch (\Throwable $e) {
                $tx->rollback($e);   // Rethrows.
            }
        } finally {
            $lock->release();
        }

        logger::success('WEBHOOK_EVENT', [
            'delivery'     => $deliveryid,
            'type'         => $type,
            'enrollmentId' => $enrollmentid,
            'sequence'     => $sequence,
            'sandbox'      => $sandbox,
            'reason'       => (string)($data['reason'] ?? ''),
            'outcome'      => $outcome,
        ]);

        return $outcome;
    }

    /**
     * Sequence check + entitlement upsert + the action for this event type.
     */
    private static function apply(string $type, string $enrollmentid, int $sequence, int $sandbox, array $data): string {
        global $DB;

        $ent = $DB->get_record('local_swayam_entitle', ['enrollmentid' => $enrollmentid]);

        // Out-of-order or replayed event: an event is applied only if its
        // sequence is higher than the last one applied for this enrollment.
        if ($ent && $sequence <= (int)$ent->sequence) {
            return 'stale';
        }

        $sub = strtolower(trim((string)($data['sub'] ?? '')));
        if (!preg_match(self::UUID, $sub)) {
            $sub = '';
        }
        $pcid = \core_text::substr(trim((string)($data['partnerCourseId'] ?? '')), 0, 100);

        $defaultstatus = [
            'enrollment.activated' => 'ACCESS_PROVISIONED',
            'enrollment.revoked'   => 'TERMINATED',
            'enrollment.completed' => 'COMPLETED',
        ][$type];
        $status = \core_text::substr(clean_param((string)($data['status'] ?? ''), PARAM_ALPHANUMEXT), 0, 30)
            ?: $defaultstatus;
        $reason = $type === 'enrollment.revoked'
            ? \core_text::substr(clean_param((string)($data['reason'] ?? 'unknown'), PARAM_ALPHANUMEXT), 0, 30)
            : '';

        $now = time();
        if ($ent) {
            $ent->swayamsub       = $sub  !== '' ? $sub  : $ent->swayamsub;
            $ent->partnercourseid = $pcid !== '' ? $pcid : $ent->partnercourseid;
            $ent->status          = $status;
            $ent->reason          = $reason;
            $ent->sequence        = $sequence;
            $ent->sandbox         = $sandbox;
            $ent->timemodified    = $now;
            $DB->update_record('local_swayam_entitle', $ent);
        } else {
            $ent = (object)[
                'enrollmentid'    => $enrollmentid,
                'swayamsub'       => $sub,
                'partnercourseid' => $pcid,
                'status'          => $status,
                'reason'          => $reason,
                'sequence'        => $sequence,
                'sandbox'         => $sandbox,
                'timecreated'     => $now,
                'timemodified'    => $now,
            ];
            $ent->id = $DB->insert_record('local_swayam_entitle', $ent);
        }

        switch ($type) {
            case 'enrollment.activated':
                return self::activate($ent);
            case 'enrollment.revoked':
                return self::revoke($ent);
            default:
                return self::complete($ent, (string)($data['by'] ?? ''));
        }
    }

    private static function record_delivery(string $deliveryid, string $type, string $enrollmentid,
                                            int $sequence, int $sandbox, string $outcome): void {
        global $DB;
        try {
            $DB->insert_record('local_swayam_webhook', (object)[
                'deliveryid'   => $deliveryid,
                'eventtype'    => \core_text::substr($type, 0, 40),
                'enrollmentid' => $enrollmentid,
                'sequence'     => $sequence,
                'sandbox'      => $sandbox,
                'outcome'      => $outcome,
                'timecreated'  => time(),
            ]);
        } catch (\dml_write_exception $e) {
            // Unique index hit: the same delivery was recorded by a parallel
            // request (only possible for ping / ignored, which take no lock).
            if (!$DB->record_exists('local_swayam_webhook', ['deliveryid' => $deliveryid])) {
                throw $e;
            }
        }
    }

    // =========================================================================
    // enrollment.activated
    // =========================================================================

    private static function activate(\stdClass $ent): string {
        $userid = $ent->swayamsub !== '' ? observer::find_userid_by_sub($ent->swayamsub) : 0;

        if (!$userid) {
            // Normal case: the event arrives before the learner's first sign-in.
            // The entitlement is applied by the user_loggedin observer.
            logger::info('WEBHOOK_ENTITLEMENT_STORED', [
                'enrollmentId'    => $ent->enrollmentid,
                'sub'             => $ent->swayamsub,
                'partnerCourseId' => $ent->partnercourseid,
                'next'            => 'Learner is enrolled at first Swayam Plus sign-in',
            ]);
            return 'entitlement_stored';
        }

        return self::grant($userid, $ent) ? 'enrolled' : 'course_not_found';
    }

    /**
     * Enrols a Moodle user for an active entitlement and upserts the enrolmap row.
     *
     * @return bool false if the Moodle course could not be resolved
     */
    public static function grant(int $userid, \stdClass $ent): bool {
        global $DB;

        $course = $ent->partnercourseid !== ''
            ? $DB->get_record('course', ['idnumber' => $ent->partnercourseid], 'id, fullname', IGNORE_MULTIPLE)
            : false;

        if (!$course) {
            logger::error('WEBHOOK_COURSE_NOT_FOUND', [
                'enrollmentId'    => $ent->enrollmentid,
                'partnerCourseId' => $ent->partnercourseid,
                'fix'             => $ent->partnercourseid === ''
                    ? 'Ask Swayam Plus team to set partnerCourseId on the course listing'
                    : "Set Course ID number = '{$ent->partnercourseid}' in Moodle course Edit Settings",
            ]);
            return false;
        }

        $courseid = (int)$course->id;

        $map = $DB->get_record('local_swayam_enrolmap', ['enrollmentid' => $ent->enrollmentid]);
        if (!$map) {
            $map = $DB->get_record('local_swayam_enrolmap', ['userid' => $userid, 'courseid' => $courseid]);
        }

        // Only lift a suspension this plugin applied (map TERMINATED by a
        // revocation). A learner suspended by hand by an admin stays suspended.
        $reactivate = $map && $map->status === 'TERMINATED';
        observer::enrol_user_into_course($userid, $courseid, $reactivate);

        $now = time();
        if ($map) {
            $update = (object)[
                'id'           => $map->id,
                'swayamsub'    => $ent->swayamsub !== '' ? $ent->swayamsub : $map->swayamsub,
                'timemodified' => $now,
            ];
            if ($map->enrollmentid !== $ent->enrollmentid) {
                // A new enrollment for the same learner + course (e.g. re-purchase
                // after a refund): completion has to be sent again for the new ID.
                $update->enrollmentid       = $ent->enrollmentid;
                $update->status             = 'ACCESS_PROVISIONED';
                $update->completionsent     = 0;
                $update->timecompletionsent = 0;
                $update->completionattempts = 0;
            } else if ($map->status !== 'IN_PROGRESS') {
                $update->status = 'ACCESS_PROVISIONED';
            }
            $DB->update_record('local_swayam_enrolmap', $update);
        } else {
            $DB->insert_record('local_swayam_enrolmap', (object)[
                'userid'       => $userid,
                'courseid'     => $courseid,
                'enrollmentid' => $ent->enrollmentid,
                'swayamsub'    => $ent->swayamsub,
                'status'       => 'ACCESS_PROVISIONED',
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        logger::success('WEBHOOK_ENROLLED', [
            'userid'       => $userid,
            'courseid'     => $courseid,
            'enrollmentId' => $ent->enrollmentid,
            'course'       => $course->fullname,
            'reactivated'  => $reactivate ? 'yes' : 'no',
        ]);
        return true;
    }

    /**
     * Called at sign-in: enrols the user for entitlements that arrived by
     * webhook before their Moodle account existed (or while the Enrollments
     * API was unreachable). Safe to call on every login.
     */
    public static function apply_entitlements_for_user(int $userid, string $sub): void {
        global $DB;

        $ents = $DB->get_records('local_swayam_entitle', [
            'swayamsub' => strtolower($sub),
            'status'    => 'ACCESS_PROVISIONED',
        ]);

        foreach ($ents as $ent) {
            $map = $DB->get_record('local_swayam_enrolmap', ['enrollmentid' => $ent->enrollmentid]);
            if ($map && $map->status !== 'TERMINATED') {
                continue;   // Already enrolled and mapped.
            }
            self::grant($userid, $ent);
        }
    }

    // =========================================================================
    // enrollment.revoked
    // =========================================================================

    private static function revoke(\stdClass $ent): string {
        global $DB;

        $map = $DB->get_record('local_swayam_enrolmap', ['enrollmentid' => $ent->enrollmentid]);
        if (!$map) {
            // Learner never signed in (the entitlement is now TERMINATED, so it
            // will not be applied), or an enrollment this LMS does not know.
            logger::info('WEBHOOK_REVOKE_NO_LOCAL_ENROLMENT', [
                'enrollmentId' => $ent->enrollmentid, 'reason' => $ent->reason,
            ]);
            return 'no_local_enrolment';
        }

        $action = get_config('local_swayam', 'revokeaction') === 'unenrol' ? 'unenrol' : 'suspend';
        observer::remove_user_from_course((int)$map->userid, (int)$map->courseid, $action);

        $DB->update_record('local_swayam_enrolmap', (object)[
            'id' => $map->id, 'status' => 'TERMINATED', 'timemodified' => time(),
        ]);

        if ($ent->reason === 'account_erased') {
            // Deleting a Moodle user is irreversible, so it is left to an admin.
            logger::error('WEBHOOK_ACCOUNT_ERASED_ACTION_REQUIRED', [
                'userid'       => $map->userid,
                'enrollmentId' => $ent->enrollmentid,
                'action'       => 'Learner erased their Swayam Plus account. Access removed; '
                                . 'an admin must delete the personal data held for this Moodle user.',
            ]);
        }

        return $action === 'unenrol' ? 'unenrolled' : 'suspended';
    }

    // =========================================================================
    // enrollment.completed
    // =========================================================================

    private static function complete(\stdClass $ent, string $by): string {
        global $DB;

        $map = $DB->get_record('local_swayam_enrolmap', ['enrollmentid' => $ent->enrollmentid]);
        if (!$map) {
            logger::info('WEBHOOK_COMPLETE_NO_LOCAL_ENROLMENT', ['enrollmentId' => $ent->enrollmentid, 'by' => $by]);
            return 'no_local_enrolment';
        }

        // Swayam Plus has the completion on record (confirmed by our own API
        // call, or by a Swayam Plus admin), so /completion never needs sending.
        $now = time();
        $DB->update_record('local_swayam_enrolmap', (object)[
            'id'                 => $map->id,
            'status'             => 'COMPLETED',
            'completionsent'     => 1,
            'timecompletionsent' => !empty($map->timecompletionsent) ? $map->timecompletionsent : $now,
            'timemodified'       => $now,
        ]);

        return $by === 'admin' ? 'completed_by_admin' : 'completed';
    }

    // =========================================================================
    // HOUSEKEEPING
    // =========================================================================

    /** Deletes delivery-log rows older than the retention period. */
    public static function purge_old_deliveries(): int {
        global $DB;
        $cutoff = time() - self::DELIVERY_RETENTION;
        $count  = $DB->count_records_select('local_swayam_webhook', 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select('local_swayam_webhook', 'timecreated < ?', [$cutoff]);
        }
        return $count;
    }
}
