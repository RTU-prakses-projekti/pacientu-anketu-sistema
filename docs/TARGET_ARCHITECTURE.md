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

Use PostgreSQL or MySQL 8 for production, Redis for queues/cache/rate limits, private object storage, multiple stateless PHP workers, HTTPS, a supervised queue worker, one scheduler trigger, centralized redacted logs, metrics/alerts, and tested backups. The current SQLite/database-queue environment is for local development.
