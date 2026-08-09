# Pašreizējā projekta tehniskais audits

Audita datums: 2026-08-09  
Projekts: `C:\xampp\htdocs\Kontroldarbu-sistema`  
Git zars audita sākumā: `universal-form-builder`  
Audita veids: statiska koda, struktūras, maršrutu, datubāzes migrāciju, UI un testu analīze. Esošais kods un dati netika mainīti.

## Kopsavilkums

Projekts ir Laravel 13 modulārs monolīts ar vienu aktīvu universālo formu/testu izveides un izpildes plūsmu. Tajā ir organizāciju izolācija, lomu un atļauju sistēma, versētas un pēc publicēšanas nemaināmas formas, publiskācijas, anonīma/autentificēta/ielūguma/piekļuves koda izpilde, autosave ar revision un idempotency kontroli, servera taimeris, automātiska un manuāla vērtēšana, LV/EN/RU saturs, piekrišanas pierādījumi, privāti pielikumi, eksporti un audit logs.

Vienlaikus DB, modeļos, kontrolieros un skatos ir saglabāts vecais quiz modulis (`tests`, `questions`, `options`, `submissions`, `answers`). Tā routes ir apzināti izņemtas, tātad šis modulis pašlaik nav sasniedzams caur aktīvo HTTP interfeisu. Aktīvajā plūsmā jālieto `Form`, `FormVersion`, `FormComponent`, `FormSubmission` un `SubmissionAnswer`, nevis līdzīgi nosauktie legacy modeļi.

## 1. Arhitektūra un tehnoloģijas

### Runtime un pakotnes

- PHP prasība: `^8.3`; auditā izmantots PHP 8.5.9.
- Laravel constraint: `^13.8`; instalēts `laravel/framework 13.20.0`.
- Datubāze lokālajā dokumentētajā konfigurācijā: SQLite; Laravel atbalsta arī konfigurētos DB draiverus. Testi izmanto in-memory SQLite.
- Frontend: Blade, Vite 8.1.5, Tailwind CSS 4, vanilla ES module JavaScript; nav Vue/React/Alpine.
- `laravel/tinker 3.0.2`: interaktīva Laravel konsole.
- `openspout/openspout 5.8.0`: XLSX eksports.
- Dev: PHPUnit 12.5.31, Mockery, Faker, Collision, Pint, Pail, Pao.
- Node auditā: 24.18.0; npm 11.16.0.

### Galvenās mapes

- `app/Domain/`: biznesa loģika. `Forms` satur authoring/builder/lokalizācijas komponentes, `Submissions` izpildi, conditions un scoring, `Audit` audit log, `Exports` eksportus.
- `app/Http/Controllers/`: plāni HTTP adapteri. Daži vecā moduļa kontrolieri ir saglabāti, bet nav pieslēgti routes.
- `app/Http/Requests/`: validācija un atsevišķos gadījumos autorizācija.
- `app/Models/`: Eloquent modeļi abām datu plūsmām.
- `app/Policies/`: `FormPolicy`, `FormSubmissionPolicy`, `OrganisationPolicy`.
- `app/Http/Middleware/`: globāli web grupai pievienotais `SetLocale`; reģistrēts, bet aktīvajās routes neizmantots legacy `AdminMiddleware`.
- `resources/views/`: viens kopīgs layout, universālo formu builder/runner/admin skati un legacy skati.
- `resources/js/app.js`: viss dinamiskais builder/runner JavaScript.
- `database/migrations/`: Laravel pamattabulas, legacy quiz shēma un universālā builder shēma.
- `database/seeders/`: lomas/atļaujas un local-only demo dati.
- `tests/Feature/UniversalFormWorkflowTest.php`: galvenā integrācijas specifikācija; `tests/Unit/LocalizedContentTest.php`: lokalizācijas fallback vienību testi.
- `docs/` un `IMPLEMENTATION_REPORT.md`: mērķa arhitektūras, shēmas, drošības un ieviešanas dokumenti; šis audits apraksta faktiski nolasīto kodu.

### Organizācijas princips

HTTP → Form Request/policy → Domain service → Eloquent/DB. Publicēta `FormVersion` un tās bērni tiek aizsargāti ar `FormVersion::booted()` un `ProtectsPublishedVersion`. Organizācijas ir tenant robeža; platformas administrators to apiet ar policy `before()`, pārējiem nepieciešamas aktīvas membership lomas ar konkrētu permission.

## 2. Routes

Vienīgais lietojumprogrammas HTTP route fails ir `routes/web.php` (68 aktīvas routes). `routes/api.php` nav, API routes nav realizētas. `routes/console.php` reģistrē scheduler un `inspire`; Laravel health route `/up` nāk no `bootstrap/app.php`.

### Autentifikācija un lokalizācija

