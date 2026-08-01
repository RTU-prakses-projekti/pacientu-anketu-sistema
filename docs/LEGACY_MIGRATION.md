# Legacy Quiz Retirement and Migration

The old `tests`, `questions`, `options`, `submissions`, and `answers` tables and all original migrations remain unchanged. At implementation start each legacy domain table contained zero rows; this was verified again before migration and a byte-identical SQLite backup was created at `storage/app/private/backups/database-before-universal-builder.sqlite`.

Legacy quiz routes are no longer registered, so the broken manual/auto-submit and shadowed export endpoints are not exposed. Legacy controllers/models/views remain as reference code and can be removed in a later reviewed cleanup; their database schema must remain until an explicit retirement decision.

No silent import was performed. If another environment contains legacy data:

1. Take and test a backup.
2. Inventory invalid question types/options, null identities, duplicate/partial answers, and availability/timer semantics.
3. Map each legacy test to a new `Form`, one immutable `FormVersion`, sections/components/options/scoring rules, then a `Publication`.
4. Map completed legacy rows only after resolving identity and answer integrity; retain source IDs in a dedicated migration-map table.
5. Reconcile counts and scores, sample records manually, and produce a signed migration report.
6. Never delete legacy history as part of import; archive it after an agreed retention/cutover period.

The new table is deliberately named `form_submissions` to avoid collision with the preserved legacy `submissions` table.
