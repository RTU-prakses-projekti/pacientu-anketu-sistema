# Universal Form Builder — Implementation Report

Date: 2026-08-02
Branch: `universal-form-builder` (unchanged; nothing was committed, pushed, merged, or staged)

## 1. Executive summary

The legacy Laravel quiz prototype has been expanded into a working modular-monolith MVP for versioned forms. Tests, examinations, patient questionnaires, consent forms, surveys, and similar use cases now share one authoring model, registry, publication layer, respondent renderer, autosave/finalization service, scoring engine, and export service.

Before the forward migration ran, the SQLite database was copied to `storage/app/private/backups/database-before-universal-builder.sqlite`. The source and backup SHA-256 were both `9DD0F0B532AD62DEBA045F099CFC1005E33E9D7F3C958E2896E1A812EB37A624` at copy time. The legacy migrations and tables were not edited or dropped. The final legacy-table counts remain `tests=0`, `questions=0`, `options=0`, `submissions=0`, and `answers=0`.

Both required vertical workflows are exercised by the application services and feature tests: an immutable, timed, scored test workflow and an anonymous/identified patient-questionnaire workflow with exact-version consent, autosave, finalization, review, and export. A real local browser smoke test also opened the seeded public questionnaire and started the shared runner successfully with no browser console errors.

### Focused corrective pass

The 2026-08-01 corrective pass resolved the confirmed workflow and isolation defects without adding migrations or broad product scope:

- Respondent mutation ownership is now independent from administrative read/grade permission. Only the authenticated owner or matching anonymous/invitation browser session may use respondent autosave/finalize routes; reviewers retain read access through administrator routes.
- Finalization now requires a revisioned, idempotent current-answer snapshot. It saves and finalizes that snapshot under one submission row lock/transaction, including when autosave is disabled. Browser finalization waits for all active/dirty saves and revision conflicts prevent stale submission.
- Autosave validates types, ranges, options, and exact-version membership without requiring unfinished fields. Required visible answers are checked only against the locked final snapshot.
- Attempt-limit accounting excludes cancelled attempts while numbering uses the identity's maximum historical attempt number plus one.
- Conditional visibility now resets from deterministic component/section defaults on every evaluation. All four show/hide actions share browser/server semantics, `FormSection.visible` is respected, and hidden stored answers are retained but ignored for required validation and scoring.
- Choice scoring uses real stored-option radio/checkbox controls. Option label edits retain stable answer values, and publication rejects correct-answer references that do not exist in the same component.
- Draft/version duplication clones private attachment metadata and physical files and remaps component attachment references, preserving respondent downloads on later published versions.
- Form duplication requires both source view and destination-organisation `forms.create` permission.
- Organisation user administration no longer receives the platform directory; it shows current members and accepts only exact-email membership lookup.
- Publication validation now requires access codes and timer durations when applicable, rejects contradictory anonymous/identified flags, and blocks activation for archived forms.

### First-admin bootstrap hardening

- `php artisan app:create-admin` is now strictly a one-time bootstrap command. It succeeds only while no user has the global `platform_admin` role and refuses to promote any existing account.
- The existence check and new administrator creation run in one database transaction while a cross-process bootstrap lock prevents concurrent command instances from creating two first administrators.
- The password remains an interactive hidden prompt and is not accepted as a command-line argument.
- Public registration continues to create only ordinary respondent accounts and ignores attempted role or permission escalation input.
- The authenticated Admin 1 **System users** interface creates ordinary users without roles, then manages global Admin 1 and organisation-scoped Admin 2, Admin 3, doctor, reviewer, and patient roles in a dedicated audited editor. The final Admin 1 cannot remove their own only Admin 1 role.

### Versioned multilingual form content

