# SUN Alumni handover package

Spec section 13 asks for a documented handover so the nominated Unit can run the system after the student
developers move on. This is that document. It deliberately contains **no passwords or keys**: it says where each
one lives and who holds it. Keep the filled-in credential register (section 3) in the ICT password manager.

## 1. What the system is

| Part | Code | Runs as |
|---|---|---|
| REST API + staff console | `backend/` (Laravel 13, PHP 8.3, Livewire 4) | PHP-FPM behind Nginx |
| Alumni app (PWA) | `pwa/` (Vue 3, Vite, Workbox) | static files from `pwa/dist`, served by Nginx |
| Database | MySQL 8.x, schema in `backend/database/migrations` (diagram: `docs/erd.md`) | `mysqld` |
| Background work | `php artisan queue:work` (Supervisor) and `php artisan schedule:run` (cron, every minute) | `www-data` |

Requirements and design: `SUN-ATES_Engineering_Specification.pdf`; how each requirement is met:
`docs/srs-compliance.md`; feature-by-feature behaviour: the root `README.md`.

## 2. Who owns what

| Area | Owner | Notes |
|---|---|---|
| System owner, record quality, verification queue, employer enquiries | Office of the Academic Registrar | Registrar staff role |
| Hosting, security, backups, staff accounts, ERP hook, messaging credentials | Directorate of ICT | ICT admin role |
| Outcome dashboards, questionnaire wording | QA Directorate / Deans | QA/Dean viewer role (read-only) |
| Jobs, events, mentorship matches | Alumni desk (Registrar staff role) | console: *Jobs and internships*, *Events*, *Mentorship* |

## 3. Credential register (fill in, store in the ICT password manager, NOT here)

| Credential | Where it is used | Where it comes from | Rotate |
|---|---|---|---|
| `APP_KEY` | `backend/.env` | generated once (`php artisan key:generate`) | never casually: encrypted data depends on it |
| MySQL `sunates` user password | `backend/.env` `DB_PASSWORD` | ICT | yearly or on staff change |
| MySQL dump user | `/root/.my.cnf` on the server | ICT | yearly |
| Backup GPG private key | ICT workstation + a sealed copy | ICT | when a holder leaves |
| SMTP relay login | `MAIL_*` | university mail admin | per mail policy |
| MTN SMS client id/secret, sender id | `MTN_SMS_*` | developers.mtn.com account owned by ICT | yearly / on leak |
| WhatsApp phone-number id, system-user token, app secret, verify token | `WHATSAPP_*` | Meta Business Manager owned by the university | token yearly |
| SorotiUniERP token (when live) | `ERP_REST_TOKEN` | SorotiUniERP team | per their policy |
| TLS certificate | Nginx | Let's Encrypt (certbot auto-renews) | automatic |
| First ICT admin console account | `sunates:create-staff` | ICT | on staff change |

Messaging credentials belong to **university-owned** accounts (MTN developer account, Meta Business Manager), never a
student's personal account.

## 4. Routine operations

| When | Task | How |
|---|---|---|
| Daily | Check the queue worker is running | `sudo supervisorctl status sunates-worker` |
| Daily (automatic) | Surveys 09:00, ERP sync 02:30, pruning | `routes/console.php`; `php artisan schedule:list` |
| Weekly | Glance at **Messages** for failures; **Activity log** for unexpected exports | console |
| Weekly | Clear the **Verification queue** and **Employer enquiries** | Registrar staff |
| Monthly | Review staff accounts; deactivate leavers | console: *Staff accounts* (ICT) |
| Quarterly | Test-restore a backup on staging | `deploy/README.md` |
| Each term | Refresh the student list for mentorship; end finished matches | console: *Mentorship* |
| On questionnaire change | Edit `backend/config/tracer_surveys.php`, then `php artisan sunates:sync-surveys` | QA Directorate + ICT |

## 5. Common tasks

- **Import graduates from a spreadsheet:** console → *Import from spreadsheet* → Preview → Commit (CSV UTF-8).
- **A graduate cannot register:** if their record has no phone or email, the registration goes to the *Verification
  queue*; *Link* it to the right record. If their number changed, update it on the record first, then they
  register again and receive the code.
- **A graduate forgot their password:** they use *Forgot your password?* in the app (email link). If they no longer
  have that email, staff update it on the record (it is also their sign-in email).
- **Someone asks to stop messages:** open their record → *Stop messages*.
- **Go live with SMS/WhatsApp:** README, "Going live with MTN SMS and WhatsApp"; then *Messages* → *Send a test message*.
  Create the four templates listed there **plus** `sunates_registration_code` (Meta "authentication" category, one parameter: the code).

## 6. Where to look when something breaks

| Symptom | Look at |
|---|---|
| Surveys or nudges not going out | worker running? `storage/logs/laravel.log`; console *Messages* for failures; `php artisan queue:failed` |
| 500 errors | `backend/storage/logs/laravel-YYYY-MM-DD.log` |
| Registration codes not arriving | console *Messages* (template `registration_code`); mail log for email codes |
| Nothing scheduled ran | cron entry present? `php artisan schedule:list` |
| Disk filling | `storage/logs`, `/var/backups/sunates` retention |

## 7. Development

Local setup, tests and demo data: root `README.md`. Tests need a MySQL database named `sunates_testing`
(`phpunit.xml` forces that name so tests can never touch real data). Before changing schema, add a migration;
never edit one that has run in production.

## 8. Handover log

| Date | From | To | What was handed over | Restore last tested |
|---|---|---|---|---|
| | | | | |
