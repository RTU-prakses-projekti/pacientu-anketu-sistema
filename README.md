# Universal Form Builder

A Laravel 13 modular-monolith MVP for building and running versioned tests, examinations, questionnaires, consent forms, surveys, registrations, and future form types through one shared component engine.

> **Sensitive-data warning:** this repository is not certified, legally approved, or production-ready for real patient/medical data. Obtain an independent security, privacy, legal, accessibility, and operational assessment before such use.

## Architecture

The application separates identity/organisations, form authoring, immutable publication, respondent execution/autosave, scoring/grading, consent, exports, private attachments, and audit logging. “Test” and “Patient questionnaire” are presets in `FormAuthoringService`; both use the same `FormVersion`, component registry, renderer, `SubmissionService`, autosave endpoint, timer enforcement, and export service. Legacy quiz tables remain intact but legacy routes are retired.

Key implementation locations:

- Domain services: `app/Domain/`
- Policies: `app/Policies/`
- Thin HTTP adapters and Form Requests: `app/Http/Controllers/`, `app/Http/Requests/`
- New schema: `database/migrations/2026_08_01_000000_create_universal_form_builder_tables.php`
- Component renderer and browser runner: `resources/views/forms/components/`, `resources/js/app.js`
- Architecture and schema details: `docs/TARGET_ARCHITECTURE.md`, `docs/DATABASE_SCHEMA.md`

## Prerequisites

- PHP 8.3+ with PDO MySQL, mbstring, XML, and ZIP extensions
- Composer 2
- Node.js/npm compatible with Vite 8
- MySQL 8 or MariaDB (the local development environment uses XAMPP MariaDB)
- A queue worker and scheduler for queued exports/deadline reconciliation
- PostgreSQL or MySQL 8 is recommended for production after portability and load verification

## Installation

Do not run destructive migration commands against an existing environment.

```powershell
composer install
Copy-Item .env.example .env   # only when .env does not exist
php artisan key:generate      # only for a new local environment
# Create the DB_DATABASE database with utf8mb4 first, using your verified local credentials.
php artisan migrate
php artisan db:seed
npm.cmd install
npm.cmd run build
```

For this repository, the original SQLite source is retained at `database/database.sqlite`, and the pre-builder backup is at `storage/app/private/backups/database-before-universal-builder.sqlite`. Do not delete or overwrite either file. The Composer `setup` script runs migrations automatically; review it before use and create the configured MySQL/MariaDB database first.

Important environment settings:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_LOCALE=lv
APP_FALLBACK_LOCALE=en
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kontroldarbu_sistema
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=file
FILESYSTEM_DISK=local
MAIL_MAILER=log
```

For production, set debug off, HTTPS/secure cookies, managed secrets, a production RDBMS, Redis-backed cache/queue/rate limits where appropriate, real mail, private durable object storage, and reviewed retention/logging policies.

## Start locally

Use separate terminals:

```powershell
php artisan serve
npm.cmd run dev
php artisan queue:work --tries=3
php artisan schedule:work
```

Open `http://127.0.0.1:8000`. The seeders create demonstration forms and users with random unknown passwords; they do not create a usable administrator credential.

## Create the first administrator

`app:create-admin` is a one-time bootstrap command for an installation that has no platform administrator. Run it interactively and enter a new, dedicated email address and a password of at least 12 characters:

```powershell
php artisan app:create-admin
```

The command never accepts the password as an argument, hardcodes it, or prints it. Optional non-secret prompts can be supplied as `--name` and `--email`; the password remains interactive and hidden.

The bootstrap check and account creation are transactionally protected against concurrent attempts. The command fails without changing any account when a platform administrator already exists or when the supplied email belongs to an existing user; it never promotes a public respondent account.

After bootstrap, use the authenticated **System users** interface while signed in as Admin 1. It creates ordinary users without privileged defaults, then provides a scoped role editor: global Admin 1 assignments use `user_roles`, while Admin 2, Admin 3, Ārsts, Vērtētājs, and Pacients are attached to a specific active organisation membership. Creation and role changes are audit logged, and the sole remaining Admin 1 cannot remove their own Admin 1 role. This command is not a normal administrator-management tool.

## Database, migrations, and demo data

Apply forward migrations only:

```powershell
php artisan migrate
php artisan migrate:status
```

Local demo data:

```powershell
php artisan db:seed
```

