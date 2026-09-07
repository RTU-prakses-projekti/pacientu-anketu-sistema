# Patient Questionnaire System

## Overview

Patient Questionnaire System is a Laravel-based web application for creating, publishing, completing and managing patient questionnaires. It supports a doctor workspace, account-free patient access and privacy-preserving result handoff.

This is an RTU professional internship and demonstration project. It is not presented as a certified clinical product. Before using real medical data in production, an independent security, privacy and compliance audit would be required.

## Main Features

- Doctor workspace with an organisation-scoped patient registry.
- Pseudonymous \`PAT-*\` research IDs for patient questionnaire workflows.
- Questionnaire assignment and individual expiring/revocable patient access links.
- Patient completion without a patient User account.
- Autosave, resume, sequential questionnaire parts and final submission.
- Questionnaire builder with sections, components, validation and conditional logic.
- LV/EN/RU localized questionnaire and application content.
- Consent recording and published form-version immutability.
- Completed result view for the responsible doctor.
- Permission-controlled anonymized result handoff.
- Sensitive-answer filtering for anonymized views and exports.
- CSV/XLSX export for anonymized results.
- Questionnaire package import/export with manifest and asset compatibility.
- Audit logging and organisation isolation.

## User Roles

The product roles are:

- **Administrator** — system and organisation administration without automatic doctor-patient ownership access.
- **Administrator assistant** — scoped organisation administration.
- **Questionnaire manager** — questionnaire builder, publishing and package exchange.
- **Doctor** — own patient workspace, assignments and completed results.

\`platform_admin\` is a hidden bootstrap/root account used for initial administration and recovery. It is not an assignable product role.

Patients do not have system accounts. They use the protected patient-access link workflow.

## Patient Workflow

\`\`\`mermaid
flowchart LR
    D[Doctor] --> P[Create patient case]
    P --> A[Assign questionnaire parts]
    A --> L[Issue expiring patient link]
    L --> R[Patient opens link without account]
    R --> S[Autosave and resume]
    S --> F[Sequential parts and consent]
    F --> C[Finalize submission]
    C --> V[Doctor views completed result]
    V --> H[Anonymized handoff]
    H --> X[Recipient views filtered result/export]
\`\`\`

Patient links are bearer credentials. The plaintext token is displayed only for link delivery; the database stores only a SHA-256 token hash. Active links expire and can be revoked or regenerated.

## Security & Privacy

- Patient cases are isolated by doctor ownership in the Doctor Workspace.
- Active organisation and membership checks scope staff access.
- Generic submission/export permissions do not grant access to patient-linked submissions or anonymized handoffs.
- Anonymized recipients see only their own handoffs, PAT IDs, metadata and non-sensitive answers.
- Patient identity fields and sensitive component answers are excluded from anonymized views and exports.
- \`platform_admin\` is hidden from role-assignment UI and protected by bootstrap safeguards.
- Audit records avoid plaintext patient access tokens.
- Private attachments and application storage are kept outside nginx public content.

These controls are application-level protections for a demonstration project. They are not a claim of medical certification or regulatory compliance.

## Architecture

\`\`\`mermaid
flowchart LR
    DA[Local doctor/admin] --> N[nginx]
    RP[Remote patient] --> CF[Cloudflare Quick Tunnel<br/>public HTTPS demo/test transport]
    CF --> N
    N --> PHP[Laravel PHP-FPM]
    PHP --> DB[(MariaDB)]
    PHP --> Q[Queue worker]
    PHP --> SCH[Scheduler]
    PHP --> PS[(Private storage volume)]
\`\`\`

Docker Compose separates the edge and backend networks. nginx serves the Laravel public directory and proxies PHP requests to the app container. The database is backend-only; private storage and logs use persistent volumes.

## Tech Stack

- Laravel 13.20
- PHP 8.4 FPM
- MariaDB 10.11
- Docker Compose
- nginx
- Vite 8 and Node 24 for frontend builds
- PHPUnit feature and unit tests
- OpenSpout for CSV/XLSX generation
- Cloudflare Quick Tunnel for public HTTPS demo/test transport without a custom domain

## Deployment

For a local Docker demonstration:

1. Start Docker Desktop with Linux containers.
2. Run \`deployment\\\\INSTALL-SERVER.bat\` once on a fresh server.
3. Use \`deployment\\\\START-SERVER.bat\`, \`STATUS-SERVER.bat\` and \`STOP-SERVER.bat\` for daily operation.
4. Use \`deployment\\\\START-PUBLIC-DEMO.bat\` for a temporary public HTTPS demo URL through Cloudflare Quick Tunnel.
5. Use \`deployment\\\\STOP-PUBLIC-DEMO.bat\` to stop only the public demo tunnel.

The Quick Tunnel is a temporary public HTTPS demo/test transport and may receive a new random \`trycloudflare.com\` hostname when recreated. No custom domain is required. The deployment scripts keep secrets in ignored local files and preserve database/private-storage volumes.

Backups are created with \`deployment\\\\BACKUP-SERVER.bat\`. Test/staging uninstall is available through \`deployment\\\\UNINSTALL-SERVER.bat\` and is destructive for this project’s Docker data only.

## Testing

The repository contains feature coverage for:

- patient token hashing, expiry, revoke/regenerate and sequential access;
- autosave, resume, finalization and consent;
- doctor ownership and organisation isolation;
- role assignment and bootstrap root protection;
- anonymized handoff, sensitive-answer filtering and CSV/XLSX export;
- questionnaire ZIP/Git package exchange, assets and legacy manifest compatibility;
- login throttling and proxy redirect behavior;
- Docker bootstrap status commands.

Run the full checks inside the application Docker environment:

\`\`\`powershell
docker compose --env-file .env.production exec -T app php artisan test
docker compose --env-file .env.production exec -T app php artisan questionnaires:validate
npm.cmd run build
git diff --check
\`\`\`

The project should be treated as a demonstration/practice system until the complete Docker runtime checks and an independent security/privacy/compliance review are completed.

## Project Structure

\`\`\`text
app/                 Laravel application, policies and domain services
database/            migrations, factories and seeders
deployment/          Docker/Windows server scripts and nginx configuration
docker/              PHP runtime configuration
questionnaires/      portable questionnaire packages
resources/views/     Blade UI
resources/css/       application styles
resources/js/         questionnaire and UI behavior
routes/               web and console routes
tests/                feature and unit regression tests
docs/screenshots/     planned portfolio screenshots
\`\`\`

## Screenshots

Planned screenshots are listed below. Actual images are intentionally not included yet.

- \`docs/screenshots/doctor-workspace.png\` — Doctor Workspace with synthetic patients and PAT IDs.
- \`docs/screenshots/patient-management.png\` — patient assignment and secure-link management with fake data.
- \`docs/screenshots/questionnaire-builder.png\` — sections, components and conditional logic.
- \`docs/screenshots/patient-portal-mobile.png\` — account-free patient portal on a phone-sized viewport.
- \`docs/screenshots/questionnaire-runner-mobile.png\` — questionnaire runner with progress and validation.
- \`docs/screenshots/result-handoff.png\` — doctor’s completed result and anonymized recipient selection.
- \`docs/screenshots/anonymized-results.png\` — recipient result list and export controls.
- \`docs/screenshots/roles-permissions.png\` — product roles with hidden bootstrap root.
- \`docs/screenshots/public-demo.png\` — public HTTPS Quick Tunnel demo, with the temporary URL redacted if necessary.

Use only synthetic data. Never show plaintext patient tokens, names, personal IDs, notes, emails, database credentials, \`.env.production\`, diagnostics or real medical answers.

## Project Context

The project was developed during an RTU professional internship in the context of a group project. It combines application development, questionnaire authoring, patient workflow, access-control work, testing and Docker-based deployment preparation.

## My Contribution

My contribution included significant implementation work on the Laravel application, patient workflow, access-control logic, anonymized result handling, Docker deployment, public HTTPS demo setup and system testing.