- A central `LocalizedContent` service and `config/form_locales.php` define the only supported content locales (`lv`, `en`, `ru`) and one null/empty/whitespace-safe fallback algorithm.
- Respondent-visible title, description, completion text, and result text are implemented on immutable `FormVersion` records. The additive migration ran successfully on the local `database.sqlite` and performed a non-destructive backfill while preserving source `forms` fields and legacy tables.
- FormVersion, section, component, and option models expose reusable localized helper methods; preview and respondent rendering no longer implement ad hoc translation fallbacks.
- The builder presents one active language at a time through consistent LV/EN/RU tabs for version, section, component, and option text, with completion/fallback status indicators.
- Choice labels are edited independently of UUID-backed `component_options.value` and `stable_key`; scoring continues to reference stable technical values that are never displayed to respondents.
- Admin preview validates a `locale` query parameter, previews the exact version without creating submissions, attempts, timers, or writes, and marks fallback use.
- Active respondent language changes force dirty state through the existing autosave/revision request—even when normal autosave is disabled—before reloading the same submission. The deadline, answers, revision acknowledgement, option values, and scoring references are preserved.

## 2. Architecture implemented

- Laravel 13 / PHP modular monolith with Blade, Vite, Tailwind CSS, vanilla JavaScript, session authentication, database queues, and the scheduler.
- Organisation tenancy and membership-scoped RBAC using database roles/permissions, policies, and permission checks.
- `Form` as stable identity and immutable numbered `FormVersion` aggregates containing sections, components, options, conditions, validation/scoring configuration, and private attachments.
- A code-based 17-type `ComponentRegistry` defines allowed settings, answer schemas, server validation, normalization, renderer selection, export formatting, and scoring compatibility. No executable code is stored in JSON.
- Thin HTTP controllers delegate authoring, builder, submission, conditional-logic, scoring, audit, and export workflows to services under `app/Domain`.
- Publication access supports authenticated organisation users, public links, hashed access codes, and hashed limited-use invitations.
- One generic respondent renderer supports sections, progress, conditional visibility, debounce autosave, optimistic revisions, idempotency UUIDs, offline/save state, resume, server deadlines, and finalization.
- Finalization, scoring, consent recording, grading, attempt administration, and aggregate writes use database transactions. Published structures are protected against model updates/deletes.
- Queue-backed privacy-safe completion email notifications and queue-backed large exports; overdue attempts are reconciled by the scheduler.
- Append-only audit records store redacted metadata and hashed IP values rather than tokens, passwords, or answer bodies.

More detail is in `docs/TARGET_ARCHITECTURE.md`, `docs/DATABASE_SCHEMA.md`, and `docs/PRODUCT_DECISIONS.md`.

## 3. Database tables and migration created

Forward-only base migration: `database/migrations/2026_08_01_000000_create_universal_form_builder_tables.php`.

Additive multilingual-content migration: `database/migrations/2026_08_02_000100_add_localized_content_to_form_versions.php`. It adds nullable `title`, `description`, and `translations` columns to `form_versions`, backfills them from the related `forms` record, maps legacy locale `name` values to versioned `title` values, and leaves the source form fields intact. `down()` removes only these columns.

Additive consent-evidence migration: `database/migrations/2026_08_02_000200_add_content_locale_to_consent_records.php`. It adds nullable `content_locale` to `consent_records`; `down()` removes only this column. Same-decision autosaves preserve the first recorded locale, consent-text hash, and timestamp, while a real decision change records new evidence.

Both 2026-08-02 migrations ran successfully on the local `database.sqlite` and are recorded as **Ran, batch 3**. They were additive: existing data was preserved, legacy tables were not deleted, and the source `forms` fields were not destructively rewritten.

It adds `is_active` and `locale` to `users` and creates:

- Tenancy/RBAC: `organisations`, `organisation_memberships`, `roles`, `permissions`, `role_permissions`, `user_roles`, `membership_roles`.
- Authoring: `forms`, `form_versions`, `form_sections`, `form_components`, `component_options`, `validation_rules`, `conditional_rules`, `conditional_actions`, `scoring_rules`.
- Publication/execution: `publications`, `invitations`, `form_submissions`, `submission_answers`, `submission_mutations`, `attempt_grants`.
- Scoring/consent: `answer_scores`, `consent_records`.
- Operations: `attachments`, `exports`, `audit_logs`.