- `GET /login` → `AuthController@showLogin`; `POST /login` → `AuthController@login`, `guest`, limiter `login` (5/min email+IP).
- `GET /register` → `AuthController@showRegister`; `POST /register` → `AuthController@register`, `guest`, limiter `registration` (5/min IP).
- `POST /logout` → `AuthController@logout`, `auth`.
- `POST /locale/{locale}` → closure `routes/web.php:32`; validē LV/EN/RU, saglabā session un autentificēta lietotāja `users.locale`.

### Respondenta/testa izpilde (publiskas routes ar īpašnieka pārbaudi)

- `GET /f/{publication}` → `RespondentController@show` (`publications.show`).
- `POST /f/{publication}/start` → `RespondentController@start` (`publications.start`).
- `GET /respond/{submission}` → `RespondentController@take` (`submissions.take`).
- `POST /respond/{submission}/autosave` → invokable `AutosaveController`, limiter `autosave` 120/min uz respondent+submission.
- `POST /respond/{submission}/finalize` → invokable `FinalizeSubmissionController`.
- `GET /respond/{submission}/complete` → `RespondentController@complete`.
- `GET /respond/{submission}/attachments/{attachment}` → `AttachmentController@respondentDownload`.

`FormSubmission` route binding izmanto UUID `public_id`; `Publication` izmanto nejaušu `public_key`. `RespondentController::assertOwner()` katrai submission route pārbauda autentificēta `user_id` vai session anonīmās atslēgas SHA-256 hash.

### Autentificētā administrācija un lietotāja dashboard

Visas zemāk minētās routes atrodas `auth` grupā; smalkā autorizācija ir policy/permission pārbaudēs.

- Dashboard: `GET /` → invokable `DashboardController`.
- Organizācijas: index/create/store/edit/update → `OrganisationController@index/create/store/edit/update`.
- Sistēma: `/system/audit` → `AuditLogController@index`; `/system/users`, platform admin create/promote, `/system/roles` un role update → `SystemAdministrationController`.
- Organizācijas lietotāji: `/organisations/{organisation}/users` un membership store → `UserAdministrationController@index/storeMembership`; `/users/{user}/toggle` → `toggleUser`.
- Formas: organisation form index/create, `POST /forms`, show/update/archive/duplicate → `FormController`.
- Versijas: update → `BuilderController@updateVersion`; publish/new draft → `FormController@publish/newDraft`.
- Builder: edit/preview, section CRUD/move, component CRUD/copy/move, condition create/delete → `BuilderController`.
- Publikācijas/ielūgumi: create/toggle → `PublicationController`; invitation create/revoke → `InvitationController`.
- Pielikumi: upload/download/delete → `AttachmentController`.
- Rezultāti: organisation submission index un submission show → `FormSubmissionController@index/show`.
- Vērtēšana: `PUT /submissions/{submission}/grade` → `GradingController@update`.
- Mēģinājumi: grant/extend/invalidate → `AttemptAdministrationController`.
- Eksports: index/create/download → `ExportController`.
- Organizācijas audit logs → `AuditLogController@index`.

Legacy `TestController`, `QuizController`, `SubmissionController`, `HomeController` nav sasaistīti ar routes. Legacy `resources/views/admin/tests/*`, `resources/views/admin/submissions/*`, `resources/views/student/*` nav aktīvā universālā moduļa UI.

## 3. Autentifikācija, lietotāji, lomas un administratori

`App\Models\User` izmanto Laravel session autentifikāciju, `HasFactory`, `Notifiable`, hashed password cast un `is_active`. Galvenās relācijas:

- `memberships()` → `OrganisationMembership` → organisation un organisation-scope lomas;
- `globalRoles()` → many-to-many `Role` caur `user_roles`;
- aktīvajam `FormSubmission` nav deklarēta pretējā `User::formSubmissions()` relācija; sasaite ir `FormSubmission::user()`.
- legacy `Submission` ar User nav FK/Eloquent relācijas; tur ir tikai teksta `student_id` un `student_name` snapshot.

Admins jaunajā sistēmā ir lietotājs ar globālo lomu `platform_admin`; to pārbauda `User::isPlatformAdmin()`. `User::hasOrganisationPermission()` platform adminam dod pilnu piekļuvi, pārējiem pārbauda aktīvu `organisation_memberships` ierakstu, membership lomu un permission.

Pirmo platform adminu veido interaktīvā komanda `php artisan app:create-admin` (`CreateAdmin`). Tā izmanto cache lock + DB row lock, strādā tikai, ja nav neviena platform admina, nepieļauj esoša respondenta paaugstināšanu un neprasa paroli CLI argumentā. Papildu adminus platform admins rada vai paaugstina `/system/users` UI ar `SystemAdministrationController`; darbības auditē.

Seeder lomas: `platform_admin`, `organisation_admin`, `form_creator`, `reviewer`, `respondent`. Atļaujas aptver organisation, forms, submissions, exports, audit, users un `publications.respond` (pēdējā pašreiz netiek tieši pārbaudīta public respondent flow).

Legacy `users.role` (`admin|student`), `User::isAdmin()/isStudent()` un `AdminMiddleware` ir saglabāti. `AdminMiddleware` alias ir reģistrēts, bet neviena aktīva route to nelieto; jaunās admin tiesības nenosaka `users.role`.

