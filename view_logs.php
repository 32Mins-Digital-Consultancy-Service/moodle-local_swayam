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
 * Integration log viewer.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_swayam\logger;

$level = optional_param('level', '', PARAM_ALPHA);
$clear = optional_param('clear', 0, PARAM_BOOL);

if (!in_array($level, ['', 'ERROR', 'SUCCESS', 'INFO'], true)) {
    $level = '';
}

admin_externalpage_setup('local_swayam_logs', '', $level !== '' ? ['level' => $level] : []);

$baseurl = new moodle_url('/local/swayam/view_logs.php');

if ($clear) {
    if (optional_param('confirm', 0, PARAM_BOOL)) {
        require_sesskey();
        logger::clear();
        redirect($baseurl, get_string('logscleared', 'local_swayam'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('clearlogsconfirm', 'local_swayam'),
        new moodle_url($baseurl, ['clear' => 1, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('logs', 'local_swayam'));

$days = (int)get_config('local_swayam', 'logretention') ?: logger::DEFAULT_RETENTION_DAYS;
echo html_writer::tag('p', get_string('logsintro', 'local_swayam', $days));

// Level filter and actions.
$links = [];
foreach (['' => get_string('all'), 'ERROR' => 'ERROR', 'SUCCESS' => 'SUCCESS', 'INFO' => 'INFO'] as $value => $label) {
    $url = $value === '' ? $baseurl : new moodle_url($baseurl, ['level' => $value]);
    $links[] = $value === $level
        ? html_writer::tag('strong', $label)
        : html_writer::link($url, $label);
}
echo html_writer::div(get_string('loglevel', 'local_swayam') . ': ' . implode(' | ', $links), 'mb-2');
echo html_writer::div(
    $OUTPUT->single_button(new moodle_url($baseurl, ['clear' => 1]), get_string('clearlogs', 'local_swayam'), 'get'),
    'mb-3'
);

$logs = logger::get_logs(500, $level);

if (empty($logs)) {
    echo $OUTPUT->notification(get_string('nologs', 'local_swayam'), \core\output\notification::NOTIFY_INFO);
} else {
    $classes = ['SUCCESS' => 'text-success', 'INFO' => 'text-info', 'ERROR' => 'text-danger'];

    $table = new html_table();
    $table->head = [
        get_string('time'),
        get_string('loglevel', 'local_swayam'),
        get_string('logevent', 'local_swayam'),
        get_string('user'),
        get_string('logdetails', 'local_swayam'),
    ];
    $table->attributes['class'] = 'generaltable table-sm';

    foreach ($logs as $entry) {
        $details = [];
        foreach ((array)json_decode((string)$entry->data, true) as $key => $value) {
            $details[] = html_writer::tag('strong', s($key)) . ': ' . s((string)$value);
        }
        $user = $entry->userid
            ? html_writer::link(new moodle_url('/user/profile.php', ['id' => $entry->userid]), $entry->userid)
            : '';
        $table->data[] = [
            userdate($entry->timecreated, get_string('strftimedatetimeshort', 'core_langconfig')),
            html_writer::span(s($entry->loglevel), ($classes[$entry->loglevel] ?? '') . ' font-weight-bold'),
            html_writer::tag('code', s($entry->event)),
            $user,
            implode('<br>', $details),
        ];
    }
    echo html_writer::tag('p', get_string('logsshown', 'local_swayam', count($logs)));
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