`form_submissions` deliberately avoids colliding with the preserved legacy `submissions` table. The schema includes foreign keys, restrict/cascade choices, soft deletes where appropriate, unique answer/mutation constraints, attempt/revision/status indexes, and portable Laravel JSON columns.

## 4. Main features completed

- Platform organisation CRUD; multi-organisation membership; organisation-level users/roles; platform user activation and role/permission screens; organisation and system audit views.
- Seeded configurable roles: `platform_admin`, `organisation_admin`, `form_creator`, `doctor`, `reviewer`, and `respondent`.
- Blank, Test/examination, and Patient questionnaire presets using one engine.
- Draft/published/archived lifecycle; preview; immutable publish; new draft cloning; duplicate; archive; version history.
- Builder section/component create, edit, copy, reorder, cross-section move, visibility, empty-section deletion, choice options, scoring, conditional visibility, and private attachment upload/linking.
- Content types: form title, heading, explanatory text, image, and file attachment.
- Input types: short/long text, number, date, time, yes/no, single/multiple choice, dropdown, rating/linear scale, and consent checkbox.
- Conditional operators: equals, not-equals, contains, greater-than, less-than, answered, and unanswered; show/hide component or section actions.
- Publication availability, access, attempts, timer, result/correct-answer visibility, anonymous/identified mode, consent, autosave, and resume policies.
- Revision-safe/idempotent autosave with exact-version component validation and unique answer upsert.
- Persisted server deadline, expiration on write/finalize, and `submissions:finalize-overdue` every minute.
- Server-only automatic scoring, partial scoring, numeric tolerance, manual grading/comments, and audited grading completion.
- Consent decision/text hash/exact-version records. `consent_records.content_locale` stores the actual resolved source locale of the displayed and hashed consent text, so it may differ from the requested locale after fallback; legacy/base consent content maps to LV. Refusal causes unrelated answers to be removed and prevents successful finalization.
- Creator submission filters/details, attempt grant, deadline extension, invalidation, CSV/XLSX export, private authorized download, and audit trails.
- XLSX sheets: Summary, Submissions, Answers, and Component statistics. CSV formula injection is neutralized.
- LV default respondent interface with EN/RU selectors and locale fallback.
- Centralized selected-language → LV → base → system-fallback → first-available content resolution; versioned form content; compact builder language tabs; localized preview and respondent/result rendering.
- Transactionally locked, one-time interactive `php artisan app:create-admin` bootstrap command with no stored/default/argument password and no existing-account promotion.
- Authenticated Admin 1 controls for ordinary-user creation and audited, scope-valid global/organisation role assignment and revocation.
- Active legacy quiz routes were retired; legacy schema and implementation files remain available for rollback/migration reference.

## 5. Files and packages added

Major additions:

- `app/Domain/{Audit,Exports,Forms,Submissions}/` services.
- `config/form_locales.php`, `LocalizedContent`, localized model concern/helpers, and `LocalizedContentRules`.
- New controllers, Form Requests, middleware, policies, notification, queue job, command, and domain models under `app/`.
- Universal migration, additive FormVersion localization migration, and `RolePermissionSeeder` / local-only `DemoSeeder`.
- Builder/respondent/admin/export/audit/system Blade views, translation files, and rebuilt Vite CSS/JavaScript.
- `tests/Feature/UniversalFormWorkflowTest.php` and `tests/Unit/LocalizedContentTest.php`.
- `load-tests/universal-form-builder.js`.
- Project README and six documents under `docs/`.

Composer package added: `openspout/openspout` v5.8.0 for streaming XLSX creation. No browser-CDN Tailwind dependency is used. The unrelated pre-existing `package-lock.json` working-tree change was preserved.

## 6. Legacy code retired or preserved

