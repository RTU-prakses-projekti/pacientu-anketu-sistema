# Security and Privacy Notes

## Implemented foundations

- Session authentication, CSRF, login throttling, 12-character letter/number password rule, session regeneration/invalidation, inactive-account rejection.
- Public registration persists participant identity but never accepts roles.
- Database roles/permissions, policies, organisation query scoping, and cross-organisation tests.
- Hash-only access codes/invitation tokens; plain invitation is shown once.
- Server-authoritative availability/deadlines and server-only scoring.
- Optimistic autosave revision, idempotent mutation UUID, answer uniqueness, row locks, transactions, immutable published versions, restricted history FKs.
- Escaped Blade output, validated registry settings/answers, private files/exports, spreadsheet formula neutralization.
- Audit service omits known sensitive keys and hashes IP with the application key.

## Not a compliance claim

These controls do not establish GDPR, HIPAA, medical-device, research-ethics, e-signature, educational-record, or other legal compliance. Do not process real patient/medical data until qualified independent reviewers approve the exact jurisdiction, hosting, data flows, policies, threat model, and operations.

## Required production work

- Debug off, HTTPS/HSTS and secure cookies, CSP and security headers, managed secret rotation, dependency/SAST/DAST/secret scanning.
- SSO/MFA/password reset/verified identity decisions and privileged access reviews.
- PostgreSQL/MySQL and Redis hardening, least-privilege service credentials, encryption/key management.
- Private object storage, malware scanning, file-type verification, export expiration cleanup.
- Data classification/minimization, consent legal review, retention/deletion/withdrawal/legal hold, data-subject workflows.
- Central redacted logs, SIEM/alerts, incident response, backup encryption and restore exercises.
- Penetration, tenant-isolation, accessibility, performance, failover, and disaster-recovery testing.

Audit metadata must never include passwords, plain tokens/codes, complete answers, or unnecessary health data. Current IP hashing may itself be personal data under applicable law and must be reviewed.
