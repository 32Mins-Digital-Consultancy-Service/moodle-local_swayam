<?php
// local/swayam/launch.php — Swayam Plus login-initiation endpoint.
// Entry point for the "Continue on IITM Pravartak" button on
// swayamplus.education.gov.in. Skips Moodle's login picker: if the
// visitor already has a live Moodle session, forward straight to the
// course; otherwise hand off to the Swayam Plus OAuth2 issuer.
require_once(__DIR__ . '/../../config.php');

$iss    = optional_param('iss', '', PARAM_URL);
$target = optional_param('target_link_uri', $CFG->wwwroot . '/my/', PARAM_URL);

// Expected issuer is derived from the plugin's own apibase setting
// rather than hardcoded, so it stays in sync with settings.php.
$expectediss = rtrim(get_config('local_swayam', 'apibase'), '/') . '/oidc';
if ($iss !== $expectediss) {
    \local_swayam\logger::error('LAUNCH_REJECTED', [
        'iss'      => $iss,
        'expected' => $expectediss,
        'fix'      => 'The iss sent by Swayam Plus must equal the API base URL setting + /oidc',
    ]);
    throw new moodle_exception('invalidrequest');   // only accept Swayam launches
}

$wantsurl = new moodle_url($target);
if ($wantsurl->get_host() !== parse_url($CFG->wwwroot, PHP_URL_HOST)) {
    $wantsurl = new moodle_url('/my/');              // never redirect off-site
}

if (isloggedin() && !isguestuser()) {
    \local_swayam\logger::info('LAUNCH', ['userid' => $USER->id, 'state' => 'already signed in', 'target' => $wantsurl->out(false)]);
    redirect($wantsurl);                             // already signed in → course
}

// The Swayam Plus issuer is the one whose base URL matches the API base
// setting (same lookup the enrolment sync uses); falls back to id 1.
$apibase  = rtrim((string)get_config('local_swayam', 'apibase'), '/');
$issuerid = $apibase === '' ? 0 : (int)$DB->get_field_sql(
    "SELECT id FROM {oauth2_issuer} WHERE " . $DB->sql_like('baseurl', ':baseurl', false) . " ORDER BY id",
    ['baseurl' => $DB->sql_like_escape($apibase) . '%'],
    IGNORE_MULTIPLE
);
if (!$issuerid) {
    $issuerid = 1;
}

\local_swayam\logger::info('LAUNCH', ['state' => 'sending to SSO', 'issuerid' => $issuerid, 'target' => $wantsurl->out(false)]);

redirect(new moodle_url('/auth/oauth2/login.php', [
    'id'       => $issuerid,
    'wantsurl' => $wantsurl->out(false),
    'sesskey'  => sesskey(),                          // valid: minted in the visitor's session
]));