- Legacy tables and all legacy migration files are unchanged and present.
- Old quiz routes, broken submit/auto-submit endpoints, and the shadowed legacy export route are absent from active routing/navigation.
- Old controllers/models/views remain in the tree but are inactive; no unsafe automatic data import was attempted.
- Pre-implementation and final checks confirm the five legacy tables contain no meaningful records.

## 7. Tests created

The complete suite currently passes **49 tests / 369 assertions** using in-memory SQLite. It covers:

- registration identity persistence, role-escalation rejection, and login throttling;
- role display hierarchy, least-privilege Admin 3/doctor permissions, Admin 1-only doctor assignment, 200-slot rendering, pseudonymous patient creation, doctor ownership isolation, finalized-status projection, and patient-scoped read-only results;
- organisation isolation and creator/reviewer permissions;
- draft authoring, component copy/reorder/move, publish immutability, new drafts, and history-preserving archive;
- access code and publication windows, hashed invitation use limits, and attempt limits;
- autosave, answer uniqueness, revision conflict, mutation idempotency, resume, fixed deadlines, overdue expiration, and repeat-finalize idempotency;
- single-choice, multiple partial, numeric tolerance, manual grading, result/correct-answer visibility, and grading audit;
- exact-version consent refusal and unrelated-answer removal;
- CSV formula safety and required XLSX worksheets;
- private attachment ownership/tenant authorization;
- legacy table preservation and retired-route regressions.
- reviewer read-versus-respondent-mutation separation;
- autosave-enabled and autosave-disabled snapshot finalization, including latest-edit replacement;
- partial multi-section autosave and atomic final required validation;
- cancelled attempt numbering;
- conditional show/hide true-to-false reset and hidden-answer scoring/validation behavior;
- choice option label stability and publication-time scoring-reference validation;
- attachment cloning across versions and form duplication;
- duplicate authorization, organisation directory isolation, and publication configuration guards.
- first-administrator bootstrap success, repeat and existing-account rejection, public-registration role isolation, concurrent bootstrap exclusion, and authenticated audited administrator management.
- all resolver fallback cases, including null/empty/whitespace translations and unsupported locales;
- FormVersion localized-content creation, draft cloning, form duplication, and published nested-translation immutability;
- LV/EN/RU builder persistence, locale-key rejection, stable option values/keys, and stable scoring references;
- RU preview, LV fallback, and proof that preview creates no submission, attempt, timer, or database write;
- RU respondent rendering, localized option fallback, and language-change preservation of submission ID, revision, deadline, answers, and scoring references.
- explicit-LV system preset generation independent from `APP_LOCALE`;
- consent requested-versus-resolved source locale and exact-text hashing for direct RU and RU-to-LV fallback, plus content-locale/hash/timestamp preservation across a same-decision language switch;
- RU autosave status data, RU preview Yes/No system text, expanded object-level fallback indicators, image fallback detection limited to rendered image fields, and localized component-copy label consistency;
- direct FormVersion migration backfill coverage preserving source form fields and an existing legacy-table record.

## 8. Commands run and results

Successful verification:

```text
composer validate --no-check-publish     valid
composer audit --locked --no-interaction no advisories
npm.cmd audit --omit=dev --audit-level=high 0 vulnerabilities
php artisan about                         Laravel 13.20.0 / PHP 8.5.9 / SQLite
php artisan route:list --except-vendor    68 routes
php artisan migrate:status                both 2026-08-02 migrations Ran, batch 3
php artisan test                           45 passed, 309 assertions
npm.cmd run build                         Vite production build passed
PHP syntax check                          99 files passed
php artisan schedule:list                 overdue command scheduled every minute
php artisan queue:work --stop-when-empty  completed successfully
git diff --check                          passed
```

The base universal migration and local-only demo seed ran successfully after the backup. The two later additive localization/consent migrations also ran successfully on the saved SQLite database as batch 3; existing data and legacy tables were preserved, and source `forms` fields were not destructively rewritten. A browser smoke test loaded the public patient questionnaire in Latvian, started an anonymous attempt, rendered consent and questionnaire controls through the generic runner, and reported no console errors. The saved local database currently contains that one non-PII demonstration `form_submissions` row; legacy data remains unchanged.

