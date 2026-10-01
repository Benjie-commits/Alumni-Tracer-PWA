# SUN-ATES: Soroti University Alumni Tracking, Engagement and Tracer Study Information System

Implementation of `SUN-ATES_Engineering_Specification.pdf` (v1.0). This repository currently contains
**Phase 1** (alumni directory, self-service registration, staff console), **Phase 2** (tracer-study surveys and
SMS/WhatsApp nudges) and **Phase 3** (outcome dashboards and employer credential verification).

| Folder | What it is | Stack (per spec section 8) |
|---|---|---|
| `backend/` | REST API for the alumni app **and** the staff console | Laravel 13, PHP 8.3, MySQL 8.x, Sanctum, Livewire 4 |
| `pwa/` | Installable alumni app | Vue 3 + Vite, Workbox service worker |
| `scripts/` | `dev.ps1` starts/stops the local stack on Windows | PowerShell |

## What is built

| Spec | Status | Where |
|---|---|---|
| FR-1 Directory and search, filter, export | Done | Console: *Alumni directory* (search, School → Department → Programme, year, status, teaching-assistant candidates, CSV export) |
| FR-2 Alumni self-service and identity check | Done | PWA registration matches student number + surname + graduation year against Registrar records; profile and work history editing |
| FR-3 Automated tracer surveys + TA flag | Done | Surveys at 6 months, 1 year and 3 years, sent by WhatsApp/SMS link; answered in the PWA; strong, available graduates flagged for teaching-assistant consideration |
| FR-7 Data-freshness nudges | Done (consent-based) | Weekly WhatsApp/SMS nudge to alumni who have not confirmed their record for a year. LinkedIn is **not** consulted: spec section 7.4 replaces it with an opt-in flow (Phase 4) |
| FR-4 Outcome dashboards | Done | Console: *Graduate outcomes*: employment, further study and entrepreneurship by School / Department / Programme / year, with export. Small groups are withheld |
| FR-6 Credential verification | Done | `/verify` web page and `POST /api/v1/verification/lookup` for employers and partners; alumni can give an employer a private verification link; "ask the Registrar" escalation |
| FR-8 Admin console, bulk import | Done | Console: CSV import with preview, verification queue, staff accounts, surveys, message log, employer enquiries |
| FR-9 SorotiUniERP hook, LinkedIn opt-in | Not started | Phase 4 (waits on the ERP being active) |
| FR-5 Engagement (events, mentorship, jobs) | **Not scheduled** | Listed as a requirement but the spec's phase plan (section 11) does not assign it to any phase |

## Local setup

Requires **PHP 8.3+** (with `pdo_mysql`, `mbstring`, `intl`, `sodium`, `zip`, `gd`, `fileinfo`), **Composer 2**,
**MySQL 8.x** and **Node 22+**.

```powershell
# 1. Backend
cd backend
composer install
copy .env.example .env          # then set DB_PORT / DB_PASSWORD for your MySQL
php artisan key:generate
php artisan migrate --seed      # schema, the four fixed roles, and the three survey questionnaires
php artisan sunates:create-staff you@soroti.ac.ug --role=ict_admin --name="Your Name"   # prompts for a password

# 2. Alumni PWA
cd ..\pwa
npm install

# 3. Run everything (Windows)
powershell -ExecutionPolicy Bypass -File scripts\dev.ps1 start
#   Staff console  http://127.0.0.1:8000/admin
#   Alumni PWA     http://127.0.0.1:5173   (Vite proxies /api and /u to :8000)
```

`dev.ps1` runs MySQL, Laravel, Vite, **a queue worker and the scheduler**. Surveys and nudges are queued jobs, so
without a worker they would sit unsent. On other platforms run `php artisan serve`, `php artisan queue:work`,
`php artisan schedule:work` and `npm run dev` yourself.

Optional fake data for local development (refuses to run outside `local`/`testing`; every school is prefixed `DEMO`):

```powershell
php artisan db:seed --class=DemoDataSeeder
```

