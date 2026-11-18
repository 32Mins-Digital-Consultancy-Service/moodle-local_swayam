# local_swayam — Installation Guide
**Version:** 1.1.0  
**Moodle:** 4.0+  
**Prepared by:** 32Mins LMS Team

See also [README.md](README.md).

---

## Prerequisites

Before installing the plugin, complete all steps in this section.

### 1. Get from Swayam Plus Team
Contact the Swayam Plus team and request:

| Item | Purpose |
|---|---|
| OIDC Client ID | SSO login button |
| OIDC Client Secret | SSO login authentication |
| M2M Client ID | Server-to-server API calls |
| M2M Client Secret | Server-to-server API authentication |
| Redirect URI whitelisted | `https://your-moodle-domain/admin/oauth2callback.php` |
| partnerCourseId set | For each shared course |

### 2. Enable Site-wide Completion Tracking
```
Site Admin → Advanced features → Enable completion tracking → YES
```

### 3. Create Custom Profile Field
```
Site Admin → Users → User profile fields → Create new field
```

| Field | Value |
|---|---|
| Type | Text input |
| Short name | `swayamsub` |
| Name | Swayam Plus ID |
| **Locked** | **Yes (required)** |
| **Visible** | **Not visible** |

> **Locked = Yes is required.** The plugin uses this field to identify the
> Swayam Plus learner. An unlocked field can be edited by the learner, so the
> plugin ignores it and logs `SUB_FIELD_NOT_LOCKED`. The field is trusted only
> for users who signed in through the Swayam Plus OAuth 2 service. Upgrading to
> 1.1.0 locks and hides an existing field automatically.

### 4. Configure OAuth2 Issuer
```
Site Admin → Server → OAuth 2 services → Add Custom
```

| Field | Value |
|---|---|
| Name | `Swayam Plus` |
| Client ID | OIDC Client ID from Swayam Plus team |
| Client secret | OIDC Client Secret from Swayam Plus team |
| Authenticate via HTTP headers | ✅ YES |
| Service base URL | `https://swayamplus.education.gov.in/oidc` |
| This service will be used | `Login page and internal services` |
| Scopes | `openid profile email` |

### 5. Configure Endpoints
After saving the issuer, click **Configure endpoints** and add:

| Name | URL |
|---|---|
| `authorization_endpoint` | `https://swayamplus.education.gov.in/oidc/auth` |
| `token_endpoint` | `https://swayamplus.education.gov.in/oidc/token` |
| `userinfo_endpoint` | `https://swayamplus.education.gov.in/oidc/me` |
| `end_session_endpoint` | `https://swayamplus.education.gov.in/oidc/session/end` |
| `discovery_endpoint` | `https://swayamplus.education.gov.in/oidc/.well-known/openid-configuration` |

### 6. Configure Field Mappings
Click **Configure user fields** on the issuer:

| External field | Internal Moodle field |
|---|---|
| `sub` | `profile_field_swayamsub` (required) |
| `email` | Email address |
| `given_name` | First name |
| `family_name` | Last name |

### 7. Enable OAuth2 Authentication
```
Site Admin → Plugins → Authentication → Manage authentication
```
- Enable **OAuth 2** (click the eye icon)
- Prevent account creation: **NO**
- Require email verification: **NO**

---

## Plugin Installation

