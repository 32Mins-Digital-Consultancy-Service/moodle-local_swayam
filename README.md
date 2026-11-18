# Swayam Plus Integration (local_swayam)

Connects a Moodle site to [Swayam Plus](https://swayamplus.education.gov.in), the
Government of India course marketplace, for course providers who deliver their
Swayam Plus courses on their own Moodle LMS.

## What it does

- **Single sign-on.** Learners click *Continue* on Swayam Plus and arrive in the
  Moodle course, signed in with their Swayam Plus account (Moodle OAuth 2).
- **Automatic enrolment.** At sign-in the plugin reads the learner's Swayam Plus
  enrollments and enrols them (manual enrolment, student role) in the Moodle
  course whose *Course ID number* equals the Swayam Plus `partnerCourseId`.
- **Progress and completion.** When a learner completes an activity, the course
  progress percentage is sent to Swayam Plus. At 100% (or when Moodle marks the
  course complete) the completion is sent, which issues the Swayam Plus certificate.
- **Webhook.** Swayam Plus tells Moodle when access starts, ends (refund, expiry)
  or is completed. Access that ends suspends (or unenrols) the learner.
- **Safety net.** A scheduled task resends progress and completions that failed.
- **Integration log** for administrators, with a retention period.

## Requirements

- Moodle 4.0 or later (tested up to 5.3).
- PHP with cURL. The Swayam Plus host must not be blocked in
  *Site administration > General > Security > HTTP security*.
- Credentials from the Swayam Plus team: an OIDC client (sign-in) and an API
  client (server-to-server), and the webhook signing secret.
- For the webhook: the site must be reachable over HTTPS at a host name.
- Cron running (enrolment after sign-in and progress updates run as ad-hoc tasks).

## Installation

1. Install the plugin into `local/swayam`, either from *Site administration >
   Plugins > Install plugins* or by extracting the ZIP into `local/`.
2. Visit *Site administration > Notifications* to complete the installation.

## Configuration

### 1. Completion tracking
*Site administration > Advanced features > Enable completion tracking*: **Yes**.

### 2. Profile field for the Swayam Plus ID
*Site administration > Users > User profile fields > Create a new profile field > Text input*

| Setting | Value |
|---|---|
| Short name | `swayamsub` |
| Name | Swayam Plus ID |
| **Locked** | **Yes** (required) |
| **Visible** | **Not visible** |

> **Security: the field must be locked.** The plugin uses this field to know which
> Swayam Plus learner a Moodle user is. An unlocked field could be edited by the
> learner, so the plugin ignores it (and logs `SUB_FIELD_NOT_LOCKED`). The plugin
> also only trusts the field for users who signed in through the Swayam Plus
> OAuth 2 service. Upgrading from an earlier version locks and hides the field
> automatically.

### 3. OAuth 2 service
*Site administration > Server > OAuth 2 services > Custom*

| Setting | Value |
|---|---|
| Name | Swayam Plus |
| Client ID / Client secret | OIDC client from the Swayam Plus team |
| Authenticate token requests via HTTP headers | Yes |
| Service base URL | `https://swayamplus.education.gov.in/oidc` |
| This service will be used | Login page and internal services |
| Scopes included in a login request | `openid profile email` |
| Require email verification | **No** |

Endpoints (*Configure endpoints*): `authorization_endpoint` `/oidc/auth`,
`token_endpoint` `/oidc/token`, `userinfo_endpoint` `/oidc/me`,
`end_session_endpoint` `/oidc/session/end` (all under the Swayam Plus host).

User field mappings (*Configure user field mappings*):

| External field | Internal field |
|---|---|
| `sub` | `profile_field_swayamsub` (required) |
| `email` | email |
| `given_name` | firstname |
| `family_name` | lastname |

Enable **OAuth 2** in *Site administration > Plugins > Authentication > Manage
authentication*, and make sure **Prevent account creation when authenticating**
is off. Ask the Swayam Plus team to allow the redirect URI
`https://your-moodle/admin/oauth2callback.php`.

### 4. Plugin settings
*Site administration > Plugins > Local plugins > Swayam Plus Integration*

| Setting | Value |
|---|---|
| Swayam Plus API base URL | `https://swayamplus.education.gov.in` |
| API resource identifier | `https://swayamplus.education.gov.in/api` |
| API client ID / secret | API client from the Swayam Plus team |
| Swayam Plus ID profile field | `swayamsub` |
| Webhook signing secret | `whsec_...` (see below) |
| When access is revoked | Suspend (recommended) or Unenrol |
| Keep log entries for | 30 days (default) |

### 5. Webhook
On the Swayam Plus Integration page choose *Webhook > Add webhook*:

- Receiver URL: `https://your-moodle/local/swayam/webhook.php`
- Events: Access granted, Access ended, Completed.

Copy the signing secret (shown once) into the plugin setting, then choose
*Verify receiver* on Swayam Plus. All four checks should pass.

### 6. Courses
For each Swayam Plus course:

1. Set the Moodle course's **Course ID number** to the Swayam Plus `partnerCourseId`.
2. Enable completion tracking in the course and set completion conditions on
   each activity (and course completion criteria if you use them).
3. Give the Swayam Plus team the launch URL for the *Continue* button:
   `https://your-moodle/local/swayam/launch.php`.

## How it works

| Moment | What the plugin does |
|---|---|
| Learner clicks *Continue* on Swayam Plus | `launch.php` sends them to Swayam Plus sign-in (or straight on if already signed in), runs the enrolment sync and opens the course |
| Any Swayam Plus sign-in | Ad-hoc task syncs the learner's enrollments |
| Activity completed | Ad-hoc task sends the progress percentage |
| 100% or course completed | Ad-hoc task sends the completion (once per enrollment) |
| Webhook received | Access granted, ended or completed is applied |
| Every 6 hours | Resends missed progress and failed completions |
| Daily | Deletes log entries past the retention period and webhook deliveries older than 30 days |

Progress is accepted by Swayam Plus only after the learner has opened the course
from Swayam Plus once (enrollment status `IN_PROGRESS`).

## Administration

- **Integration log:** *Site administration > Plugins > Local plugins > Swayam
  Plus integration log*. Entries hold IDs and status codes only.
- **CLI tools** (run from the Moodle directory):
  - `php local/swayam/cli/backfill_enrolmap.php [--execute]`: map learners who
    were enrolled before the plugin was installed.
  - `php local/swayam/cli/resend_completion.php [--execute] [--userid=] [--courseid=] [--force]`:
    send completions for learners at 100%.

## Privacy

The plugin implements the Moodle Privacy API. It stores the link between a
Moodle user, a course and a Swayam Plus enrollment, webhook entitlements, and
the integration log, and it sends the learner's Swayam Plus ID, enrollment ID,
course progress and completion (with the final course grade) to Swayam Plus.
All of it can be exported and deleted through *Site administration > Users >
Privacy and policies > Data requests*.

## Support

- Source code: https://github.com/32Mins-Digital-Consultancy-Service/moodle-local_swayam
- Issues: https://github.com/32Mins-Digital-Consultancy-Service/moodle-local_swayam/issues

## License

2026 32Mins Digital Consultancy Service

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU General Public License as published by the Free Software
Foundation, either version 3 of the License, or (at your option) any later
version. See [LICENSE](LICENSE).
