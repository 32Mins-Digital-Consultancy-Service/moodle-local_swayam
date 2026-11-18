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
 * Integration log, stored in local_swayam_log.
 *
 * Entries hold IDs, status codes and short error codes only: no response
 * bodies, grades, names or e-mail addresses. They are deleted after the
 * retention period (setting logretention) by task\cleanup, and are covered
 * by the privacy provider.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_swayam;

/**
 * Integration log.
 */
class logger {

    /** Keys never stored, whatever a caller passes. */
    const DROPPED_KEYS = ['sub', 'body', 'response', 'payload', 'note', 'enrollment', 'email', 'grade'];

    /** Default retention in days. */
    const DEFAULT_RETENTION_DAYS = 30;

    /**
     * Writes one entry.
     *
     * @param string $level INFO | SUCCESS | ERROR
     * @param string $event short event code
     * @param array $data details; 'userid' goes into its own column
     */
    public static function log(string $level, string $event, array $data = []): void {
        global $DB;

        $userid = (int)($data['userid'] ?? 0);
        unset($data['userid']);
        foreach (self::DROPPED_KEYS as $key) {
            unset($data[$key]);
        }
        foreach ($data as $key => $value) {
            $data[$key] = is_scalar($value) || $value === null
                ? \core_text::substr((string)$value, 0, 500)
                : '[' . gettype($value) . ']';
        }

        try {
            $DB->insert_record('local_swayam_log', (object)[
                'loglevel'    => \core_text::substr($level, 0, 10),
                'event'       => \core_text::substr($event, 0, 64),
                'userid'      => $userid,
                'data'        => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timecreated' => time(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break enrolment or the learner's request
            // (for example before the upgrade has created the table).
            debugging('local_swayam: could not write log entry ' . $event . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Exception message for the log. Moodle hides the cause of a database
     * failure behind "Error writing to database"; the driver's own message
     * (duplicate key, unknown column, ...) is what an admin needs.
     *
     * @param \Throwable $e
     * @return string
     */
    public static function describe(\Throwable $e): string {
        $msg = $e->getMessage();
        if ($e instanceof \dml_exception && !empty($e->error)) {
            $msg .= ' [' . \core_text::substr((string)$e->error, 0, 300) . ']';
        }
        return $msg;
    }

    /**
     * Informational entry.
     *
     * @param string $event
     * @param array $data
     */
    public static function info(string $event, array $data = []): void {
        self::log('INFO', $event, $data);
    }

    /**
     * Error entry.
     *
     * @param string $event
     * @param array $data
     */
    public static function error(string $event, array $data = []): void {
        self::log('ERROR', $event, $data);
    }

    /**
     * Success entry.
     *
     * @param string $event
     * @param array $data
     */
    public static function success(string $event, array $data = []): void {
        self::log('SUCCESS', $event, $data);
    }

    /**
     * Most recent entries, newest first.
     *
     * @param int $limit
     * @param string $level only this level, or '' for all
     * @return \stdClass[]
     */
    public static function get_logs(int $limit = 200, string $level = ''): array {
        global $DB;
        $conditions = $level !== '' ? ['loglevel' => $level] : [];
        return array_values($DB->get_records('local_swayam_log', $conditions, 'id DESC', '*', 0, $limit));
    }

    /**
     * Deletes every entry.
     */
    public static function clear(): void {
        global $DB;
        $DB->delete_records('local_swayam_log');
    }

    /**
     * Deletes entries older than the retention period.
     *
     * @return int number of entries deleted
     */
    public static function purge(): int {
        global $DB;
        $days   = (int)get_config('local_swayam', 'logretention') ?: self::DEFAULT_RETENTION_DAYS;
        $cutoff = time() - $days * DAYSECS;
        $count  = $DB->count_records_select('local_swayam_log', 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select('local_swayam_log', 'timecreated < ?', [$cutoff]);
        }
        return $count;
    }
}