### Step 1 — Copy Files
Install the ZIP from Site Admin → Plugins → Install plugins, or extract it into
your Moodle installation (the ZIP's top folder is `swayam`):

```
/path/to/moodle/local/swayam/
```

### Step 2 — Run Moodle Upgrade
```
Site Admin → Notifications → Upgrade database
```
Moodle detects the plugin and creates its tables automatically.

### Step 3 — Configure the Plugin
```
Site Admin → Plugins → Local plugins → Swayam Plus Integration
```

| Setting | Value |
|---|---|
| API base URL | `https://swayamplus.education.gov.in` |
| API resource | `https://swayamplus.education.gov.in/api` |
| M2M Client ID | M2M Client ID from Swayam Plus team |
| M2M Client Secret | M2M Client Secret from Swayam Plus team |
| Swayam Plus ID profile field | `swayamsub` |
| Keep log entries for | 30 days (default) |

### Step 4 — Purge Caches
```
Site Admin → Development → Purge all caches
```

---

## Course Setup

For each course to be integrated with Swayam Plus:

### Moodle Side
1. Go to the course → **Edit settings**
2. Set **Course ID number** to a unique value (e.g. `4`)
3. Set **Completion tracking → Enable: YES**
4. Save

For each activity in the course:
- Edit the activity → **Activity completion** → set a condition

| Activity type | Recommended condition |
|---|---|
| Page / URL | Student must view this activity |
| Quiz | Student must receive a passing grade |
| Assignment | Student must receive a grade |

### Swayam Plus Side
Ask the Swayam Plus team to set `partnerCourseId` on the course to match the Moodle Course ID number.

---

## Viewing Logs

Site Admin → Plugins → Local plugins → **Swayam Plus integration log**
(`/local/swayam/view_logs.php`).

From 1.1.0 the log is stored in the `local_swayam_log` table (IDs and status
codes only) and entries are deleted after the retention period. The old file
`moodledata/local_swayam/swayam.log` is no longer written; delete it once you
no longer need it.

---

## Testing

### Test Token (Windows CMD)
```cmd
curl -k -s -u "M2M_CLIENT_ID:M2M_CLIENT_SECRET" ^
  -d "grant_type=client_credentials&scope=partner.enrollments:read partner.completions:write&resource=https://swayamplus.education.gov.in/api" ^
  https://swayamplus.education.gov.in/oidc/token
```
Expected: JSON with `access_token`

### Test Progress Push (PowerShell)
```powershell
$token = (curl -k -s -u "CLIENT_ID:SECRET" `
  -d "grant_type=client_credentials&scope=partner.enrollments:read partner.completions:write&resource=https://swayamplus.education.gov.in/api" `
  https://swayamplus.education.gov.in/oidc/token | ConvertFrom-Json).access_token

curl -k -s -w "`nHTTP:%{http_code}" -X POST `
  -H "Authorization: Bearer $token" `
  -H "Content-Type: application/json" `
  -d '{"percent":50}' `
  "https://swayamplus.education.gov.in/api/v1/partner/enrollments/ENROLLMENT_ID/progress"
```
Expected: HTTP 204

---

## Important Notes

> **Progress tracking requires IN_PROGRESS status.**  
> The student must click **"Continue on IITM"** on their Swayam Plus dashboard first.  
> This moves the enrollment from `ACCESS_PROVISIONED` to `IN_PROGRESS`.  
> The plugin will not push progress until this happens.

> **Status updates automatically on each login.**  
> Once the student clicks "Continue on IITM" and logs back in via Swayam Plus SSO,  
> the plugin updates the status to `IN_PROGRESS` and progress tracking activates.

> **Cron must run.** From 1.1.0 the enrolment sync after sign-in and the
> progress / completion updates run as ad-hoc tasks. The launch page
> (`launch.php`) still enrols the learner immediately before opening the course.

---

## Troubleshooting

| Problem | Log entry | Fix |
|---|---|---|
| Not enrolled after login | `LOGIN_NO_ENROLLMENTS` | Ask Swayam Plus team to set `partnerCourseId` |
| Course not found | `LOGIN_COURSE_NOT_FOUND` | Set Course ID number in Moodle course settings |
| Progress not pushing | `MODULE_SKIP_NOT_IN_PROGRESS` | Student must click "Continue on IITM" on Swayam Plus |
| Progress API rejected | `PROGRESS_PUSH_FAILED HTTP 409` | Same as above — enrollment still PROVISIONING |
| No completion events | No `COURSE_COMPLETED_EVENT` | Set course completion criteria in Moodle |
| Token fails | `TOKEN_MINT_FAILED` | Check M2M credentials in plugin settings |
| Not enrolled, profile field ignored | `SUB_FIELD_NOT_LOCKED` | Set Locked = Yes on the profile field |
| API calls fail with a blocked URL | `API_GET_FAILED` / `TOKEN_MINT_FAILED` error mentions blocked | Remove the Swayam Plus host from the cURL blocked hosts list (Site Admin → General → Security → HTTP security) |

---

*local_swayam — 32Mins LMS Team*

---

## Upgrading to v1.1.0 (from v1.0.3)

Changes made for the Moodle plugins directory review:

- **Security: Swayam Plus identity.** The learner's Swayam Plus ID is taken from
  the verified OAuth 2 linked login. The profile field is used only when it is
  locked and only for users who signed in with Swayam Plus. The upgrade locks
  and hides the existing `swayamsub` field.
- **Security: launch.php.** Finds the Swayam Plus OAuth 2 service by base URL
  (no hard-coded id). Visitors sent from the Swayam Plus host go straight to
  sign-in; visitors sent from anywhere else see a "Sign in with Swayam Plus"
  button first (login CSRF protection). After sign-in the learner comes back to
  `launch.php`, which enrols them and opens the course.
- **Security:** outbound calls respect the site's HTTP security settings
  (no `ignoresecurity`); clearing the log needs confirmation and a sesskey.
- **Observers are lightweight.** Sign-in sync and progress / completion pushes
  run as ad-hoc tasks (cron must run).
- **Log** moved from `moodledata/local_swayam/swayam.log` to the
  `local_swayam_log` table with a retention period (default 30 days); no
  response bodies or grades are logged. The log page is in the admin tree.
- **Privacy API** implemented (export and delete).
- LICENSE, README, file headers and language strings added.

Database: one new table (`local_swayam_log`). No mapping data is changed.
The ZIP's top folder is now `swayam`.

---

## Upgrading to v1.0.3 (from v1.0.2)

### What changed
- **Fix: completion never reached Swayam Plus (portal stuck at e.g. 98%).**
  At 100% v1.0.2 called `completion_info::mark_course_completions()`, which
  does not exist in Moodle. The observer crashed, so `/completion` was never
  sent. v1.0.3 sends `/completion` directly at 100%.
- Progress now uses Moodle core's own calculation
  (`\core_completion\progress::get_course_progress_percentage`), so the
  percentage pushed matches the progress bar students see in Moodle.
- `floor()` instead of `round()` — 100% is only reported when truly complete.
- The 6-hourly scheduled task now **retries completions** that failed or were
  missed (v1.0.2 skipped every learner at 100%).
- Completion is sent once per enrollment (new `completionsent` flag), even if
  several triggers fire.
- Observer errors are caught and logged (`OBSERVER_EXCEPTION`) instead of
  breaking the student's page.
- New CLI: `cli/resend_completion.php` to find and fix stuck learners.
- The plugin no longer writes to Moodle course completion data at all.
- **New: webhook receiver** (`webhook.php`). Swayam Plus tells Moodle when a
  learner's access starts or ends, so refunded / expired / removed learners
  lose access without waiting for a login. See "Webhook setup" below.

### Database change (additive only)
Three columns are added to `mdl_local_swayam_enrolmap`, all with default 0:
`completionsent`, `timecompletionsent`, `completionattempts`.
The `status` column is also added if it is missing (sites first installed
before v1.0.2 never received it).
Two new tables are created for the webhook:
`mdl_local_swayam_entitle` (entitlements + last applied event sequence) and
`mdl_local_swayam_webhook` (processed delivery IDs, purged after 30 days).
No table, column or row is dropped or modified. Moodle core tables are not touched.

### Upgrade steps
1. Ask the DB team for a backup of the Moodle database
   (at minimum `mdl_local_swayam_enrolmap`) before upgrading.
2. Test on staging first if available.
3. Replace the `local/swayam` folder with the new one
   (or Site admin → Plugins → Install plugins → upload ZIP).
   **Do NOT uninstall the old plugin first** — uninstall drops the enrolmap table.
4. Visit Site admin → Notifications and run the upgrade.
5. Check stuck learners (dry run), then send:
   ```
   php local/swayam/cli/resend_completion.php
   php local/swayam/cli/resend_completion.php --execute
   ```
   (Or wait for the next scheduled-task run — it does the same automatically.)
6. Check `local/swayam/view_logs.php` for `COMPLETION_POSTED` / `COMPLETION_FAILED`.

### Rollback
Restore the v1.0.2 folder. Moodle will refuse a version downgrade, so
rollback = restore the code **and** the DB backup from step 1. The extra
columns and tables are harmless if left in place.

---

## Webhook setup (v1.0.3)

Swayam Plus sends an HTTPS POST to Moodle when an enrollment changes.

### Requirements
- Moodle reachable on the public internet over **HTTPS, at a host name** (not an IP).
- The server clock must be correct (NTP) — signatures older than 5 minutes are rejected.
- No redirect on the receiver URL (Swayam Plus treats a 3xx as a failure).

### Steps
1. On the Swayam Plus **Integration** page → **Webhook** → **Add webhook**.
   Receiver URL:
   ```
   https://your-moodle-domain/local/swayam/webhook.php
   ```
   Events: **Access granted**, **Access ended** (and **Completed** if wanted).
2. Copy the signing secret (`whsec_...`) — it is shown **once**.
3. In Moodle: Site Admin → Plugins → Local plugins → Swayam Plus Integration
   - **Webhook signing secret** → paste the secret
   - **When access is revoked** → *Suspend* (default, keeps progress and grades) or *Unenrol*
4. Back on Swayam Plus select **Verify receiver**. All four checks should pass
   and the webhook shows *Receiver verified*.
5. Check `local/swayam/view_logs.php` for `WEBHOOK_PING` / `WEBHOOK_EVENT`.

### What each event does

| Event | Plugin action |
|---|---|
| `ping` | Answers 200, nothing else |
| `enrollment.activated` | Stores an entitlement. Learner already has a Moodle account → enrolled immediately. No account yet → enrolled at first Swayam Plus sign-in |
| `enrollment.revoked` | Enrolment suspended (or unenrolled), mapping set to `TERMINATED`, progress pushes stop. Lifted automatically if access is granted again |
| `enrollment.completed` | Mapping set to `COMPLETED`; the plugin stops sending `/completion`. Moodle completion data is not written |

- Duplicate deliveries are detected by delivery ID and answered 200.
- Out-of-order events are ignored using the `sequence` number per enrollment.
- Events for enrollments Moodle does not know are answered 200 with no action.
- **`account_erased`**: access is removed and `WEBHOOK_ACCOUNT_ERASED_ACTION_REQUIRED`
  is logged. The plugin never deletes a Moodle user — an admin must delete the
  learner's personal data (Site Admin → Users → Privacy → Data requests).
- A learner suspended manually by an admin is **not** re-activated by the plugin;
  only suspensions caused by a revocation are lifted.

### HTTP responses

| Code | Meaning |
|---|---|
| 200 | Applied, duplicate, out of order, ping, or unknown enrollment |
| 400 | Body is not a usable JSON event |
| 401 | Signature missing / wrong / older than 5 minutes, or signing secret not set |
| 500 | Processing failed — Swayam Plus retries |

### Getting a new secret
Select **Get a new secret** on Swayam Plus, then paste it into the plugin
setting. For 24 hours requests are signed with both secrets, so there is no
downtime while you switch.

### Send a signed test request yourself (bash)
```bash
SECRET='whsec_...'; BODY='{"id":"manual-test-1","type":"ping","sequence":0,"data":{}}'; T=$(date +%s)
SIG=$(printf '%s.%s' "$T" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -i -X POST https://your-moodle-domain/local/swayam/webhook.php \
  -H 'Content-Type: application/json' \
  -H "X-Swayam-Event: ping" -H "X-Swayam-Delivery: manual-test-1" \
  -H "X-Swayam-Signature: t=$T,v1=$SIG" --data-raw "$BODY"
```
Expected: `HTTP 200` and `{"status":"ping"}`. Change one character of `SIG` → `HTTP 401`.

### Webhook troubleshooting

| Log entry | Fix |
|---|---|
| `WEBHOOK_NOT_CONFIGURED` | Set the Webhook signing secret in the plugin settings |
| `WEBHOOK_SIGNATURE_INVALID` | Secret does not match Swayam Plus, or the server clock is off |
| `WEBHOOK_COURSE_NOT_FOUND` | Set the Moodle Course ID number to the `partnerCourseId` |
| `WEBHOOK_ENTITLEMENT_STORED` | Normal — learner has not signed in yet |
| `WEBHOOK_EXCEPTION` | Processing failed; Swayam Plus retries. Check the `error` field |
| Nothing logged at all | Request never reached Moodle — check firewall / WAF / HTTPS / redirects |
