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
