<?php
namespace local_swayam\task;
defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: safety-net sync every 6 hours.
 *
 * - Progress below 100%  -> POST /progress (catches missed events).
 * - Progress at 100% and completion not yet confirmed -> POST /completion
 *   (v1.0.3: retries completions; v1.0.2 skipped these, so a failed or
 *    missed completion was never re-sent and the portal stayed at e.g. 98%).
 */
class push_progress extends \core\task\scheduled_task {

    /** Stop retrying a completion after this many failed attempts (≈ 7.5 days at 6h). */
    const MAX_COMPLETION_ATTEMPTS = 30;

    public function get_name(): string {
        return 'Swayam Plus — Safety-net progress and completion sync';
    }

    public function execute(): void {
        global $DB;

        $token = \local_swayam\observer::get_token_for_task();
        if (!$token) {
            mtrace('local_swayam push_progress: could not mint M2M token — aborting');
            return;
        }

        $base = rtrim(get_config('local_swayam', 'apibase'), '/');
        $maps = $DB->get_records('local_swayam_enrolmap');

        if (empty($maps)) {
            mtrace('local_swayam push_progress: no mapped enrollments — nothing to do');
            return;
        }

        mtrace('local_swayam push_progress: checking ' . count($maps) . ' enrollment(s)...');

        $pushed = 0; $completed = 0; $skipped = 0; $errors = 0;

        foreach ($maps as $map) {
            $userid   = (int)$map->userid;
            $courseid = (int)$map->courseid;

            if (!empty($map->completionsent)) {
                $skipped++;   // Already completed on Swayam Plus — nothing more to send.
                continue;
            }

            if ($map->status !== 'IN_PROGRESS') {
                mtrace("  [SKIP] userid={$userid} courseid={$courseid} — status={$map->status} (not IN_PROGRESS)");
                $skipped++;
                continue;
            }

            try {
                $percent = \local_swayam\observer::calculate_progress($userid, $courseid);
            } catch (\Throwable $e) {
                mtrace("  [ERR] userid={$userid} courseid={$courseid} — " . $e->getMessage());
                $errors++;
                continue;
            }

            if ($percent < 0) {
                $skipped++;
                continue;
            }

            if ($percent >= 100) {
                if ((int)($map->completionattempts ?? 0) >= self::MAX_COMPLETION_ATTEMPTS) {
                    mtrace("  [SKIP] userid={$userid} courseid={$courseid} — completion failed "
                        . self::MAX_COMPLETION_ATTEMPTS . " times, run cli/resend_completion.php manually");
                    $skipped++;
                    continue;
                }
                $ok = \local_swayam\observer::send_completion($map, $userid, $courseid, 'scheduled_task');
                if ($ok) {
                    $completed++;
                    mtrace("  [OK]  userid={$userid} courseid={$courseid} enrollmentId={$map->enrollmentid} → COMPLETION sent");
                } else {
                    $errors++;
                    mtrace("  [ERR] userid={$userid} courseid={$courseid} → completion failed (see view_logs.php)");
                }
                continue;
            }

            $curl = new \curl(['ignoresecurity' => true]);
            $curl->setHeader("Authorization: Bearer {$token}");
            $curl->setHeader('Content-Type: application/json');
            $curl->post(
                "{$base}/api/v1/partner/enrollments/{$map->enrollmentid}/progress",
                json_encode(['percent' => $percent])
            );
            $info = $curl->get_info();
            $http = (int)($info['http_code'] ?? 0);

            if ($http === 204) {
                $pushed++;
                mtrace("  [OK]  userid={$userid} courseid={$courseid} enrollmentId={$map->enrollmentid} → {$percent}%");
                $DB->update_record('local_swayam_enrolmap', (object)['id' => $map->id, 'timemodified' => time()]);
            } else {
                $errors++;
                mtrace("  [ERR] userid={$userid} courseid={$courseid} → HTTP {$http}");
            }
        }

        mtrace("local_swayam push_progress: done. progress={$pushed} completions={$completed} skipped={$skipped} errors={$errors}");
    }
}