The demo seeder is guarded to the `local` environment and creates no real personal data or known password. Never use `migrate:fresh`, `db:wipe`, or reset/rollback commands on a database that must be preserved.

## Queues and scheduler

Large exports (over 500 matching submissions) dispatch `GenerateExport`; small exports run synchronously through the same service. Start a worker with `php artisan queue:work`. The scheduler invokes `submissions:finalize-overdue` every minute; run `php artisan schedule:work` locally or the standard scheduler trigger in deployment.

## Tests and build

```powershell
composer validate --no-check-publish
php artisan test
npm.cmd run build
```

Tests use in-memory SQLite and cover authentication, role escalation prevention, throttling, tenant isolation, versions, autosave/idempotency, deadlines, attempts, scoring, consent, export safety/XLSX, and legacy preservation.

## Database migration to MySQL

On 2026-08-09 the local development application was migrated from its retained SQLite source to the XAMPP MariaDB database `kontroldarbu_sistema` using `utf8mb4`. All Laravel migrations ran on MariaDB, existing rows were copied with primary keys and relationships preserved, and foreign-key/count verification completed successfully. PHPUnit intentionally continues to use isolated in-memory SQLite; it does not touch the development database.

The checked-in `.env.example` contains non-secret local defaults. Verify the host, port, username, and password for your own installation before running migrations. Never commit `.env`, database dumps, or MySQL data files.

## Performance test

Install [k6](https://grafana.com/docs/k6/latest/set-up/install-k6/), create an active **public-link** test publication with an answerable component and a sufficiently high attempt policy for isolated browser sessions, then run:

```powershell
$env:BASE_URL='http://127.0.0.1:8000'
$env:PUBLICATION_KEY='replace-with-public-key'
k6 run load-tests/universal-form-builder.js
```

The script ramps to 150 virtual users and exercises form load, attempt creation, autosave, and finalization. Default thresholds are under 1% failed requests and p95 below 1 second. These are proposed MVP thresholds, not a claim of measured capacity. Use production-like PostgreSQL/Redis infrastructure and anonymized representative form sizes.

## Backup and restore

Before every migration deployment, take an application-consistent database and private-files backup, record hashes, and test restoration in an isolated environment. For SQLite, stop writers and copy the database plus private files. For a managed RDBMS, use its consistent snapshot tooling. Restoration must include database, private attachments/exports, environment secrets, and an integrity check. Define and test RPO/RTO before production.

## Current limitations

- Password reset and MFA are not included in this MVP; administrator creation, login/logout, secure registration rules, active/inactive accounts, and login throttling are implemented.
- The UI offers LV/EN/RU interface resources and translatable form/component fields, but professional translation review and complete locale coverage are required.
- Attachment MIME/size validation and private authorization exist; production malware scanning/DLP is not included.
- Conditional logic supports simple visibility actions, not nested Boolean groups or calculations.
- Async exports use the database queue locally. Production object storage, cleanup scheduling, and operational monitoring require deployment configuration.
- No formal performance result is claimed until k6 is run in a production-like environment.

See `docs/SECURITY_NOTES.md` and `IMPLEMENTATION_REPORT.md` for further limitations.

## Doctor workspace

The organisation-scoped doctor workspace provides 200 fixed patient slots per doctor, doctor-only first/last name, a doctor-entered Patient ID stored in `external_patient_code`, notes, and a generated non-sequential `PAT-` Research ID stored in `patient_code`. Its wide table has synchronized horizontal scrollbars above and below: the upper bar measures the real table `scrollWidth` and follows later dynamic questionnaire columns, while the table wrapper retains native mouse/touchpad scrolling. The display hierarchy is Admin 1, Admin 2, Admin 3, Vērtētājs, Pacients, and Ārsts; stable role keys remain unchanged. Only Admin 1 may assign the doctor role, but platform administration alone grants no patient-data access. Doctor-only navigation contains no builder or system administration links, and one doctor cannot access another doctor's patient cases. Patient-linked submissions are excluded from generic administration lists, direct submission administration, grading/attempt administration, and existing general-purpose exports; clinical results are available only through the owning doctor's patient-scoped route.

Future research exports must use `patient_code` as the pseudonymous subject identifier and only questionnaire columns explicitly selected by the doctor. `first_name`, `last_name`, `external_patient_code`, and `note` are clinical identity/context fields and must be excluded by default; no patient export is implemented yet.
