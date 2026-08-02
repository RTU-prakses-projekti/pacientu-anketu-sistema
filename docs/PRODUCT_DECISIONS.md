# Product Decisions

## Decisions implemented

- One universal form engine; tests and patient questionnaires are presets, not modules.
- Organisations are the administrative isolation boundary. Users may have several memberships.
- Roles are configurable database records. Initial roles are `platform_admin`, `organisation_admin`, `form_creator`, `reviewer`, and `respondent`.
- Draft versions are editable. Published versions and their child structures are immutable. Editing a publication starts a copied draft.
- Publications expose one exact version through authenticated, public, access-code, or hashed invitation-token access.
- Anonymous submissions use a random browser-session respondent key hash, never a fake account.
- Server time is authoritative. Attempt deadlines are stored once and checked on autosave/finalization; refresh does not reset time.
- Consent is a component plus an immutable consent record referencing the exact form version and content hash.
- Results default to completion-only. Correct answers require an explicit publication setting.
- Form content supports exactly Latvian (`lv`), English (`en`), and Russian (`ru`), configured centrally in `config/form_locales.php`. Latvian is the form-content default.
- Localized text resolves in this order: requested non-empty translation, Latvian translation, legacy/base value, configured system-fallback translation, then the first non-empty supported translation. Null, empty, and whitespace-only values are absent.
- Respondent-visible form title/description/completion/result content belongs to the exact `FormVersion`; `Form.name` remains a mutable internal administrative name for backward compatibility.
- Component option `stable_key` and `value` are technical immutable identities. Labels may change independently in LV/EN/RU without changing answers or scoring references.
- Builder translation fields use compact LV/EN/RU tabs. Preview chooses a content locale independently from the administrator interface locale and reads the exact draft/published version without starting an attempt.
- Changing language during an active response saves dirty browser state through the existing revisioned autosave request first, then reloads the same submission. Submission identity, deadline, answers, option values, and scoring are language-independent.

## Decisions still requiring owners

- Jurisdictions, regulatory classification, and approved medical/research use cases.
- SSO/MFA/password-reset provider and account recovery policy.
- Retention, withdrawal, legal hold, subject access, deletion, and backup retention per data class.
- Exact timer grace/accommodation and invalidation appeal rules.
- Whether invitation recipients require verified identity rather than a reference string.
- Production storage, malware scanning, notification provider, RDBMS, Redis, observability, RPO/RTO, and SLOs.
- Professional translations and WCAG conformance testing level.
- Advanced logic, question banks, calculations, signatures, LMS/health integrations, and statistical disclosure controls.
