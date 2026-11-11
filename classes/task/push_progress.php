<?php
namespace local_swayam\task;
defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: safety-net progress sync every 6 hours.
 *
 * The primary progress push is event-driven (module_completed observer).
 * This task catches any missed events — network failures, bulk imports, etc.
 */
class push_progress extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'Swayam Plus — Safety-net progress sync';
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

        $pushed  = 0;
        $skipped = 0;
        $errors  = 0;

        foreach ($maps as $map) {
            $userid   = (int)$map->userid;
            $courseid = (int)$map->courseid;

            // Only push if IN_PROGRESS.
            if ($map->status !== 'IN_PROGRESS') {
                mtrace("  [SKIP] userid={$userid} courseid={$courseid} — status={$map->status} (not IN_PROGRESS)");
                $skipped++;
                continue;
            }

            $percent = \local_swayam\observer::calculate_progress($userid, $courseid);

            if ($percent < 0) {
                $skipped++;
                continue;
            }

            if ($percent >= 100) {
                mtrace("  [SKIP] userid={$userid} courseid={$courseid} — 100%, handled by completion event");
                $skipped++;
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

        mtrace("local_swayam push_progress: done. pushed={$pushed} skipped={$skipped} errors={$errors}");
    }
}
