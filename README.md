# SUN-ATES: Soroti University Alumni Tracking, Engagement and Tracer Study Information System

Implementation of `SUN-ATES_Engineering_Specification.pdf` (v1.0). This repository currently contains
**Phase 1**: the alumni directory, alumni self-service registration and the staff console.

| Folder | What it is | Stack (per spec section 8) |
|---|---|---|
| `backend/` | REST API for the alumni app **and** the staff console | Laravel 13, PHP 8.3, MySQL 8.x, Sanctum, Livewire 4 |
| `pwa/` | Installable alumni app | Vue 3 + Vite, Workbox service worker |
| `scripts/` | `dev.ps1` starts/stops the local stack on Windows | PowerShell |

## What Phase 1 covers

| Spec | Status | Where |
|---|---|---|
| FR-1 Directory and search, filter, export | Done | Staff console: *Alumni directory* (search, School → Department → Programme, year, status, CSV export) |
| FR-2 Alumni self-service and identity check | Done | PWA registration matches student number + surname + graduation year against Registrar records; profile and work history editing |
| FR-8 Admin console, bulk import | Done | Console: CSV import with preview, verification queue, staff accounts |
| FR-3, 4, 5, 6, 7, 9 | Not started | Phases 2 to 4 (surveys/nudges, dashboards, engagement, verification lookup, ERP hook, LinkedIn opt-in) |

## Local setup

Requires **PHP 8.3+** (with `pdo_mysql`, `mbstring`, `intl`, `sodium`, `zip`, `gd`, `fileinfo`), **Composer 2**,
**MySQL 8.x** and **Node 22+**.

```powershell
# 1. Backend
cd backend
composer install
copy .env.example .env          # then set DB_PORT / DB_PASSWORD for your MySQL
php artisan key:generate
php artisan migrate --seed      # creates the schema and the four fixed roles
php artisan sunates:create-staff you@soroti.ac.ug --role=ict_admin --name="Your Name"   # prompts for a password

# 2. Alumni PWA
cd ..\pwa
npm install

# 3. Run everything (Windows)
powershell -ExecutionPolicy Bypass -File scripts\dev.ps1 start
#   Staff console  http://127.0.0.1:8000/admin
#   Alumni PWA     http://127.0.0.1:5173   (Vite proxies /api to :8000)
```

Optional fake data for local development (refuses to run outside `local`/`testing`; every school is prefixed `DEMO`):

```powershell
php artisan db:seed --class=DemoDataSeeder
```

`dev.ps1` expects portable PHP and MySQL under `%USERPROFILE%\tools` (`php\`, `mysql-8.4.11-winx64\`, `mysql-8.4.ini`,
MySQL on port **3307** so it can sit beside another MySQL). Pass `-Tools <folder>` to point elsewhere. Logs go to
`%TEMP%\sunates-dev\`. On other platforms just run `php artisan serve` and `npm run dev` yourself.

### Tests

```powershell
cd backend ; php artisan test     # 83 tests; runs against MySQL database `sunates_testing`
cd pwa     ; npm test             # 15 tests (Node's built-in runner)
cd pwa     ; npm run build        # production bundle in pwa\dist
```

Create the `sunates_testing` database and grant your DB user access first; `phpunit.xml` forces that database name
so `RefreshDatabase` can never touch development data.

## How it works

**Registration and verification (FR-2).** Registrar records are imported as `unclaimed` profiles. When an alumnus
registers, student number, surname and graduation year must all match one unclaimed record to be verified straight
away (matching ignores case and extra spaces). Anything else is accepted as a self-declared claim in `pending`
status, shown to the alumnus as "waiting for the Registrar", and appears in the console's **Verification queue**,
where staff *link* it to the right record (e.g. a misspelt surname), *approve it as new*, or *reject* it. A failed
match never reveals which records exist.

**Bulk import (FR-8).** *Import from spreadsheet* takes a CSV export. Required columns: Student number, First name,
Surname. Optional: Other names, Gender, School, Department, Programme, Graduation year/date (dd/mm/yyyy or
yyyy-mm-dd), Class of award, Date of birth, Email, Phone. Headings are matched loosely ("Reg No", "Faculty",
"Course"). *Preview* runs the full import inside a transaction and rolls it back, so the report is exactly what
committing would do. Rows are matched on student number, so re-importing is safe: Registrar-owned fields are refreshed,
alumni-entered contact details are never overwritten, and blank cells never erase data. Schools, departments and
programmes are created on the fly. Bad rows are skipped with a row number and reason.

**Access (spec section 9).**

| | Alumni PWA / API | Directory (names, programme, outcomes) | Contact details, export of them, editing | Verification queue, import | Staff accounts |
|---|:-:|:-:|:-:|:-:|:-:|
| Alumni | own record only | | | | |
| Registrar | | yes | yes | yes | |
| ICT admin | | yes | yes | yes | yes |
| QA / Dean viewer | | yes (read-only) | **no** | | |

Alumni use the API with bearer tokens (30 days, one per device). Staff use session sign-in to the console; staff
cannot use the alumni API and alumni cannot enter the console. QA/Dean viewers deliberately receive no personal
contact details (not in pages, search, exports or the browser payload). Confirm this with the Registrar and QA
Directorate; relaxing it is a small change in `AlumniDirectory`, `AlumniDetail` and `AlumniExportController`.

### API (`/api/v1`)

Public: `GET reference/programmes`, `GET reference/options`, `POST auth/register` (5/min/IP), `POST auth/login`
(5/min per email+IP). Alumni (bearer token): `POST auth/logout`, `GET me`, `GET|PUT me/profile`,
`GET|POST me/employment-records`, `PUT|DELETE me/employment-records/{id}`. Alumni can change only contact,
location and outcome fields; the academic record is Registrar-owned.

## Design decisions worth knowing

- **Freshness date.** `alumni_profiles.profile_updated_at` means "the alumnus themselves confirmed this". Staff edits do
  not move it. Phase 2's data-freshness nudges (FR-7) depend on that.
- **Nothing personal is cached on the phone.** The service worker caches the app shell and the public reference lists
  only; the token is the only thing in `localStorage`. Alumni often use shared phones. This is checked by the
  browser end-to-end run.
- **Export safety.** Alumni type their own names and employers, so CSV cells beginning `= + - @` are prefixed with `'`
  to stop spreadsheet formulas running when staff open an export (real phone numbers are left alone).
- **CORS** is limited to `CORS_ALLOWED_ORIGINS` (default: the Vite dev server). In production, serve the PWA from the
  same origin as the API and no CORS is needed.
- **No default credentials anywhere.** Staff accounts come from `sunates:create-staff` or the console.

## Open items for the Registrar / ICT (spec section 14)

- Spreadsheet import is CSV only; add `.xlsx` if the Registrar's files cannot easily be saved as CSV UTF-8.
- Production hosting, domain, TLS and backup schedule (spec section 10) are still to be confirmed with ICT; SMS (MTN) and
  WhatsApp (Cloud API) credentials are not needed until Phase 2.
- The data-protection review (spec section 4) should complete before alumni data is collected at scale. The consent
  wording on the registration screen is a draft for that review.
- Reference-data screens (rename or merge schools, departments, programmes) are not built; the import creates them.
- The PWA has no password reset yet (needs an email or SMS channel decision).