`dev.ps1` expects portable PHP and MySQL under `%USERPROFILE%\tools` (`php\`, `mysql-8.4.11-winx64\`, `mysql-8.4.ini`,
MySQL on port **3307** so it can sit beside another MySQL). Pass `-Tools <folder>` to point elsewhere. Logs go to
`%TEMP%\sunates-dev\`.

### Tests

```powershell
cd backend ; php artisan test     # 435 tests; runs against MySQL database `sunates_testing`
cd pwa     ; npm test             # 46 tests (Node's built-in runner)
cd pwa     ; npm run build        # production bundle in pwa\dist
```

Create the `sunates_testing` database and grant your DB user access first; `phpunit.xml` forces that database name
so `RefreshDatabase` can never touch development data. Tests use fake SMS/WhatsApp gateways and a frozen clock: nothing
is ever sent.

## Registration, verification and import (Phase 1)

**Registration (FR-2).** Registrar records are imported as `unclaimed` profiles. When an alumnus registers, student
number, surname and graduation year must all match one unclaimed record to be verified straight away (case and extra
spaces ignored). Anything else becomes a self-declared claim in `pending` status, shown as "waiting for the Registrar",
and appears in the console's **Verification queue**, where staff *link* it to the right record (e.g. a misspelt
surname), *approve it as new*, or *reject* it. A failed match never reveals which records exist.

**Bulk import (FR-8).** *Import from spreadsheet* takes a CSV export. Required columns: Student number, First name,
Surname. Optional: Other names, Gender, School, Department, Programme, Graduation year/date (dd/mm/yyyy or
yyyy-mm-dd), Class of award, Date of birth, Email, Phone. Headings are matched loosely ("Reg No", "Faculty",
"Course"). *Preview* runs the whole import in a transaction and rolls it back, so the report is exactly what committing
would do. Rows are matched on student number, so re-importing is safe: Registrar-owned fields are refreshed,
alumni-entered contact details are never overwritten, and blank cells never erase data.

## Tracer surveys (FR-3, Phase 2)

**The cycle.** Every morning (09:00 Uganda time) `php artisan sunates:surveys` runs:

1. closes invitations whose window has passed;
2. invites everyone whose **6-month, 1-year or 3-year** milestone has arrived (graduation date + 6/12/36 months; if the
   Registrar only has a year, the end of that year is assumed so nobody is surveyed early);
3. sends reminders (day 7 and day 21 after the first message) to those who have not answered.

It is safe to run repeatedly: there is one invitation per alumnus per milestone, so nobody is messaged twice. An invitation
stays open for **90 days** after its milestone. **Only invitations whose window is still open are created**, so switching the
system on does not message everyone who graduated years ago (there is deliberately no back-fill; see *To confirm*). Use
`php artisan sunates:surveys --dry-run` to see exactly who would be invited before going live; the console's **Tracer
surveys** page shows the same counts plus who reaches each milestone in the next 30 days.

**The message.** WhatsApp first, falling back to SMS, with a link `…/s/<private token>`. Opening it works on any phone
with no sign-in; the token is the credential, shows only the person's first name, and is rate-limited. Registered
alumni also see waiting surveys on their profile. A message that cannot be sent (no number, opted out, provider refuses)
never blocks the survey: it stays open in the app and the failure is listed under **Messages** in the console.

**The form.** Questions appear or disappear as answers change (e.g. employer questions only for people who work).
Submissions are **idempotent**: the phone picks a submission id first, so a retry after a dropped connection is
recognised instead of duplicated. If the connection is lost, answers are **queued on the phone and sent automatically**
when the app opens, when the connection returns, or when the app comes back to the foreground, so iPhones (which cannot
retry in the background) are covered. Confirmed answers are deleted from the phone at once.

**Questionnaires** live in `backend/config/tracer_surveys.php`. They are a **starting point, not the official NCHE
instrument** (the spec does not include it). Have the QA Directorate compare them with NCHE's guidance, edit the file,
then run `php artisan sunates:sync-surveys`. Each change creates a new immutable version; answers already collected stay
attached to the exact wording they were given for, and the console shows and exports them that way.

**What an answer does.** It is stored, and also updates the alumnus's employment and further-study status on their
profile and counts as them confirming their record (so they are not nudged). **Teaching-assistant flag:** a graduate whose
class of award is *First Class* or *Second Class Upper* (`ta_eligible_classes` in `config/sunates.php`) who answers yes
to the TA question is flagged; staff can filter the directory by it. Individual answers and the CSV export (one column
per question, for NCHE reporting) are visible to Registrar and ICT staff only; QA/Dean viewers see response *rates*.

## Messages and nudges (Phase 2)

All messages go through one service (`NotificationService`): it works out which channels a person can be reached on,
respects opt-outs, tries WhatsApp then SMS, and logs every attempt (channel, template, status). The message text is
deliberately **not** stored, because survey messages contain a private link.

- **Quiet hours:** nothing is sent 19:00 to 08:00 Uganda time; queued messages wait for the morning.
- **Stopping messages:** every SMS ends with a link to a "stop" page (opening it changes nothing until the button is
  pressed, because messaging apps pre-fetch links); replying STOP on WhatsApp stops every channel; alumni can switch SMS
  and WhatsApp off in the app; staff can record a request made by phone. SMS and WhatsApp can be stopped independently.
- **Failures:** a temporary provider problem (timeout, rate limit, expired token) retries with back-off and does *not*
  fall through to the other channel, to avoid messaging someone twice; a permanent refusal (not on WhatsApp, bad
  number) moves to the next channel.
- **Nudges (FR-7):** `php artisan sunates:nudges` runs weekly. A record is stale if the alumnus has not edited it or
  answered a survey for **a year**. At most one nudge per quarter and three a year; nothing to anyone who heard from us
  in the last 14 days; a daily cap (default 200) protects the SMS budget; `--dry-run` shows who would be nudged.
- **Delivery receipts:** WhatsApp reports by webhook (signature-checked, receipts that arrive out of order never move a
  message backwards). MTN is polled every 15 minutes (`sunates:sync-delivery-status`).
- **Console → Messages** shows the log, 30-day counts, and (ICT) a **send a test message** form for checking credentials.

### Going live with MTN SMS and WhatsApp

Messaging runs in **log-only mode** (`SMS_DRIVER=log`, `WHATSAPP_DRIVER=log`) until configured: messages are written
to `storage/logs/laravel.log` and nothing is sent. To go live, with the Directorate of ICT:

1. **MTN:** register on developers.mtn.com, subscribe to the SMS API, put the credentials in `backend/.env`
   (`MTN_SMS_CLIENT_ID`, `MTN_SMS_CLIENT_SECRET`, `MTN_SMS_SENDER`; override `MTN_SMS_BASE_URL` / `MTN_SMS_TOKEN_URL` if
   MTN gives sandbox values), set `SMS_DRIVER=mtn`, then use **Messages → Send a test message**.
2. **WhatsApp:** create a Meta Business app with the WhatsApp Cloud API, fill `WHATSAPP_PHONE_NUMBER_ID`,
   `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APP_SECRET`, choose a `WHATSAPP_VERIFY_TOKEN`, and set `WHATSAPP_DRIVER=cloud`.
   Register the webhook `https://<your-domain>/api/webhooks/whatsapp` (subscribe to *messages*).
3. **Create four message templates** in Meta Business Manager (business-initiated messages must use approved templates;
   names are configurable in `config/sunates.php`). Suggested bodies, with `{{n}}` filled in this order:
   `sunates_survey_invite`: "Hi {{1}}, Soroti University would like to hear how you are doing {{2}} after graduating. It
   takes 2 minutes: {{3}}" · `sunates_survey_reminder`: "Hi {{1}}, your Soroti University graduate survey is still open.
   It takes 2 minutes: {{2}}" · `sunates_profile_nudge`: "Hi {{1}}, please confirm your Soroti University alumni details
   are up to date: {{2}}" · `sunates_register_invite`: "Hi {{1}}, join the Soroti University alumni network and keep
   your details current: {{2}}". Add a footer such as "Reply STOP to stop messages". Meta decides each template's category
   and therefore its price; confirm with ICT.
4. Set `SUNATES_PWA_URL` and `APP_URL` to the real https addresses (survey links and the stop link are built from them).

> **Honesty note:** both provider adapters are built to the providers' published API documentation and tested against
> faked HTTP responses. They have **not** been run against live MTN or Meta accounts (that needs the university's
> credentials). Use the test-message form before relying on them; every URL is configurable.

### Production: the two background processes

Surveys, reminders and nudges only happen if both of these are running:

```
# cron, every minute: runs the daily/weekly jobs
* * * * * cd /var/www/sunates/backend && php artisan schedule:run >> /dev/null 2>&1

# Supervisor (or systemd): keeps the queue worker alive
php artisan queue:work --sleep=3 --tries=3 --max-time=3600
```

After each deployment: `php artisan migrate --force && php artisan sunates:sync-surveys`.

## Outcome dashboards (FR-4, Phase 3)

*Graduate outcomes* in the console shows what graduates are doing: **employment** (employed, self-employed, seeking
work, studying full time, other), **further study** (studying now or planning to) and **entrepreneurship** (started a
business), as stat tiles, a stacked bar per group and a full table. Compare by School, Department, Programme or
graduation year; narrow by any of those and by graduation-year range; export exactly what is on screen to CSV. It is open
to every staff role (Deans, QA and Top Management use the QA/Dean viewer role) because it shows aggregates only.

- **Two data sources.** *Tracer surveys* (the rigorous one: pick the 6-month, 1-year or 3-year survey, or each alumnus's
  most recent answer, so people are counted once, not once per survey) or *alumni profiles* (the latest status graduates
  recorded themselves, available from day one before any survey has come back; it has no "started a business" figure).
- **Small groups are withheld.** A group with fewer than **5** respondents (`DASHBOARD_MIN_CELL_SIZE`) is shown as
  "fewer than 5" with no percentages, on screen and in the export, so a table cut finely enough cannot reveal what one
  identifiable graduate said. The same rule hides a whole view that is too small.
- **Response rate** is answered / *closed* surveys (answered + expired); surveys still open are not counted as missed.
  For the profile source the equivalent is "graduate records with a known status".
- Charts use a colour-blind-checked palette (see `public/css/admin.css`); every figure is also in the table and in
  hover/keyboard tooltips, and each bar has a text equivalent for screen readers.

## Credential verification (FR-6, Phase 3)

Employers and partner institutions can check that someone graduated, without contacting the Registrar.

- **The page** is `/verify` (server-rendered, no sign-in, no app). Enter the organisation, the graduate's full name and,
  optionally, programme and year. **Partners** can call `POST /api/v1/verification/lookup` with the same fields.
- **What comes back** is `verified` / `not_found` / `ambiguous`, and for a verified graduate **programme and graduation
  year only**: never a student number, contact details, class of award or school (spec section 9).
- **Only Registrar-held records can be verified:** a record with a student number whose graduation has happened. A claim
  someone typed in themselves, even one staff approved as new, is never confirmed.
- **It never lists candidates.** If a name fits several graduates the answer is "ambiguous" and describes none of them;
  adding programme and year usually settles it. Names match in any order, ignoring case, accents, hyphens and
  apostrophes, as whole words ("Amina" does not match "Aminata"). A single name or initials are refused as too vague.
- **Not found or unclear?** The page offers "Ask the Registrar's office to check". The enquiry (organisation, contact
  details, what was asked) appears under **Employer enquiries** in the console for Registrar/ICT staff, who reply from
  their own email and mark it dealt with.
- **Alumni can give employers a link.** In the app, a verified graduate creates a private link (`/verify/<token>`,
  90 days, up to 5 at a time, withdrawable). The employer sees their name, programme and year confirmed. The alumnus
  sees how often each was opened. Links stop working if the graduate can no longer be confirmed.
- **Abuse limits:** the lookup is unauthenticated, so it is limited to 20 per minute and 200 per day per address,
  enquiries to 5 per hour, and enquiries carry a hidden bot trap. Every lookup is logged, including ones that find nobody.
- **The log is kept apart from profile data** (spec section 9): `credential_verification_requests` holds only who asked,
  what they typed and what we answered, with no profile data and no foreign keys, so it can be moved to its own database
  by setting `VERIFICATION_DB_CONNECTION` to a second connection in `config/database.php`. It is deleted after 730 days
  (`sunates:prune-verification-data`, monthly), along with resolved enquiries and long-dead links; unresolved enquiries
  are never pruned.
- **Production routing:** `/verify` and `/u` are served by Laravel, not the PWA. Route them (with `/api`, `/admin` and
  `/livewire`) to PHP-FPM in Nginx, and everything else to the PWA's `index.html`.

## Access (spec section 9)

| | Alumni PWA / API | Directory (names, programme, outcomes) | Contact details, editing | Verification queue, import, **individual survey answers, message log, employer enquiries** | Outcome dashboards, surveys overview | Staff accounts, test messages |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Alumni | own record only | | | | | |
| Registrar | | yes | yes | yes | yes | |
| ICT admin | | yes | yes | yes | yes | yes |
| QA / Dean viewer | | yes (read-only) | **no** | **no** | yes | |
| Employer / partner (no account) | | | | | | `/verify` only |

Alumni use the API with bearer tokens (30 days, one per device). Staff use session sign-in to the console; staff cannot
use the alumni API and alumni cannot enter the console. QA/Dean viewers deliberately receive no personal contact details
(not in pages, search, exports or the browser payload). Confirm this with the Registrar and QA Directorate.

### API (`/api/v1`)

Public: `GET reference/programmes`, `GET reference/options`, `POST auth/register` (5/min/IP), `POST auth/login`
(5/min per email+IP), `GET surveys/{token}` and `POST surveys/{token}/responses` (30/min/IP; the token is the credential).
Alumni (bearer token): `POST auth/logout`, `GET me`, `GET|PUT me/profile`, `GET|POST me/employment-records`,
`PUT|DELETE me/employment-records/{id}`, `GET me/surveys`, `GET|PUT me/notification-preferences`.
Employer verification (no sign-in, rate-limited): `POST verification/lookup`. Alumni also: `GET|POST me/credential-links`, `DELETE me/credential-links/{id}`.
Provider callbacks: `GET|POST /api/webhooks/whatsapp` (verify token + `X-Hub-Signature-256`). Server-rendered:
`GET|POST /u/{token}` ("stop messaging me"), `GET|POST /verify`, `POST /verify/enquiry`, `GET /verify/{token}`.

## Design decisions worth knowing

- **Freshness date.** `alumni_profiles.profile_updated_at` means "the alumnus themselves confirmed this" (a profile
  edit or a survey answer). Staff edits do not move it. The nudge engine depends on that.
- **Nothing personal is cached on the phone.** The service worker caches the app shell and the public reference lists
  only; the token is the only thing kept in `localStorage`, plus survey answers that are waiting to send (deleted once
  confirmed). Alumni often use shared phones. The browser end-to-end runs check this.
- **App-level offline queue, not Workbox Background Sync.** It works identically on Android and iOS and avoids storing
  credentials in a service-worker queue.
- **Export safety.** CSV cells beginning `= + - @` are prefixed with `'` so spreadsheet formulas typed by alumni cannot run
  when staff open an export (real phone numbers are left alone).
- **CORS** is limited to `CORS_ALLOWED_ORIGINS`. In production, serve the PWA from the same origin as the API.
- **No default credentials anywhere.** Staff accounts come from `sunates:create-staff` or the console.

## To confirm with the Registrar / QA Directorate / ICT

- **The questionnaires** are a draft, not the NCHE instrument (see above).
- **Contacting alumni who have not registered.** By default they are neither surveyed nor nudged
  (`SURVEY_INCLUDE_UNCLAIMED`, `NUDGES_INCLUDE_UNCLAIMED`), because they have not been through the consent step. Most of
  the alumni base starts in this state, so this decides how many people the first survey reaches. Decide it as part of
  the data-protection review (spec section 4).
- **No back-fill.** Graduates whose 6-month/1-year/3-year window closed before launch are never surveyed. A one-off
  survey for them is possible but is a separate decision.
- **Timing and limits:** 90-day window, reminders at day 7 and 21, quiet hours 19:00 to 08:00, nudges after a year of
  silence, one per quarter, three per year, daily cap 200. All in `config/sunates.php`.
- **Teaching-assistant criteria:** First Class / Second Class Upper plus a yes. Confirm the class-of-award wording the
  Registrar actually uses.
- **Survey answers update the profile** (employment and further-study status) and count as confirming the record.
- **WhatsApp first, then SMS.** Reverse the order in `channel_priority` if SMS is cheaper or more reliable.

## To confirm for Phase 3

- **No opt-out from employer verification.** Graduation is a Registrar fact, so any employer can confirm it from a name
  (without ever seeing more than programme and year). Alumni can choose *not* to hand out links, but cannot stop a
  lookup. Confirm this with the data-protection review; adding an opt-out is small.
- **Employers are not identified beyond what they type.** There is no sign-in, email confirmation or CAPTCHA, only rate
  limits and the log. If misuse appears, ICT can add a CAPTCHA to the page or issue API keys to partner institutions.
- **Verification log retention (730 days)** and where it lives (same database unless `VERIFICATION_DB_CONNECTION` is set).
- **Dashboard small-group threshold (5)** and the **profile-status data source**, which is broader but less rigorous than
  the surveys.
- **Outcome definitions:** "in work" = employed + self-employed; "further study" = studying now or planning to;
  "started a business" = the survey's yes/no question.

## Open items

- **FR-5 Engagement (event postings, mentorship matching, job/internship postings) is in the spec's requirements but in
  none of its four phases.** It needs a decision on when and with whom to build it.

- Spreadsheet import is CSV only; add `.xlsx` if the Registrar's files cannot easily be saved as CSV UTF-8.
- Production hosting, domain, TLS and backups (spec section 10) are still to be confirmed with ICT.
- The data-protection review should complete before alumni data is collected at scale. The consent wording on the
  registration screen is a draft for that review.
- Reference-data screens (rename or merge schools, departments, programmes) are not built; the import creates them.
- The PWA has no password reset yet (needs an email or SMS channel decision).
- Surveys are in English only; Luganda/Ateso translations would need per-language questionnaire versions.
