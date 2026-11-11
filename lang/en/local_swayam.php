<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname']        = 'Swayam Plus Integration';
$string['apibase']           = 'Swayam Plus API base URL';
$string['apibase_desc']      = 'Base URL of the Swayam Plus server, e.g. https://swayamplus.education.gov.in (no trailing slash)';
$string['apiresource']       = 'API resource identifier';
$string['apiresource_desc']  = 'Must match the resource the M2M token is minted for, e.g. https://swayamplus.education.gov.in/api';
$string['clientid']          = 'M2M client ID';
$string['clientid_desc']     = 'OAuth 2 client_credentials client ID issued by Swayam Plus team for server-to-server calls';
$string['clientsecret']      = 'M2M client secret';
$string['clientsecret_desc'] = 'Corresponding M2M client secret';
$string['subfield']          = 'Swayam sub profile field shortname';
$string['subfield_desc']     = 'Shortname of the Moodle custom user profile field that stores the Swayam Plus OIDC sub UUID (default: swayamsub)';
$string['cachedef_token']    = 'Swayam Plus M2M access token cache';
$string['webhookheading']       = 'Webhook (Swayam Plus → Moodle)';
$string['webhookheading_desc']  = 'Swayam Plus calls this site when a learner\'s access starts or ends. Register this Receiver URL on the Swayam Plus Integration page (Webhook → Add webhook):<br><code>{$a}</code>';
$string['webhooksecret']        = 'Webhook signing secret';
$string['webhooksecret_desc']   = 'The signing secret shown once by Swayam Plus when the webhook is added (starts with whsec_). Every webhook request is rejected with 401 until this is set.';
$string['revokeaction']         = 'When access is revoked';
$string['revokeaction_desc']    = 'What to do with the learner\'s course enrolment when Swayam Plus sends enrollment.revoked (refund, expiry, removal). Suspend keeps progress and grades, and is reversed automatically if access is granted again.';
$string['revokeaction_suspend'] = 'Suspend the enrolment (recommended)';
$string['revokeaction_unenrol'] = 'Unenrol the learner';