Lietotāju dzēšana nav realizēta. `/users/{user}/toggle` tikai pārslēdz `is_active`; pats sevi administrators atslēgt nevar. Atsevišķi FK deletion noteikumi ir dažādi (`form_submissions.user_id` nullOnDelete, bet membership/forms u.c. var restrict/cascade), tādēļ tieša DB dzēšana nav paredzēts UI process.

## 4. Admin panelis un rezultāti

Nav atsevišķa admin layout; viss izmanto `resources/views/layouts/app.blade.php`. Platform admin navigācijā redz organisations, system users, roles un audit. Organizācijas vadības saites dashboardā parādās pēc permission.

- Dashboard: `DashboardController` + `resources/views/dashboard.blade.php`.
- Sistēmas lietotāji/lomas: `SystemAdministrationController` + `resources/views/system/users.blade.php`, `system/roles.blade.php`.
- Organizācijas biedri: `UserAdministrationController` + `resources/views/users/index.blade.php`.
- Organizācijas: `OrganisationController` + `resources/views/organisations/index.blade.php`, `edit.blade.php`.
- Builder: `FormController`, `BuilderController`, domain services + `resources/views/forms/*`.
- Aktīvais rezultātu saraksts: `FormSubmissionController@index` + `resources/views/submissions/index.blade.php`.
- Konkrēta izpildījuma rezultāts: `FormSubmissionController@show` + `resources/views/submissions/show.blade.php`.

Katrs aktīvā admin rezultātu saraksta ieraksts ir viena `form_submissions` rinda, ko `SubmissionService::start()` rada testa sākšanas brīdī. Tādēļ sarakstā ir ne tikai pabeigti rezultāti, bet arī `in_progress`, `expired`, `cancelled`, `awaiting_grading`, `submitted`, `graded`. Filtri: form, status, grading status, publication, user, datumu intervāls.

Konkrēto lietotāju nosaka `form_submissions.user_id → users.id`; `FormSubmissionController@index` eager-load `user`, un UI jau rāda `user.email`. Detail view arī rāda email. Anonīmam lietotājam `user_id` ir null, UI rāda “anonymous”; tehniski izpilde ir piesaistīta `anonymous_key_hash` un/vai `invitation_id`, taču šīs vērtības UI nerāda. Ja nākotnē vajag vārdu/student ID/ielūguma reference, dati jāielādē/papildina `FormSubmissionController@index/show` un jāattēlo `resources/views/submissions/index.blade.php`/`show.blade.php`; pašreiz User jau ir pieejams index un caur relāciju detail, bet invitation relācija `FormSubmission` modelī nav deklarēta.

## 5. Formas/testa dzīves cikls

1. `FormController@store` validē `StoreFormRequest` un izsauc `FormAuthoringService::create()`.
2. Transaction izveido `forms`, `form_versions` v1 draft, pirmo `form_sections` rindu un preset komponentes (`blank`, `test`, `patient_questionnaire`).
3. `BuilderController` + `BuilderService`/`FormAuthoringService` rediģē versijas lokalizēto saturu, sadaļas, komponentes, opcijas, scoring un nosacījumus.
4. `FormAuthoringService::publish()` validē references un scoring correct answers, aprēķina SHA-256 `content_hash`, atzīmē versiju `published` un formu `published`.
5. `PublicationController@store` piesaista tieši publicētu versiju un definē access mode, logu, mēģinājumu limitu, timeri, rezultāta redzamību, anonymous/identified/consent/autosave/resume.
6. `RespondentController@start` → `SubmissionService::start()` autorizē piekļuvi, meklē atsākamu mēģinājumu, ievēro limitu/grantus, rada `form_submissions`.
7. `respondent.take` + `forms.components.generic` renderē publicētās versijas saturu; `resources/js/app.js` vada lapas, nosacījumus, progresu, autosave un timeri.
8. Autosave → `SubmissionService::autosave()` → `submission_answers` upsert + `submission_mutations` idempotency ieraksts + revision pieaugums; consent rada/atjauno `consent_records`.
9. Finalize nosūta pilnu jaunāko answers snapshot uz `finalizeWithSnapshot()`, serverī pārbauda redzamo required/consent, izsauc `ScoringService` un saglabā kopsummas `form_submissions`, per-answer punktus `answer_scores`.
10. Respondents redz `respondent/complete.blade.php` atbilstoši publication visibility. Admin ierakstu redz `submissions/index` un `submissions/show`.

Jaunā draft versija kopē struktūru, stable keys, option values, rules un fiziski nokopē pielikumus. Publicētās versijas labošana nav paredzēta; jauns saturs iet jaunā draft versijā.

## 6. Jautājumu un komponentu tipi

