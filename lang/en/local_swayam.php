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
 * English strings for local_swayam.
 *
 * @package    local_swayam
 * @copyright  2026 32Mins Digital Consultancy Service
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['apibase'] = 'Swayam Plus API base URL';
$string['apibase_desc'] = 'Base URL of the Swayam Plus server, for example https://swayamplus.education.gov.in (no trailing slash). The Swayam Plus OAuth 2 service is the one whose base URL starts with this value.';
$string['apiresource'] = 'API resource identifier';
$string['apiresource_desc'] = 'Must match the resource the access token is issued for, for example https://swayamplus.education.gov.in/api';
$string['cachedef_token'] = 'Swayam Plus access token';
$string['clearlogs'] = 'Clear logs';
$string['clearlogsconfirm'] = 'Delete every entry in the Swayam Plus integration log? This cannot be undone.';
$string['clientid'] = 'API client ID';
$string['clientid_desc'] = 'Client ID issued by the Swayam Plus team for server-to-server calls (client_credentials).';
$string['clientsecret'] = 'API client secret';
$string['clientsecret_desc'] = 'Client secret that goes with the API client ID.';
$string['launchbutton'] = 'Sign in with Swayam Plus';
$string['launchheading'] = 'Continue to your course';
$string['launchintro'] = 'Sign in with your Swayam Plus account to open the course.';
$string['launchnoissuer'] = 'Swayam Plus sign-in is not set up on this site. Please contact the site administrator.';
$string['logdetails'] = 'Details';
$string['logevent'] = 'Event';
$string['logheading_desc'] = 'Sign-in, enrolment, progress, completion and webhook events are recorded in the <a href="{$a}">integration log</a>. Entries hold IDs and status codes only.';
$string['loglevel'] = 'Level';
$string['logretention'] = 'Keep log entries for';
$string['logretention_desc'] = 'Older entries are deleted by the daily clean-up task.';
$string['logs'] = 'Swayam Plus integration log';
$string['logscleared'] = 'The integration log has been cleared.';
$string['logsintro'] = 'Newest entries first. Entries are deleted after {$a} days.';
$string['logsshown'] = '{$a} entries shown.';
$string['nologs'] = 'No log entries yet.';
$string['pluginname'] = 'Swayam Plus Integration';
$string['privacy:metadata:enrolmap'] = 'Links a Moodle user and course to the Swayam Plus enrollment the learner bought, so progress and completion can be sent back.';
$string['privacy:metadata:enrolmap:completionsent'] = 'Whether Swayam Plus has accepted the course completion.';
$string['privacy:metadata:enrolmap:courseid'] = 'The Moodle course.';
$string['privacy:metadata:enrolmap:enrollmentid'] = 'The Swayam Plus enrollment ID.';
$string['privacy:metadata:enrolmap:status'] = 'The Swayam Plus enrollment status.';
$string['privacy:metadata:enrolmap:swayamsub'] = 'The learner\'s Swayam Plus ID.';
$string['privacy:metadata:enrolmap:timecompletionsent'] = 'When Swayam Plus accepted the course completion.';
$string['privacy:metadata:enrolmap:timecreated'] = 'When the link was created.';
$string['privacy:metadata:enrolmap:timemodified'] = 'When the link was last changed.';
$string['privacy:metadata:enrolmap:userid'] = 'The Moodle user.';
$string['privacy:metadata:entitle'] = 'Course access granted by Swayam Plus through the webhook, kept until the learner signs in to Moodle.';
$string['privacy:metadata:entitle:enrollmentid'] = 'The Swayam Plus enrollment ID.';
$string['privacy:metadata:entitle:partnercourseid'] = 'The course the access is for (Moodle course ID number).';
$string['privacy:metadata:entitle:status'] = 'The Swayam Plus enrollment status.';
$string['privacy:metadata:entitle:swayamsub'] = 'The learner\'s Swayam Plus ID.';
$string['privacy:metadata:entitle:timecreated'] = 'When the access was first received.';
$string['privacy:metadata:log'] = 'Integration log of sign-in, enrolment, progress and completion events. Deleted after the retention period.';
$string['privacy:metadata:log:data'] = 'Event details: IDs (course, enrollment) and status codes.';
$string['privacy:metadata:log:event'] = 'The event code.';
$string['privacy:metadata:log:timecreated'] = 'When the event happened.';
$string['privacy:metadata:log:userid'] = 'The Moodle user the event is about.';
$string['privacy:metadata:swayamplus'] = 'Swayam Plus (the course marketplace the learner bought the course on) receives the learner\'s course progress and completion.';
$string['privacy:metadata:swayamplus:enrollmentid'] = 'The Swayam Plus enrollment ID the update is for.';
$string['privacy:metadata:swayamplus:finalgrade'] = 'The learner\'s final course grade, in the completion note.';
$string['privacy:metadata:swayamplus:progress'] = 'The learner\'s course progress percentage.';
$string['privacy:metadata:swayamplus:swayamsub'] = 'The learner\'s Swayam Plus ID, used to fetch the learner\'s enrollments.';
$string['privacy:metadata:webhook'] = 'Webhook deliveries already processed, kept 30 days to detect duplicates.';
$string['privacy:metadata:webhook:enrollmentid'] = 'The Swayam Plus enrollment ID the delivery was about.';
$string['privacy:metadata:webhook:eventtype'] = 'The webhook event type.';
$string['privacy:metadata:webhook:timecreated'] = 'When the delivery was received.';
$string['privacy:path:enrolments'] = 'Swayam Plus enrollments';
$string['privacy:path:log'] = 'Swayam Plus integration log';
$string['revokeaction'] = 'When access is revoked';
$string['revokeaction_desc'] = 'What to do with the learner\'s course enrolment when Swayam Plus sends enrollment.revoked (refund, expiry, removal). Suspend keeps progress and grades, and is reversed automatically if access is granted again.';
$string['revokeaction_suspend'] = 'Suspend the enrolment (recommended)';
$string['revokeaction_unenrol'] = 'Unenrol the learner';
$string['subfield'] = 'Swayam Plus ID profile field';
$string['subfield_desc'] = 'Short name of the custom profile field the OAuth 2 service fills with the Swayam Plus ID ("sub"). The field must be locked (Locked = Yes); an unlocked field is ignored because learners could change it.';
$string['taskcleanup'] = 'Swayam Plus: delete old log entries and webhook deliveries';
$string['taskpushprogress'] = 'Swayam Plus: resend progress and completions';
$string['taskpushuserprogress'] = 'Swayam Plus: send a learner\'s progress';
$string['tasksyncuser'] = 'Swayam Plus: enrol a learner after sign-in';
$string['webhookheading'] = 'Webhook (Swayam Plus to Moodle)';
$string['webhookheading_desc'] = 'Swayam Plus calls this site when a learner\'s access starts or ends. Register this Receiver URL on the Swayam Plus Integration page (Webhook, Add webhook):<br><code>{$a}</code>';
$string['webhooksecret'] = 'Webhook signing secret';
$string['webhooksecret_desc'] = 'The signing secret shown once by Swayam Plus when the webhook is added (starts with whsec_). Every webhook request is rejected with 401 until this is set.';
