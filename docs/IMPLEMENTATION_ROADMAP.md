# Implementation Roadmap

## Delivered MVP

1. Safe backup and additive schema.
2. Organisations, memberships, database permissions and policies.
3. Versioned form presets, builder, component registry, preview, immutable publish/new draft/archive/duplicate.
4. Publications, access modes, hashed invitations/codes, attempts and server deadlines.
5. Generic renderer, autosave/revision/idempotency/resume/finalize.
6. Conditional visibility, scoring/manual grading, consent records.
7. Private attachments, CSV/XLSX exports, queues, audit log, LV/EN/RU foundation.
8. Automated workflow tests, k6 load script, documentation.

## Priority follow-up

1. Independent security/privacy/accessibility assessment; resolve findings before sensitive data.
2. Production PostgreSQL/Redis/object-storage environment and database portability CI.
3. Password reset, verified email/SSO, admin MFA, session/device management.
4. Malware scanning/DLP, file lifecycle cleanup, export cleanup, retention/legal-hold workflows.
5. Complete professional LV/EN/RU translations and translation completeness gates.
6. Richer conditional groups/calculations and per-section timer/accommodation policy if approved.
7. Browser end-to-end/accessibility tests and production-like k6 execution at/above 150 concurrent users.
8. Notification templates/delivery UI and provider configuration.