Avots ir `ComponentRegistry::DEFINITIONS`. Visi komponenti glabājas `form_components.type`; konfigurācija ir `settings` JSON, opcijas — `component_options`, required — `is_required`, sākotnējā/redzamības īpašība — `visible`, punkti — `max_points`, pareizās atbildes — vienā `scoring_rules.rules` JSON ierakstā.

| Type identifier | UI un atbildes glabāšana | Automātiska vērtēšana |
|---|---|---|
| `form_title`, `heading`, `explanatory_text` | Satura bloki; atbildi nerada | Nē |
| `image` | Privāta attachment bilde ar title/caption; nav atbildams “attēla jautājums” | Nē |
| `file_attachment` | Privāta lejupielāde; nav atbildams | Nē |
| `short_text` | `<input>`; trimots string/null `submission_answers.value` JSON | Nē; precīza teksta pareizā atbilde nav realizēta |
| `long_text` | `<textarea>`; trimots string/null | Nē; var būt manual grading |
| `number` | number input; normalizēts float | `numeric_exact`, `numeric_tolerance` |
| `date`, `time` | date `Y-m-d`, time `H:i` string | Nē |
| `yes_no` | radio; boolean | `yes_no` |
| `single_choice` | radio; option stabilais UUID `value` | `single_choice` |
| `multiple_choice` | checkbox kopa; unikāls string UUID masīvs | `multiple_all_or_nothing`, `multiple_partial` |
| `dropdown` | select; option UUID | `single_choice` |
| `rating_scale`, `linear_scale` | range; float, min/max un lokalizēti gala label | Nē |
| `consent_checkbox` | checkbox; boolean + atsevišķs consent evidence | Nē |

Admin konfigurē tipus `resources/views/forms/builder.blade.php`; JS tikai parāda tipam atļautos settings un options. `generic.blade.php` tos renderē respondentam un preview. Opciju labels var tulkot, bet to UUID `value` paliek stabils, tādēļ tulkojuma maiņa nesalauž scoring.

`required` tiek pārbaudīts serverī finalizācijā tikai atbildamiem un pašlaik redzamiem komponentiem. `visible=false` nozīmē sākotnēji slēptu komponenti; papildus ir conditional show/hide. Slēptās atbildes audit/resume stabilitātei paliek DB, bet required un scoring tās ignorē.

## 7. Scoring

Galvenā loģika: `app/Domain/Submissions/ScoringService.php`; konfigurācijas pārbaude: `app/Domain/Forms/ScoringRuleValidator.php`; manuālā vērtēšana: `GradingController@update`.

- Pareizā atbilde ir `scoring_rules.rules.correct`; numeric tolerance papildus `rules.tolerance`.
- Single/dropdown/yes-no salīdzina string vērtības strict `===` pēc cast uz string.
- Multiple all-or-nothing sakārto abus masīvus un salīdzina exact set.
- Partial: `max * (pareizi - nepareizi) / pareizo_skaits`, ierobežots 0..max.
- Numeric exact izmanto float `===`; tolerance — absolūto starpību `<= tolerance`.
- `maximum_points` ir redzamo score/manual komponentu max punktu summa.
- `automatic_points` ir auto summa; sākotnējais `final_points=automatic_points`.
- Manuālie punkti glabājas `answer_scores.manual_points`; pēc grading `form_submissions.manual_points` ir summa un `final_points=automatic+manual`; percentage ir `final/maximum*100`.
- Katras atbildes auto/manual/final punkti un reviewer metadata ir `answer_scores`.
- Teksta exact-match scoring nav realizēts; attiecīgi nav arī case sensitivity vai whitespace noteikumu. Input normalization tikai apgriež sākuma/beigu whitespace `short_text`/`long_text` atbildēm.
- Hidden komponenti scoring laikā tiek izlaisti.

## 8. Datubāzes shēma

Zemāk ir reālie migrāciju tabulu nosaukumi.

### Identity un tenancy

- `users`: id, name, unique email, password, email_verified_at, remember_token, legacy enum `role`, unique nullable `student_id`, `is_active`, `locale`, timestamps. Modelis `User`.
- `organisations`: name, unique slug, is_active, settings JSON, soft delete. `Organisation`.
- `roles`: unique name, display_name, scope. `Role`.
- `permissions`: unique name, display_name. `Permission`.
- `role_permissions`: role_id + permission_id composite PK.
- `user_roles`: user_id + role_id composite PK (globālās lomas).
- `organisation_memberships`: organisation_id, user_id, is_active, unique pair. `OrganisationMembership`.
- `membership_roles`: membership_id + role_id composite PK.

### Authoring un publikācijas

