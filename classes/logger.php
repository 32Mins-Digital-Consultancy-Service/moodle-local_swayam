<?php
namespace local_swayam;
defined('MOODLE_INTERNAL') || die();

class logger {

    private static function get_log_path(): string {
        global $CFG;
        $dir = $CFG->dataroot . '/local_swayam';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir . '/swayam.log';
    }

    public static function log(string $level, string $event, array $data = []): void {
        $line = json_encode([
            'time'  => date('Y-m-d H:i:s'),
            'level' => $level,
            'event' => $event,
            'data'  => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(self::get_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    /**
     * Exception message for the log. Moodle hides the cause of a database
     * failure behind "Error writing to database"; the driver's own message
     * (duplicate key, unknown column, ...) is what an admin needs.
     */
    public static function describe(\Throwable $e): string {
        $msg = $e->getMessage();
        if ($e instanceof \dml_exception && !empty($e->error)) {
            $msg .= ' [' . \core_text::substr((string)$e->error, 0, 300) . ']';
        }
        return $msg;
    }

    public static function info(string $event, array $data = []): void {
        self::log('INFO', $event, $data);
    }

    public static function error(string $event, array $data = []): void {
        self::log('ERROR', $event, $data);
    }

    public static function success(string $event, array $data = []): void {
        self::log('SUCCESS', $event, $data);
    }

    public static function get_logs(int $lines = 200): array {
        $path = self::get_log_path();
        if (!file_exists($path)) {
            return [];
        }
        $all    = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recent = array_slice($all, -$lines);
        $parsed = [];
        foreach (array_reverse($recent) as $line) {
            $decoded = json_decode($line, true);
            if ($decoded) {
                $parsed[] = $decoded;
            }
        }
        return $parsed;
    }

    public static function clear(): void {
        $path = self::get_log_path();
        if (file_exists($path)) {
            file_put_contents($path, '');
        }
    }
}