## 9. Known limitations

- Password reset, email verification, SSO, MFA, privileged-session/device management, and account recovery are not implemented.
- The registry supports validated settings including defaults, randomization, width, scale labels, and refusal policy, but the MVP builder does not yet expose every registry setting. The dedicated `validation_rules` schema is versioned/cloned, while current field min/max/length validation is primarily driven by validated component settings rather than a full custom-rule editor.
- The LV/EN/RU builder and fallback behavior are implemented, but professional translation/content review and automated translation-completeness policy gates are still required.
- Conditional logic is simple visibility logic only; it has no nested AND/OR groups, calculations, branching destinations, or cycle graph analysis beyond same-version/self-reference checks.
- Consent withdrawal lifecycle UI and policy-driven retention workflows are not implemented. Refusal handling is a technical safeguard, not legal consent compliance.
- Notification delivery is a generic queued creator email with no per-form template/preferences, digesting, retry dashboard, or provider configuration UI.
- The admin filtering UI covers core fields but is not a full reporting/query builder.
- Production malware scanning/DLP, antivirus quarantine, object storage, export/attachment cleanup jobs, retention/legal-hold/data-subject workflows, and backup encryption are not included.
- Local development was moved to XAMPP MariaDB 10.4 on 2026-08-09, with all migrations, imported-data counts, foreign keys, Laravel queries, and the regression suite verified. MySQL 8/PostgreSQL CI portability, query plans, failover, and production lock/concurrency behavior have not yet been exercised.
- Accessibility has keyboard controls and labelled/status foundations, but no independent WCAG audit has been performed.
- The current local `.env` reports locale `en`; `.env.example` defaults to `lv`, and the locale middleware defaults unauthenticated respondents to Latvian. Set `APP_LOCALE=lv` in deployments without exposing environment secrets.

## 10. Security/privacy warning

This implementation is **not** a claim of GDPR, HIPAA, medical-device, research-ethics, e-signature, educational-record, or other legal compliance and is **not approved for real patient/medical data**. An independent security, privacy, legal, accessibility, data-governance, penetration, and operational assessment is mandatory before sensitive or regulated use. Production also requires HTTPS/HSTS, secure cookies, CSP/security headers, debug off, managed secrets, least-privilege infrastructure, SSO/MFA decisions, logging/alerting, incident response, retention/deletion rules, encrypted tested backups, and formal threat modelling. See `docs/SECURITY_NOTES.md`.

## 11. Performance-test status

`load-tests/universal-form-builder.js` ramps to 150 virtual users and exercises landing load, attempt creation, autosave, and finalization. Proposed thresholds are less than 1% failed requests and p95 under 1 second.

The test was **not run** because k6 is not installed in this environment. Exact failure:

```text
k6 version
k6 : The term 'k6' is not recognized as the name of a cmdlet, function, script file, or operable program.
```

No 150-user capacity claim is made.

## 12. Exact local startup instructions

From `C:\xampp\htdocs\Kontroldarbu-sistema`, after reviewing `.env`:

```powershell
composer install
php artisan migrate
php artisan db:seed
npm.cmd install
npm.cmd run build
php artisan serve
```

In separate terminals run:

```powershell
php artisan queue:work --tries=3
php artisan schedule:work
```

For frontend development, additionally run `npm.cmd run dev`. Open `http://127.0.0.1:8000`. Never run reset/fresh/wipe commands against a database that must be preserved.

## 13. Exact first-admin creation instructions

This is a one-time installation bootstrap command, not a normal administrator-management tool. It can run only when no user has the global `platform_admin` role. Provide a new, dedicated email address and a password of at least 12 characters containing letters and numbers:

```powershell
php artisan app:create-admin
```

Optional non-secret prompts can be passed as `--name` and `--email`; the password always remains interactive and hidden and cannot be passed as an argument. The command performs the existence check and account creation transactionally, excludes concurrent bootstrap attempts, refuses an email belonging to any existing user, and makes no changes when an administrator already exists.