- `forms`: organisation_id, created_by→users, name, slug, status, preset_key, translations JSON, soft delete; unique organisation+slug. `Form`.
- `form_versions`: form_id, version_number, status, settings JSON, content_hash, created_by, published_at, vēlāk pievienoti title, description, translations JSON. `FormVersion`.
- `form_sections`: form_version_id, stable_key, title, description, display_order, visible, translations. `FormSection`.
- `form_components`: form_version_id, form_section_id, stable_key, type, label/description/help_text, order, is_required, visible, max_points, manual_grading, settings/translations JSON. `FormComponent`.
- `component_options`: form_component_id, stable_key, label, value, order, translations. `ComponentOption`.
- `validation_rules`: form_component_id, rule_type, order, parameters JSON, message_translations JSON. `ValidationRule`; shēma/modelis eksistē, bet builder CRUD pašlaik nav realizēts.
- `conditional_rules`: version, source component, operator, comparison_value JSON, priority. `ConditionalRule`.
- `conditional_actions`: rule, action, optional target component/section. `ConditionalAction`.
- `scoring_rules`: unique component, strategy, max_points, rules JSON. `ScoringRule`.
- `publications`: organisation/form/version FK, public_key, name/status/access, hashed access code, open/close, attempts, timer, result/correct answer settings, identity/consent/autosave/resume flags. `Publication`.
- `invitations`: publication_id, unique token_hash, recipient_reference, max_uses/uses, expiry/revocation. `Invitation`.
- `attachments`: organisation, polymorphic attachable, uploaded_by, private disk/path, filename/MIME/size/SHA-256/status. `Attachment`.

### Izpilde, rezultāti, auditēšana

- `form_submissions`: public UUID, organisation/publication/version, nullable user/invitation, anonymous hash, attempt/status/time/revision, maximum/automatic/manual/final points, percentage, grading status, invalidation reason. `FormSubmission`.
- `submission_answers`: submission, component, value JSON, display_value, answer_revision, saved_at; unique submission+component. `SubmissionAnswer`.
- `submission_mutations`: submission, client UUID, acknowledged revision; unikālais pāris nodrošina idempotency. `SubmissionMutation`.
- `answer_scores`: unique answer, automatic/manual/final points, comment, graded_by, graded_at. `AnswerScore`.
- `attempt_grants`: publication un respondent identity, additional_attempts, reason, granted_by. `AttemptGrant`.
- `consent_records`: submission, component, version, decision, consent_text_hash, recorded/withdrawn, `content_locale`; unique submission+component. `ConsentRecord`.
- `exports`: public UUID, organisation/requester/form, format/status/filters, storage path/expiry/error. `Export`.
- `audit_logs`: optional organisation/actor, action, polymorphic subject, request_id, salted IP hash, metadata, created_at. `AuditLog`.

### Legacy quiz

- `tests` (`Test`): title, description, duration, active/availability.
- `questions` (`Question`): test FK cascade, text, enum multiple_choice/true_false/essay, points, order.
- `options` (`Option`): question FK cascade, text, is_correct.
- `submissions` (`Submission`): test FK cascade, student_id/name teksti, timestamps, score/total/is_auto_submitted; unique test+student_id. Nav users FK.
- `answers` (`Answer`): submission/question FK cascade, nullable option FK set-null, essay_answer, is_correct.

### Laravel infrastruktūra

