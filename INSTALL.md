# local_swayam — Installation Guide
**Version:** 1.0.0  
**Moodle:** 4.0+  
**Prepared by:** 32Mins LMS Team

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
| Name | Swayam Sub |

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
Copy the `local_swayam` folder into your Moodle installation:

```
/path/to/moodle/local/swayam/
```

The folder structure must be:
```
local/swayam/
├── version.php
├── settings.php
├── view_logs.php
├── INSTALL.md
├── lang/
│   └── en/
│       └── local_swayam.php
├── classes/
│   ├── observer.php
│   ├── logger.php
│   └── task/
│       └── push_progress.php
└── db/
    ├── install.xml
    ├── events.php
    ├── caches.php
    └── tasks.php
```

### Step 2 — Run Moodle Upgrade
```
Site Admin → Notifications → Upgrade database
```
Moodle detects the plugin and creates the `mdl_local_swayam_enrolmap` table automatically.

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
| Sub field shortname | `swayamsub` |

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

Log in as admin and open:
```
http://your-moodle-domain/local/swayam/view_logs.php
```

Or read the raw log file at:
```
moodledata/local_swayam/swayam.log
```

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

---

*local_swayam v1.0.0 — 32Mins LMS Team — June 2026*

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

### Database change (additive only)
Three columns are added to `mdl_local_swayam_enrolmap`, all with default 0:
`completionsent`, `timecompletionsent`, `completionattempts`.
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
columns are harmless if left in place.
