# Target Architecture

## Shape

The MVP is a modular Laravel monolith. HTTP controllers are adapters; Form Requests validate transport data; policies enforce organisation scope; domain services own transactional workflows. Queued jobs handle large exports, and the scheduler reconciles overdue submissions.

## Modules

- **Organisations / identity:** organisations, memberships, database roles/permissions, platform roles, account activation.
- **Forms / authoring:** stable forms, draft/published versions, sections, registry-backed components/options/validation/conditions/scoring rules.
- **Publications / access:** one immutable version, access mode, hash-only codes/tokens, availability, attempt/result/timer policies.
- **Submissions:** versioned attempts, answer upserts, mutation idempotency, optimistic revision, finalization and deadlines.
- **Scoring / grading:** server strategies and reviewer-awarded scores.
- **Consent:** exact-version decisions with text hash.
- **Exports / attachments:** private storage, authorization, CSV/XLSX, queue-ready jobs.
- **Audit:** append-only security and domain event metadata with sensitive keys excluded.

## Component contract

`ComponentRegistry` defines stable key, display metadata, supported settings, answer schema, server normalization/validation, renderer reference, export formatting, and scoring capability. Database JSON stores only registry-validated data; it never stores executable classes or source. Adding a component type normally requires registry logic, Blade rendering, and tests—not a database migration.

## Publication and execution

Publishing validates references, hashes the complete version graph, and changes the draft to immutable `published`. A `Publication` points to that exact version. `SubmissionService` validates access, determines attempt number/deadline, restores in-progress attempts, validates/autosaves component values with a revision and mutation UUID, records consent, and atomically finalizes/scorers the saved snapshot. Browser JavaScript displays time and state but has no scoring/timing authority.

## Deployment direction

Use PostgreSQL or MySQL 8 for production, Redis for queues/cache/rate limits, private object storage, multiple stateless PHP workers, HTTPS, a supervised queue worker, one scheduler trigger, centralized redacted logs, metrics/alerts, and tested backups. Local development now uses XAMPP MariaDB with the database queue; PHPUnit remains isolated on in-memory SQLite.

## Doctor access model

The doctor UI is a separate authenticated workspace backed by `patient_cases` and `patient_form_assignments`. A doctor-only account is redirected there and receives no form-builder or system-administration navigation. Rows are fixed slots; questionnaire columns are derived deterministically from assigned publications. Only `submitted`, `awaiting_grading`, and `graded` submissions count as completed.

Access checks combine the owning `doctor_id`, an active doctor membership with the explicit clinical permission, patient/assignment consistency, and the assignment invitation, preventing cross-doctor and cross-patient result access. Platform administration and clinical access are separate trust domains: Admin 1 may manage users and doctor-role assignment but cannot inspect a doctor's patient workspace, identity fields, notes, or individual results merely because of the global role. Patient-assignment-linked submissions are denied by the generic FormSubmission policy and filtered from generic administration and export queries, closing alternate non-clinical access paths.

The future export boundary is intentionally asymmetric. Doctor-only identity/context fields are `first_name`, `last_name`, the manually entered Patient ID in `external_patient_code`, and `note`. The shareable research side starts with the generated immutable `patient_code`/`PAT-*` Research ID pseudonym and may later add only questionnaire answer columns explicitly selected by the doctor. The current model documents this boundary but does not implement export UI or files.

## Patient package execution

`PatientAccessService` is the capability boundary for account-free patient access. It issues hash-only expiring packages, revokes old capabilities, consumes the secret-bearing URL into a server session, and verifies that a submission's assignment belongs to the active package. Controllers never need invitation plaintext. `SubmissionService::startForInvitation` is the trusted adapter into the existing attempt engine and forces in-progress reuse for patient parts; the normal invitation entry point remains token-based.

The portal derives an unbounded ordered part list from assignments. Unlocking is a server-side prefix rule: every earlier `(display_order, id)` part must have a finalized submission. The existing runner uses a minimal patient layout and autosave, then finalization returns to the clean package URL. URL generation uses Laravel's configured/current origin rather than a local or LAN address.
