# Database Schema

The forward-only migration `2026_08_01_000000_create_universal_form_builder_tables.php` adds the universal domain without editing/dropping the legacy quiz schema.

## Identity and tenancy

- `organisations`; `organisation_memberships`
- `roles`; `permissions`; `role_permissions`; `membership_roles`; `user_roles`
- `users` gains `is_active` and `locale`; public registration still cannot assign roles

## Authoring

- `forms` — stable soft-deletable identity, organisation, owner, lifecycle, preset
- `form_versions` — numbered draft/published structure and immutable hash
- `form_sections` — ordered pages/sections
- `form_components` — registry type, order, required/visible flags, points, validated settings/translations JSON
- `component_options`, `validation_rules`, `conditional_rules`, `conditional_actions`, `scoring_rules`

## Publication and execution

- `publications` — exact version, access/availability/attempt/timer/result/consent/autosave policies
- `invitations` — SHA-256 token hash, use/expiry/revocation controls
- `form_submissions` — separate name avoids collision with preserved legacy `submissions`; exact version, identity mode, attempt/status/server times/revision/score
- `submission_answers` — unique `(form_submission_id, form_component_id)` typed JSON value
- `submission_mutations` — unique mutation UUID per submission for idempotency
- `attempt_grants`, `answer_scores`, `consent_records`

## Operations

- `attachments` — private randomized paths and metadata
- `exports` — private queue-ready artifacts with expiry
- `audit_logs` — append-only action metadata

Foreign keys use restrict for immutable/history-bearing records and cascade only for role pivots or membership internals. Forms are archived/soft-deleted; published versions and response records have no destructive UI.

JSON is used for type-specific settings, translations, answer values, scoring parameters, condition comparison values, filters, and redacted audit metadata. Foreign keys, statuses, types, order, flags, dates, attempts, deadlines, revisions, and points remain normal indexed columns. The migration uses Laravel schema primitives portable across SQLite, MySQL 8, and PostgreSQL; production portability must still be exercised in CI.