Create users through the authenticated **System users** interface while signed in as an existing Admin 1. New users receive no privileged role by default. Use **Change roles** to assign or revoke global Admin 1 and organisation-specific Admin 2, Admin 3, doctor, reviewer, or patient roles; these changes are audit logged and validated against each role's stored scope.

## 14. Remaining work ordered by priority

1. Commission independent security/privacy/legal/accessibility reviews and remediate all findings before sensitive data use.
2. Deploy and validate production PostgreSQL/MySQL, Redis, private object storage, HTTPS/security headers, monitoring, encrypted backups, restore drills, and retention/deletion workflows.
3. Run tenant-isolation penetration tests and production-like concurrency/locking/load tests, including the documented 150-user k6 scenario.
4. Add password reset, verified identity/SSO, administrator MFA, privileged-session controls, and formal role review workflows.
5. Complete the builder UI for remaining non-text registry validation/layout/randomization settings; add professional LV/EN/RU review and automated translation completeness gates.
6. Add consent withdrawal, approved retention policies, legal text/version management, and data-subject workflows after legal design approval.
7. Add malware scanning/DLP/quarantine, private-object lifecycle management, and scheduled expired export cleanup.
8. Add notification preferences/templates, provider configuration, delivery observability, retry/dead-letter operations, and broader browser E2E/WCAG automation.

## 15. Doctor workspace and role hierarchy

The permission seeder and additive migration expose the requested Admin 1/Admin 2/Admin 3 display hierarchy without changing stable role keys. Admin 3 is limited to form authoring, publishing, and submission viewing. The doctor role has only doctor-dashboard, patient-view/update, and patient-questionnaire permissions, and only Admin 1 may assign it. Doctor-only navigation omits form-builder and system-administration links.

`patient_cases` provides 200 unique slots per organisation/doctor with doctor-only first/last names, a manually entered Patient ID in `external_patient_code`, notes, and a generated immutable `PAT-` Research ID in `patient_code`. The dashboard places these fields in separate columns and keeps the save/create action visible in its own column. Its wide dynamic-questionnaire table retains the native lower scrollbar and adds a synchronized upper scrollbar whose spacer is recalculated from the real table `scrollWidth` with resize observation. `patient_form_assignments` provides dynamic questionnaire-part labels/order and an optional invitation link. The dashboard uses green status only for finalized submission states and opens a read-only patient-scoped result. Policies and route checks reject cross-doctor, cross-patient, invitation-free, and platform-admin-only result access.

Platform administration is explicitly separated from clinical data access. Admin 1 retains system administration and doctor-role assignment but receives neither doctor patient-data permissions nor a PatientCase policy bypass. Patient-linked submissions are also excluded from generic submission administration and existing general-purpose exports, so the generic FormSubmission policy cannot bypass clinical ownership. A future research export may include `patient_code` and doctor-selected questionnaire answers; it must exclude `first_name`, `last_name`, `external_patient_code`, and `note` by default. That research export remains intentionally unimplemented.

## 16. Doctor-to-patient questionnaire portal

The doctor-only patient management screen assigns existing active/published invitation publications as ordered parts. `PatientAccessPackage` owns a UUID, patient/creator relation, SHA-256-only secret hash, expiry, and revocation state; assignments point at the current package. Link issue/regeneration is transactional, revokes prior packages, aligns internal invitation expiry, and records only non-secret audit metadata.

The initial secret-bearing route establishes a package session and redirects to a clean UUID URL. Authorization is rechecked for every portal, start, runner autosave, and finalize request. Previous incomplete parts block later starts server-side. The trusted `SubmissionService::startForInvitation` entry point retains publication, invitation, attempt, audit, revision, validation, consent, and scoring behavior while reusing an in-progress patient attempt. Finalization returns to the portal. Revoked/expired packages and cross-package assignment substitution are denied. Email/SMS delivery and a patient-specific export remain out of scope.
