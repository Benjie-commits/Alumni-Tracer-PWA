# SUN-ATES: compliance with the engineering specification (v1.0)

Status as of 17 Oct 2026, after the SRS-alignment work on branch `setup/php-composer-environment`.

**How to read "Verified".** On the development machine used for this work, the MySQL test database
(`sunates_testing`) was not available, so the **database-backed feature tests were written but not executed**.
What *was* executed: the backend unit suite (88 tests incl. the security-header tests), PHP syntax checks of every
changed file, route registration (`route:list`), Blade compilation (`view:cache`), the PWA test suite (51 tests) and
the PWA production build. Run `cd backend; php artisan test` against `sunates_testing` to complete verification.

Legend: **Done** = built end-to-end (backend, UI, permissions) with tests · **Partial** = built in part or with a
documented departure · **Blocked** = needs something outside the code (credentials, infrastructure, a decision) ·
**Manual** = a check only a person with devices/staff can do.

## Functional requirements (section 3)

| Req | Status | Where | Notes |
|---|---|---|---|
| FR-1 Directory, search, filter, export | Done | Console *Alumni directory*; `AlumniExportController` | Exports now recorded in the audit trail |
| FR-2 Registration, identity check, self-service profile | Done | `AlumniRegistrationService`, `RegisterView.vue`, `ProfileView.vue` | Match is now proven by a one-time code to the Registrar-held phone/email; records without contact go to staff. Password reset by email added; sign-in email change needs the current password |
| FR-3 Surveys at 6 mo / 1 yr / 3 yr, TA flag | Done | `SurveyScheduler`, `SurveyView.vue` | Questionnaire is a draft pending QA/NCHE alignment (section 14) |
| FR-4 Outcome dashboards with export | Done | Console *Graduate outcomes* | Small groups withheld |
| FR-5 Events, mentorship, targeted job/internship postings | Done (students staff-managed) | Console *Jobs and internships*, *Events*, *Mentorship*; PWA *Jobs & events*, mentoring card | Students have no accounts (no identity process decided). Alerts are in-app only |
| FR-6 Credential verification | Done | `/verify`, `POST /api/v1/verification/lookup`, credential links | Name matching now tolerates punctuation in stored names |
| FR-7 Data-freshness nudges | Done | `ProfileNudger`, `SendProfileNudge` | Retry bug fixed; rules re-checked at send time; LinkedIn per 7.4 below |
| FR-8 Staff console, bulk import, reporting | Done | Livewire console | Activity log added |
| FR-9 SorotiUniERP hook | Blocked (built) | `GraduateSource` adapters, console *SorotiUniERP sync* | Needs the real ERP interface and credentials to run |

## Non-functional requirements (section 4)

| Category | Status | Notes |
|---|---|---|
| Performance (3G) | Manual | Lazy-loaded routes, small bundle; throttled-3G Lighthouse run not yet done (`docs/testing-checklist.md` B) |
| Offline behaviour | Done + Manual | App-level queue, retries on open/online/foreground; sign-out now asks before discarding. Device tests on Android and iOS not yet done (checklist C) |
| Availability | Blocked | SLA depends on hosting allocation (ICT) |
| Security and data protection | Partial | RBAC, rate limits (API now throttled, login spray limit), audit trail, security headers, HTTPS forced in production. The data-protection review itself is a university task |
| Scalability | Done | MySQL + queue; indexed queries |
| Usability (entry-level Android; desktop console) | Manual | Mobile-first PWA; to confirm on a real low-end device |
| Maintainability | Done | Versioned migrations, ORM, handover document, ERD |
| Hosting portability | Done | Linux/Nginx kit independent of the ASP.NET stack |

## Integration (section 7)

| Item | Status | Notes |
|---|---|---|
| 7.1 ERP: shared DB or REST behind one interface | Blocked (built) | Untested against SorotiUniERP |
| 7.2 MTN SMS behind an interface | Blocked (built) | Needs MTN credentials; log driver in development |
| 7.3 WhatsApp Cloud API | Blocked (built) | Needs Meta account and approved templates, incl. new `sunates_registration_code` |
| 7.4 LinkedIn consent-based check | Partial (departure) | LinkedIn's official sign-in exposes no job data, so the periodic check is not buildable as written; the spec's manual-search fallback is built. Needs Registrar/ICT sign-off |

## Security (section 9)

| Item | Status | Notes |
|---|---|---|
| RBAC: Alumni, Registrar, ICT, QA/Dean viewer, rate-limited employer endpoint | Done | Plus staff-managed students (no login) |
| HTTPS throughout, Let's Encrypt | Partial | App forces https in production and sends HSTS; Nginx/certbot kit prepared, not deployed |
| Data-protection review | Blocked | University task; consent wording and retention periods are drafts for it |
| Scheduled MySQL backups; verification logs kept apart | Partial | Encrypted backup script + cron prepared, not yet run; logs separable via `VERIFICATION_DB_CONNECTION` |
| Verification returns status, programme, year only | Done | |

## Deliverables (sections 6, 10–13)

| Deliverable | Status | Where |
|---|---|---|
| ERD (section 6) | Done | `docs/erd.md` (review against Registrar spreadsheet fields still to do) |
| Deployment: local / staging / production, sandbox isolation (10) | Partial | `deploy/` kit and production `.env` template; no server yet |
| Phased build (11) | Done | Phases 1–4 plus FR-5 |
| Unit tests for survey scheduling and verification (12) | Done | `tests/Unit`, `tests/Feature/Phase2`, `Phase3` (feature tests need the test DB) |
| UAT with Registrar staff on anonymised data (12) | Manual | Checklist D |
| 3G load test; offline on Android + iOS (12) | Manual | Checklists B and C |
| Handover package (13) | Done | `docs/handover.md` (credential register to fill in ICT's password manager) |

## Decisions still needed (section 14 and this work)

1. Data-protection review: consent wording, retention periods (verification logs 730 d, audit log 1095 d), whether
   unregistered alumni may be surveyed/nudged/sent registration codes.
2. LinkedIn departure (7.4): Registrar and ICT sign-off.
3. Students: whether current students should ever get accounts, and how their identity would be verified.
4. Whether job/event postings should also go out by SMS/WhatsApp (templates, budget).
5. Final questionnaire fields against NCHE's tracer-study template (QA Directorate).
6. Hosting, domain, TLS, backup key holders (ICT).