`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `sessions` ir framework atbalsta tabulas. Paroles reset routes/UI šajā projektā nav realizētas.

## 9. Lokalizācija LV/EN/RU

- UI tulkojumi: `lang/lv/messages.php`, `lang/en/messages.php`, `lang/ru/messages.php`.
- Atļautās valodas/default/fallback: `config/form_locales.php`: LV, EN, RU; default LV; fallback no `app.fallback_locale` (dokumentēti EN).
- `SetLocale` izvēlas session → user locale → default.
- `LocalizedContent` fallback secība: requested translation → default LV → base kolonna/settings → konfigurētais fallback → pirmā pieejamā supported valoda.
- `HasLocalizedContent` concern modeļiem dod resolver helperus.
- Builder locale tabs: `resources/views/forms/partials/locale-tabs.blade.php`; JS pārslēdz paneli un rāda filled/empty status.
- Lokalizēti: versijas title, description, completion_text, result_text; section title/description; component label/description/help/placeholder/consent/scale labels/image title+caption; option label.
- Administratīvais `forms.name`, publication name, lomu/atļauju tehniskie nosaukumi un vairāki status/strategy/operator identifikatori nav lokalizēts form content.
- Ir arī cieti kodētas UI virknes (`Invalid credentials.`, detail sadaļas `Consent`, invitation `Reference/Max uses/Expires`, registry angļu tipu nosaukumi), tātad UI lokalizācija nav pilnīga.
- Preview valodu izvēlas `?locale=` bez session maiņas. Izpildē valodu izvēlas globālais locale switch; JS pirms locale submit piespiedu kārtā saglabā dirty answers.
- Consent evidence glabā faktiski atrisinātā teksta `content_locale` un precīzā teksta SHA-256 hash; audit metadata glabā requested un resolved locale, bet ne pašu sensitīvo tekstu.

## 10. Pilnais testa izpildes request flow

`GET /f/{publication}` → `RespondentController@show` → `respondent/landing.blade.php` → `POST /f/{publication}/start` → `SubmissionService::start()` → access/window/identity/attempt pārbaude → `form_submissions` → redirect uz `GET /respond/{public_id}` → `RespondentController@take` → `respondent/take.blade.php` → `forms/components/generic.blade.php` + `resources/js/app.js`.

Input/change → 700 ms debounce → `POST /respond/{public_id}/autosave` JSON (`expected_revision`, UUID mutation, full visible/ne-visible DOM answer snapshot) → `AutosaveController` → owner check → `SubmissionService::autosave()` → row lock/revision/idempotency → normalize/validate → `submission_answers`, consent evidence, `submission_mutations`, revision response.

Submit/timer → JS nosūta pēdējo pilno snapshot uz `POST .../finalize` → `FinalizeSubmissionController` → `finalizeWithSnapshot()` → required/consent + server visibility → `ScoringService` → `answer_scores` + `form_submissions` totals/status → audit + notification formas creatoram → JSON redirect → `GET .../complete` → `respondent/complete.blade.php`. Scheduler `submissions:finalize-overdue` katru minūti finalizē nokavētos mēģinājumus kā `expired`.

Admin flow: dashboard organisation submissions link → `FormSubmissionController@index` → `submissions/index.blade.php` → show → `submissions/show.blade.php`; manual score PUT → `GradeSubmissionRequest` policy `grade` → `GradingController`.

## 11. Frontend

Galvenais un vienīgais aktīvais layout ir `resources/views/layouts/app.blade.php`; atsevišķs admin layout nav. Tas ielādē `resources/css/app.css` un `resources/js/app.js` caur Vite, satur CSRF meta, navigāciju, locale forms un flash/errors.

Builder UI ir server-rendered `forms/builder.blade.php`; dynamic tips/settings un locale tabs tiek vadīti ar vanilla JS. Reordering lielākoties ir POST formās; section pārvietošana no select notiek ar `fetch` un reload. Drag-and-drop nav realizēts.

Runner UI ir `respondent/take.blade.php`; katra section ir lapa. JS nodrošina conditional visibility, progress, previous/next, client validation, debounced autosave, offline/retry status, beforeunload brīdinājumu, locale-before-save, pilna snapshot finalize un sekundes timeri. Taimeris UI ir klientā, bet termiņu autoritatīvi pārbauda serveris.

## 12. Testi un build

`tests/Feature/UniversalFormWorkflowTest.php` satur 42 Feature testus; `tests/Unit/LocalizedContentTest.php` — 3 Unit testus. Audita palaišanas rezultāts: **45 passed, 309 assertions**, exit code 0.

Nosegts: reģistrācijas role escalation un login throttling; first-admin concurrency/ierobežojumi; tenant isolation; formu versijas/immutability/archive/duplicate; komponentu kopēšana/pārvietošana; presets; autosave/revision/idempotency/resume/final snapshot; deadline/scheduler; access code/invitations; consent un locale evidence; partial/numeric/manual scoring; grading audits; exports un CSV formula safety/XLSX sheets; private attachments; ownership; conditions/hidden scoring; option stability; publikāciju validācija; LV/EN/RU builder/preview/runner; legacy tabulu saglabāšana un legacy routes noņemšana.

Trūkst vai nav tieši testēts: faktiska e-pasta/notification transport piegāde un queue worker kļūmes/retry; ļoti liels async export job end-to-end; failu malware skenēšana (nav realizēta); pilns accessibility/browser E2E; rate limiting autosave; visu 17 tipu katra invalid input robeža; invitation respondenta admin UI identifikācija; user deaktivācijas ietekme uz jau aktīvu session; DB portability uz MySQL/PostgreSQL; slodzes skripts nav palaists; legacy neaktīvā koda darbība nav testēta kā funkcionāls modulis.

Frontend: sākotnējais `npm run build` PowerShell vidē tika bloķēts tikai tāpēc, ka `npm.ps1` izpildi aizliedz lokālā ExecutionPolicy. Ekvivalents `npm.cmd run build` pabeidzās sekmīgi ar Vite 8.1.5 (3 modules transformed, exit code 0). Build output ir ignorēts Git un darba koku nemainīja.

## 13. Git stāvoklis

- Zars: `universal-form-builder`.
- Audita sākumā `git status --short`: tukšs (necommitotu izmaiņu nebija).
- Pēc testiem/build un pirms šī dokumenta izveides: tukšs.
- Šajā uzdevumā netika veikts commit vai push.
- Sagaidāmā vienīgā izmaiņa pēc audita: `?? CURRENT_PROJECT_AUDIT.md`.

## 14. Problēmas un tehniskais parāds

### Augstāka nozīme

1. Divas paralēlas domēna shēmas ar ļoti līdzīgiem nosaukumiem rada augstu kļūdainas izstrādes risku. `Submission` ir legacy, `FormSubmission` ir aktīvais; `Answer` ir legacy, `SubmissionAnswer` ir aktīvais. Legacy routes ir izņemtas, bet kontrolieri/modeļi/skati paliek.
2. Legacy `Submission::calculateScore()` summē `answers.points`, bet `answers` migrācijā šāda lauka nav. Ja legacy routes atjaunotu, auto-submit scoring būtu bojāts. Legacy `QuizController` arī pieļauj dalīšanu ar nulli, ja total points būtu 0. Pašlaik kods nav sasniedzams.
3. `AdminMiddleware` pārbauda legacy `users.role`, kamēr aktīvā sistēma izmanto `user_roles`; alias ir reģistrēts. Nejauša tā pielikšana jaunai route dotu atšķirīgu autorizācijas modeli.
4. Lietotāja hard-delete lifecycle nav realizēts; UI tikai deaktivē. Tas ir saprātīgs drošs stāvoklis, bet retention/anonymisation/process ir jādefinē pirms personas datu produkcijas.

### Vidēja nozīme

5. `validation_rules` shēma un modelis eksistē, bet builderā nav CRUD un SubmissionService tos neizpilda; praktiski tiek izmantoti tikai ComponentRegistry settings noteikumi.
6. `publications.respond` permission ir seedots, bet authenticated respondent access pārbauda tikai aktīvu membership, ne šo permission. Jebkura aktīva membership loma var sākt authenticated publication.
7. `correct_answers_visible` rāda scoring rule correct vērtības jebkuram submission ar `submitted_at`, tostarp potenciāli `expired` vai `awaiting_grading`; business noteikums jāapstiprina. `result_visibility=none` joprojām rāda statusu un version result/completion tekstu, bet ne score.
8. Admin submission UI identificē tikai email vai “anonymous”; invitation `recipient_reference`, User name/student_id un consent `content_locale/hash` netiek parādīti. Dati daļēji ir DB, bet ne pilnībā eager-loaded/renderēti.
9. `FormSubmission` modelim nav `invitation()` relācijas; User modelim nav submissions relācijas. FK ir pareizi, bet navigācija/audita vaicājumi ir mazāk skaidri.
10. Builder nosacījumu UI rada vienu action katram rule un neļauj rediģēt/reorder; servera modelis tehniski atbalsta vairākus actions.
11. Visas builder un runner uzvedības ir vienā `resources/js/app.js`; pieaugot funkcionalitātei, būs grūtāk izolēti testēt un uzturēt.

### Drošība/operācijas un kvalitāte

12. Pozitīvi: piekļuves kodi ir `Hash::make`, invitations/anonymity ir SHA-256, private attachment autorizācija ir serverī, public IDs nav secīgi, scoring ir serverī, revisions izmanto row lock un mutation UUID, audit metadata filtrē sensitīvus top-level keys, CSV formulas tiek neitralizētas.
13. AuditService filtrē tikai precīzus top-level sensitīvo key nosaukumus; nested vai citādi nosaukta sensitīva metadata netiktu automātiski izņemta. Pašreizējie call sites pārsvarā sūta drošu metadata.
14. Attachment MIME/extension/size validācija ir, taču malware scanning/DLP nav realizēts; to atzīst README.
15. MFA, password reset UI/routes, email verification enforcement, formāla accessibility pārbaude un production monitoring nav realizēti.
16. Daļa kontrolieru un Blade ir stipri saspiesti vienas rindas stilā, un legacy failos ir novecojuši komentāri/angļu hardcoded teksti; tas apgrūtina review, lai gan funkcionalitāti pats par sevi nemaina.
17. Legacy un universālajā shēmā deletion politika atšķiras (legacy cascade, jaunajā pārsvarā restrict/nullOnDelete). Datu retention ir jāvada ar apzinātiem statusiem/archive, ne ad-hoc delete.

## 15. PROJECT MAP

### User management

- Routes: `routes/web.php` — `system.users`, `system.platform-admins.*`, `users.index`, `memberships.store`, `users.toggle`.
- Controllers: `SystemAdministrationController`, `UserAdministrationController`, `AuthController`.
- Models/tables: `User/users`, `Role/roles`, `Permission/permissions`, `OrganisationMembership/organisation_memberships`, pivot tabulas.
- Views: `system/users.blade.php`, `system/roles.blade.php`, `users/index.blade.php`, `auth/*`.
- Bootstrap: `app/Console/Commands/CreateAdmin.php`.

### Organisations and authorization

- Routes: `organisations.*`, organisation-scoped forms/users/submissions/exports/audit.
- Controller/model/views: `OrganisationController`, `Organisation`, `organisations/index.blade.php`, `organisations/edit.blade.php`.
- Policies: `OrganisationPolicy`, `FormPolicy`, `FormSubmissionPolicy`.
- Permission logic: `User::hasOrganisationPermission()`; seed mapping `RolePermissionSeeder`.

### Form builder

- Routes: `forms.*`, `builder.versions.*`, `builder.sections.*`, `builder.components.*`, `builder.conditions.*`, attachments.
- Controllers: `FormController`, `BuilderController`, `AttachmentController`.
- Services: `FormAuthoringService`, `BuilderService`, `ComponentRegistry`, `ScoringRuleValidator`, `LocalizedContent`.
- Models: `Form`, `FormVersion`, `FormSection`, `FormComponent`, `ComponentOption`, `ValidationRule`, `ConditionalRule/Action`, `ScoringRule`, `Attachment`.
- Views: `forms/create`, `index`, `show`, `builder`, `preview`, `partials/locale-tabs`, `components/generic`.
- JS: `resources/js/app.js` component form + locale editor + move select sadaļas.

### Publication and access

- Routes/controllers: `publications.store/toggle` → `PublicationController`; `invitations.store/revoke` → `InvitationController`; public landing/start → `RespondentController`.
- Models/tables: `Publication/publications`, `Invitation/invitations`, `AttemptGrant/attempt_grants`.
- Access enforcement: `SubmissionService::authorizeAccess()`.

### Test runner

- Routes: `publications.show/start`, `submissions.take/autosave/finalize/complete`, respondent attachments.
- Controllers: `RespondentController`, `AutosaveController`, `FinalizeSubmissionController`, `AttachmentController`.
- Services: `SubmissionService`, `ConditionalLogicService`, `ComponentRegistry`.
- Views: `respondent/landing`, `take`, `complete`; renderer `forms/components/generic`.
- JS: `resources/js/app.js` runner bloks.
- DB: `form_submissions`, `submission_answers`, `submission_mutations`, `consent_records`.

### Scoring and grading

- Logic: `ScoringService`, `ScoringRuleValidator`, `GradingController`.
- Validation/auth: `GradeSubmissionRequest`, `FormSubmissionPolicy::grade`.
- DB/models: `scoring_rules/ScoringRule`, `answer_scores/AnswerScore`, totals `form_submissions`.
- UI: builder scoring controls; `submissions/show.blade.php` manual grading; `respondent/complete.blade.php` controlled result display.

### Results, attempts, exports and audit

- Results: `FormSubmissionController` + `submissions/index/show`.
- Attempts: `AttemptAdministrationController`; scheduler command `FinalizeOverdueSubmissions`.
- Exports: `ExportController`, `ExportService`, `GenerateExport`, `exports/index.blade.php`, `exports` table, private local storage.
- Audit: `AuditService`, `AuditLogController`, `audit/index.blade.php`, `audit_logs`.
- Notification: `SubmissionFinalizedNotification` nosūtīta formas creatoram pēc pirmās sekmīgās finalizācijas.

### Localization

- UI dictionaries: `lang/{lv,en,ru}/messages.php`.
- Config/middleware: `config/form_locales.php`, `SetLocale`.
- Domain/model helpers: `LocalizedContent`, `LocalizedContentRules`, `HasLocalizedContent` un localized metodes version/section/component/option.
- Admin UI: `forms/partials/locale-tabs.blade.php`; preview locale query; runner locale form ar pre-save.

### Database

- Pilnā jaunā shēma: `database/migrations/2026_08_01_000000_create_universal_form_builder_tables.php`.
- Version content papildinājums: `2026_08_02_000100_add_localized_content_to_form_versions.php`.
- Consent locale: `2026_08_02_000200_add_content_locale_to_consent_records.php`.
- Legacy shēma: `2026_07_18_191211` līdz `191215` migrācijas.
- Lomu dati: `database/seeders/RolePermissionSeeder.php`.

### Legacy quiz (neaktīvs)

- Controllers: `TestController`, `QuizController`, `SubmissionController`, `HomeController`.
- Models: `Test`, `Question`, `Option`, `Submission`, `Answer`.
- Views: `admin/tests/*`, `admin/submissions/*`, `student/*`, `home.blade.php`.
- Routes: nav realizētas/ir apzināti retired; nejaukt ar aktīvo universālo formu plūsmu.

## Audita izpildes rezultāts

- Audit completed: jā.
- Tests status: PASS — 45 tests, 309 assertions.
- npm build status: PASS ar `npm.cmd run build`; tiešais PowerShell `npm run build` wrapper bija bloķēts ar lokālo ExecutionPolicy.
- Git branch: `universal-form-builder`.
- Changed files: tikai `CURRENT_PROJECT_AUDIT.md`.

## Database migration to MySQL

2026-08-09 pēc sākotnējā audita lokālā development aplikācija tika pārslēgta no saglabātā `database/database.sqlite` avota uz XAMPP MariaDB 10.4.32 datubāzi `kontroldarbu_sistema` (`utf8mb4`, `utf8mb4_unicode_ci`). Visas 13 Laravel migrācijas MySQL/MariaDB pusē ir `Ran`. Esošie SQLite dati tika pārnesti, saglabājot ID, ārējās atslēgas, UUID/stable keys, JSON, timestamp un paroļu hash; svarīgo tabulu row counts sakrīt un ārējo atslēgu orphan skaits ir 0. SQLite oriģināla SHA-256 pirms un pēc importa palika `44E7A210C0A2422B1EEA8AC7AD82B890350C32EF8238F0DF24ED637E6E0F6D43`. PHPUnit konfigurācija apzināti palika uz in-memory SQLite.
