<?php
require_once(__DIR__ . '/../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

use local_swayam\logger;

if (optional_param('clear', 0, PARAM_INT) === 1) {
    logger::clear();
    redirect(new moodle_url('/local/swayam/view_logs.php'), 'Logs cleared.');
}

$PAGE->set_url(new moodle_url('/local/swayam/view_logs.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Swayam Plus — Integration Logs');
$PAGE->set_heading('Swayam Plus — Integration Logs');

echo $OUTPUT->header();

echo html_writer::tag('p',
    html_writer::link(new moodle_url('/local/swayam/view_logs.php', ['clear' => 1]),
        'Clear logs', ['style' => 'color:red;margin-right:20px']) .
    html_writer::link(new moodle_url('/local/swayam/view_logs.php'),
        'Refresh', ['style' => 'color:blue'])
);

$logs   = logger::get_logs(200);
$colors = ['SUCCESS' => '#22c55e', 'INFO' => '#3b82f6', 'ERROR' => '#ef4444'];

if (empty($logs)) {
    echo html_writer::tag('p', '⚠️ No logs yet. Complete an activity in a Swayam Plus mapped course to see output here.');
} else {
    echo html_writer::tag('p', count($logs) . ' log entries (newest first)');
    foreach ($logs as $entry) {
        $level = $entry['level'] ?? 'INFO';
        $color = $colors[$level] ?? '#888';
        $data  = $entry['data']  ?? [];
        $html  = "<div style='font-family:monospace;font-size:13px;padding:8px 12px;margin-bottom:6px;"
               . "border-left:4px solid {$color};background:#f9f9f9;border-radius:4px'>";
        $html .= "<span style='color:#888'>{$entry['time']}</span> ";
        $html .= "<span style='color:{$color};font-weight:bold'>[{$level}]</span> ";
        $html .= "<span style='font-weight:bold'>{$entry['event']}</span>";
        if (!empty($data)) {
            $html .= "<br><span style='color:#555;padding-left:16px'>";
            foreach ($data as $k => $v) {
                $html .= "<b>{$k}</b>: " . htmlspecialchars((string)$v) . " &nbsp; ";
            }
            $html .= "</span>";
        }
        $html .= "</div>";
        echo $html;
    }
}

echo $OUTPUT->footer();
