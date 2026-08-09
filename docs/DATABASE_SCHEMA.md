# Database Schema

The forward-only migration `2026_08_01_000000_create_universal_form_builder_tables.php` adds the universal domain without editing/dropping the legacy quiz schema.

The additive migration `2026_08_02_000100_add_localized_content_to_form_versions.php` changes the schema by adding nullable `title`, `description`, and `translations` columns to `form_versions`. Its non-destructive backfill preserves existing records, maps `forms.translations.{locale}.name` to `form_versions.translations.{locale}.title`, guarantees an LV title from `forms.name`, does not rewrite the source `forms.name` or `forms.translations` fields, and does not delete legacy tables. Its `down()` removes only these three added columns.

The additive migration `2026_08_02_000200_add_content_locale_to_consent_records.php` adds nullable `consent_records.content_locale` for the exact resolved `lv`, `en`, or `ru` source locale whose consent text was shown and hashed. The respondent's requested locale may differ from `content_locale` when localized content falls back; a resolved legacy/base consent value is treated as Latvian content. Its `down()` removes only that column.

Both 2026-08-02 migrations ran successfully on the local `database.sqlite` and are recorded as **Ran, batch 3**. Their changes were additive: existing data was preserved, legacy tables were not deleted, and the source `forms` fields were not destructively rewritten.

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

## Localized authoring content

- `form_versions.translations.{locale}.{title,description,completion_text,result_text}`
- `form_sections.translations.{locale}.{title,description}`
- `form_components.translations.{locale}.{label,description,help_text,placeholder,consent_text,minimum_label,maximum_label,image_title,image_caption}`
- `component_options.translations.{locale}.label`

Only `lv`, `en`, and `ru` are accepted. Latvian values are mirrored into existing base columns or type-specific settings where those legacy/base locations already exist. Technical option values, stable keys, validation/scoring rules, points, and attachment IDs never enter translation JSON. `component_options.value` and `stable_key` never derive from or change with a translated label.

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

JSON is used for type-specific settings, translations, answer values, scoring parameters, condition comparison values, filters, and redacted audit metadata. Foreign keys, statuses, types, order, flags, dates, attempts, deadlines, revisions, and points remain normal indexed columns. The migration uses Laravel schema primitives portable across SQLite, MySQL/MariaDB, and PostgreSQL. The complete migration and existing-data import have been exercised locally on MariaDB 10.4; MySQL 8 and PostgreSQL portability still require CI verification.

## Doctor workspace

- `patient_cases` — UUID public identifier, organisation and owning doctor, fixed slot number from 1 to 200, globally unique non-sequential Research ID/pseudonym in `patient_code`, doctor-only `first_name`, `last_name`, manually entered Patient ID in optional `external_patient_code`, and note. `(organisation_id, doctor_id, slot_number)` is unique.
- `patient_form_assignments` — UUID public identifier, patient case, exact publication, optional invitation, administrator-defined label/order, and assignment time. A publication can occur once per patient case; an invitation can be linked once.

Patient results are associated through the assignment's non-null invitation. An assignment without an invitation remains not completed and cannot expose an unrelated invitation-free submission. Doctor access requires patient ownership and an active organisation doctor membership carrying the relevant clinical permission. Platform-administrator status has no PatientCase policy bypass and no doctor workspace by itself. Any `form_submissions` row linked through `patient_form_assignments.invitation_id` is clinical: generic submission administration, grading/attempt administration, and general-purpose export queries exclude or deny it.

For a future research export, `patient_code` and doctor-selected questionnaire answer columns form the shareable boundary. `first_name`, `last_name`, `external_patient_code`, and `note` are excluded from that boundary by default. No export implementation is included in this change.
