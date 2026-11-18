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
 * Swayam Plus login-initiation endpoint ("Continue" button on Swayam Plus).
 *
 * Signed-in visitor: the enrolment sync runs now (so the course is open on
 * arrival) and the visitor is sent to the target course.
 *
 * Anonymous visitor: sent to the Swayam Plus OAuth 2 service, coming back
 * here after sign-in. auth/oauth2/login.php requires a sesskey, which this
 * page passes. To stop other sites from starting a sign-in for a visitor
 * (login CSRF), the automatic redirect is used only when the request comes
 * from the Swayam Plus host (HTTP Referer). Any other request gets a page
 * with a button, so the visitor starts the sign-in themselves.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_swayam\logger;
use local_swayam\observer;

$iss    = optional_param('iss', '', PARAM_URL);
$target = optional_param('target_link_uri', '', PARAM_URL);

$apibase     = rtrim((string)get_config('local_swayam', 'apibase'), '/');
$expectediss = $apibase . '/oidc';
if ($apibase === '' || $iss !== $expectediss) {
    logger::error('LAUNCH_REJECTED', [
        'iss'      => $iss,
        'expected' => $expectediss,
        'fix'      => 'The iss sent by Swayam Plus must equal the API base URL setting + /oidc',
    ]);
    throw new moodle_exception('invalidrequest');
}

$wantsurl = new moodle_url($target !== '' ? $target : '/my/');
if ($wantsurl->get_host() !== parse_url($CFG->wwwroot, PHP_URL_HOST)) {
    $wantsurl = new moodle_url('/my/');   // Never redirect off-site.
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/swayam/launch.php', ['iss' => $iss, 'target_link_uri' => $wantsurl->out(false)]));
$PAGE->set_pagelayout('login');

if (isloggedin() && !isguestuser()) {
    if (observer::has_swayam_login((int)$USER->id)) {
        // Learner-initiated page: enrol now so the course is open on arrival.
        try {
            observer::sync_user((int)$USER->id);
        } catch (\Throwable $e) {
            logger::error('LAUNCH_SYNC_FAILED', ['userid' => $USER->id, 'error' => logger::describe($e)]);
        }
    }
    logger::info('LAUNCH', ['userid' => $USER->id, 'state' => 'signed in', 'target' => $wantsurl->out(false)]);
    redirect($wantsurl);
}

$issuerids = observer::get_issuer_ids();
if (!$issuerids) {
    logger::error('LAUNCH_NO_ISSUER', [
        'fix' => 'Create the Swayam Plus OAuth 2 service with a base URL that starts with the API base URL setting',
    ]);
    throw new moodle_exception('launchnoissuer', 'local_swayam');
}

// After sign-in the visitor comes back here, so the sync above runs first.
$signin = new moodle_url('/auth/oauth2/login.php', [
    'id'       => reset($issuerids),
    'wantsurl' => $PAGE->url->out(false),
    'sesskey'  => sesskey(),
]);

// Trusted origin: the Swayam Plus host, or a host in the same parent domain.
$referrerhost = strtolower((string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST));
$swayamhost   = strtolower((string)parse_url($apibase, PHP_URL_HOST));
$parent       = substr($swayamhost, strpos($swayamhost, '.') + 1);
$trusted      = $referrerhost !== '' && (
    $referrerhost === $swayamhost
    || (substr_count($parent, '.') >= 1 && substr($referrerhost, -strlen('.' . $parent)) === '.' . $parent)
);

logger::info('LAUNCH', [
    'state'    => $trusted ? 'sending to SSO' : 'sign-in button shown',
    'referrer' => $referrerhost,
    'target'   => $wantsurl->out(false),
]);

if ($trusted) {
    redirect($signin);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('launchheading', 'local_swayam'));
echo html_writer::tag('p', get_string('launchintro', 'local_swayam'));
echo $OUTPUT->single_button($signin, get_string('launchbutton', 'local_swayam'), 'get');
echo $OUTPUT->footer();
