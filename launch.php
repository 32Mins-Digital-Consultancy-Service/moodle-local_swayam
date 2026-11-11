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
    throw new moodle_exception('invalidrequest');   // only accept Swayam launches
}

$wantsurl = new moodle_url($target);
if ($wantsurl->get_host() !== parse_url($CFG->wwwroot, PHP_URL_HOST)) {
    $wantsurl = new moodle_url('/my/');              // never redirect off-site
}

if (isloggedin() && !isguestuser()) {
    redirect($wantsurl);                             // already signed in → course
}

redirect(new moodle_url('/auth/oauth2/login.php', [
    'id'       => 1,                                 // Swayam Plus issuer id on this site
    'wantsurl' => $wantsurl->out(false),
    'sesskey'  => sesskey(),                          // valid: minted in the visitor's session
]));
