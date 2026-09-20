<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Models\AnonymizedResultHandoff;
use App\Models\FormComponent;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\Permission;
use App\Models\Publication;
use App\Models\Role;
use App\Models\SubmissionAnswer;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnonymizedResultHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_sensitive_components_are_saved_and_exported_in_the_package_manifest(): void
    {
        [$doctor, $organisation, $form, $version, $normal, $name, $email, $phone] = $this->graph();
        $this->assertFalse($normal->fresh()->is_sensitive);
        $this->assertTrue($name->fresh()->is_sensitive);
        $this->assertTrue($email->fresh()->is_sensitive);
        $this->assertTrue($phone->fresh()->is_sensitive);
        $manifest = app(\App\Domain\Forms\QuestionnairePackageService::class)->manifest($form, $version);
        $portable = collect($manifest['sections'])->flatMap(fn ($section) => $section['components'])->keyBy('stable_key');
        $this->assertFalse($portable[$normal->stable_key]['is_sensitive']);
        $this->assertTrue($portable[$name->stable_key]['is_sensitive']);
        $this->assertTrue($portable[$email->stable_key]['is_sensitive']);
        $this->assertTrue($portable[$phone->stable_key]['is_sensitive']);
    }

    public function test_builder_http_save_persists_sensitive_flag_and_unchecking_clears_it(): void
    {
        [, , $form, $published, $normal] = $this->graph();
        $draft = app(FormAuthoringService::class)->createDraftFrom($published, $form->creator);
        $component = $draft->components()->where('stable_key', $normal->stable_key)->firstOrFail();
        $this->assertFalse($component->is_sensitive);
        $payload = ['visible' => 1, 'is_sensitive' => 1, 'translations' => ['lv' => ['label' => $component->label]], 'scoring_strategy' => 'none'];

        $this->actingAs($form->creator)->put(route('builder.components.update', [$form, $component]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $component->refresh();
        $this->assertTrue($component->is_sensitive);
        $this->actingAs($form->creator)->get(route('forms.builder', $form))->assertOk()->assertSee('name="is_sensitive" value="1" checked', false);

        $payload['is_sensitive'] = 0;
        $this->actingAs($form->creator)->put(route('builder.components.update', [$form, $component]), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($component->fresh()->is_sensitive);
    }

    public function test_doctor_anonymized_result_and_export_follow_questionnaire_order(): void
    {
        $organisation = Organisation::create(['name' => 'Ordered research', 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
        [$doctor] = $this->member('doctor', $organisation);
        [$creator] = $this->member('form_creator', $organisation);
        $authoring = app(FormAuthoringService::class);
        $form = $authoring->create($organisation->id, $creator, 'Ordered study', 'blank');
        $version = $form->versions()->firstOrFail();
        $firstSection = $version->sections()->firstOrFail();
        $secondSection = $authoring->addSection($version, 'Second section');
        $firstLate = $authoring->addComponent($version, $firstSection, ['type' => 'short_text', 'label' => 'First late', 'options' => []]);
        $firstEarly = $authoring->addComponent($version, $firstSection, ['type' => 'short_text', 'label' => 'First early', 'options' => []]);
        $secondLate = $authoring->addComponent($version, $secondSection, ['type' => 'short_text', 'label' => 'Second late', 'options' => []]);
        $secondEarly = $authoring->addComponent($version, $secondSection, ['type' => 'short_text', 'label' => 'Second early', 'options' => []]);
        $firstSection->update(['display_order' => 20]);
        $secondSection->update(['display_order' => 10]);
        $firstLate->update(['display_order' => 20]);
        $firstEarly->update(['display_order' => 10]);
        $secondLate->update(['display_order' => 20]);
        $secondEarly->update(['display_order' => 10]);
        $published = $authoring->publish($version);

        $patient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id, 'slot_number' => 1, 'first_name' => 'Ordered', 'last_name' => 'Patient']);
        $publication = Publication::create(['organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $published->id, 'public_key' => Str::random(20), 'name' => 'Ordered study', 'status' => 'active', 'access_mode' => 'invitation', 'identified_required' => true, 'attempt_limit' => 1]);
        $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', Str::random(64))]);
        $assignment = PatientFormAssignment::create(['patient_case_id' => $patient->id, 'publication_id' => $publication->id, 'invitation_id' => $invitation->id, 'label' => 'Ordered study', 'display_order' => 1]);
        $submission = FormSubmission::create(['public_id' => Str::uuid(), 'organisation_id' => $organisation->id, 'publication_id' => $publication->id, 'form_version_id' => $published->id, 'invitation_id' => $invitation->id, 'attempt_number' => 1, 'status' => 'submitted', 'started_at' => now(), 'submitted_at' => now()]);
        foreach ([$firstLate, $secondLate, $firstEarly, $secondEarly] as $component) SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $component->id, 'value' => $component->label, 'display_value' => $component->label, 'saved_at' => now()]);
        $labels = ['Second early', 'Second late', 'First early', 'First late'];
        $assertOrder = function (string $content) use ($labels): void {
            $positions = array_map(fn ($label) => strpos($content, $label), $labels);
            foreach ($positions as $position) $this->assertIsInt($position);
            $sorted = $positions;
            sort($sorted);
            $this->assertSame($sorted, $positions);
        };

        $doctorContent = $this->actingAs($doctor)->get(route('doctor.results.show', [$patient, $assignment]))->assertOk()->getContent();
        $assertOrder($doctorContent);
        $recipient = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertRedirect();
        $handoff = \App\Models\AnonymizedResultHandoff::firstOrFail();
        $anonymousContent = $this->actingAs($recipient)->get(route('anonymized-results.show', $handoff))->assertOk()->getContent();
        $assertOrder($anonymousContent);
        $csvContent = $this->actingAs($recipient)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertDownload()->streamedContent();
        $assertOrder($csvContent);
    }

    public function test_custom_recipient_can_receive_only_anonymised_completed_result(): void
    {
        [$doctor, $organisation, , , $normal, $name, $email, $phone, $assignment, $submission, $patient] = $this->completedGraph();
        $researcher = User::factory()->create(['name' => 'Uldis Bērziņš', 'is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $researcher->id, 'is_active' => true]);
        $role = Role::create(['name' => 'researcher', 'display_name' => 'Pētnieks', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('name', 'anonymized_results.view')->firstOrFail());
        $membership->roles()->attach($role);
        SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $normal->id, 'value' => '60', 'display_value' => '60', 'saved_at' => now()]);
        foreach ([[$name, 'John Doe'], [$email, 'john@example.test'], [$phone, '+37120000000']] as [$component, $value]) SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $component->id, 'value' => $value, 'display_value' => $value, 'saved_at' => now()]);

        $this->actingAs($doctor)->get(route('doctor.results.show', [$patient, $assignment]))->assertOk()->assertSee('Uldis Bērziņš — Pētnieks');
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $researcher->id])->assertRedirect();
        $this->assertDatabaseHas('anonymized_result_handoffs', ['form_submission_id' => $submission->id, 'recipient_user_id' => $researcher->id]);
        $this->actingAs($researcher)->get(route('anonymized-results.index'))->assertOk()->assertSee($patient->patient_code)->assertDontSee('Secret Patient');
        $this->actingAs($researcher)->get(route('anonymized-results.show', \App\Models\AnonymizedResultHandoff::firstOrFail()))->assertOk()->assertSee('60')->assertDontSee('John Doe')->assertDontSee('john@example.test')->assertDontSee('+37120000000')->assertDontSee('Secret Patient')->assertDontSee('Doctor note');
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $researcher->id])->assertSessionHasErrors('recipient');
    }

    public function test_recipient_can_export_csv_and_xlsx_without_sensitive_or_patient_data(): void
    {
        [$doctor, $organisation, , , $normal, $name, $email, $phone, $assignment, $submission, $patient] = $this->completedGraph();
        $recipient = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', 'administrator')->firstOrFail());
        SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $normal->id, 'value' => '60', 'display_value' => '60', 'saved_at' => now()]);
        foreach ([[$name, 'John Doe'], [$email, 'john@example.test'], [$phone, '+37120000000']] as [$component, $value]) SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $component->id, 'value' => $value, 'display_value' => $value, 'saved_at' => now()]);
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertRedirect();
        $handoff = \App\Models\AnonymizedResultHandoff::firstOrFail();

        $csv = $this->actingAs($recipient)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertDownload('anonymized-results-'.now()->format('Ymd-His').'.csv');
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString('PAT-', $csvContent);
        $this->assertStringContainsString('60', $csvContent);
        $this->assertStringNotContainsString('John Doe', $csvContent);
        $this->assertStringNotContainsString('john@example.test', $csvContent);
        $this->assertStringNotContainsString('+37120000000', $csvContent);
        $this->assertStringNotContainsString('Secret', $csvContent);
        $this->assertStringNotContainsString('Name', $csvContent);

        $this->actingAs($recipient)->post(route('anonymized-results.export'), ['format' => 'xlsx', 'handoff_ids' => [$handoff->public_id]])->assertDownload();
        $other = User::factory()->create(['is_active' => true]);
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $other->id, 'is_active' => true]);
        $this->actingAs($other)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertForbidden();
    }

    public function test_doctor_can_bulk_handoff_completed_results_with_sensitive_data_filtered(): void
    {
        [$doctor, $organisation, , , $normal, $name, $email, $phone, $firstAssignment, $firstSubmission, $firstPatient] = $this->completedGraph();
        SubmissionAnswer::create(['form_submission_id' => $firstSubmission->id, 'form_component_id' => $normal->id, 'value' => '60', 'display_value' => '60', 'saved_at' => now()]);
        foreach ([[$name, 'BULK_SENSITIVE_NAME_ONE'], [$email, 'BULK_SENSITIVE_EMAIL_ONE'], [$phone, 'BULK_SENSITIVE_PHONE_ONE']] as [$component, $value]) {
            SubmissionAnswer::create(['form_submission_id' => $firstSubmission->id, 'form_component_id' => $component->id, 'value' => $value, 'display_value' => $value, 'saved_at' => now()]);
        }

        [$secondAssignment, $secondSubmission, $secondPatient] = $this->createAdditionalResult(
            $organisation, $doctor, $firstAssignment->publication, $normal, $name, 2, 'BULK_SECOND', 'submitted'
        );
        $recipient = $this->anonymizedRecipient($organisation);

        $this->actingAs($doctor)->get(route('doctor.dashboard', ['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id]))
            ->assertOk()
            ->assertSee('form="bulk-anonymized-handoff-form" name="assignment_ids[]" value="'.$firstAssignment->public_id.'"', false)
            ->assertSee('form="bulk-anonymized-handoff-form" name="assignment_ids[]" value="'.$secondAssignment->public_id.'"', false);

        $this->actingAs($doctor)->post(route('doctor.results.handoff.bulk'), [
            'recipient' => $recipient->id,
            'assignment_ids' => [$firstAssignment->public_id, $secondAssignment->public_id],
        ])->assertRedirect(route('doctor.dashboard', ['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id]))
            ->assertSessionHas('success', __('messages.bulk_handoff_summary', ['created' => 2, 'skipped' => 0]));

        $this->assertSame(2, AnonymizedResultHandoff::where('recipient_user_id', $recipient->id)->count());
        $handoffs = AnonymizedResultHandoff::where('recipient_user_id', $recipient->id)->get();
        foreach ($handoffs as $handoff) {
            $show = $this->actingAs($recipient)->get(route('anonymized-results.show', $handoff))->assertOk();
            $show->assertSee('Age')->assertDontSee('SENSITIVE')->assertDontSee('PRIVATE_PERSON')->assertDontSee('PRIVATE_CODE')->assertDontSee('DOCTOR_NOTE')->assertDontSee('Secret Patient')->assertDontSee('Doctor note');
        }

        $csv = $this->actingAs($recipient)->post(route('anonymized-results.export'), [
            'format' => 'csv',
            'handoff_ids' => $handoffs->pluck('public_id')->all(),
        ])->assertDownload()->streamedContent();
        $this->assertStringContainsString($firstPatient->patient_code, $csv);
        $this->assertStringContainsString($secondPatient->patient_code, $csv);
        $this->assertStringContainsString('60', $csv);
        $this->assertStringContainsString('BULK_SECOND_AGE', $csv);
        $this->assertStringNotContainsString('SENSITIVE', $csv);
        $this->assertStringNotContainsString('PRIVATE_PERSON', $csv);
        $this->assertStringNotContainsString('PRIVATE_CODE', $csv);
        $this->assertStringNotContainsString('DOCTOR_NOTE', $csv);
        $this->assertStringNotContainsString('Secret Patient', $csv);
        $this->assertStringNotContainsString('Doctor note', $csv);
    }

    public function test_bulk_handoff_rejects_an_incomplete_result_without_partial_handoffs(): void
    {
        [$doctor, $organisation, , , $normal, $name, , , $completedAssignment] = $this->completedGraph();
        [$incompleteAssignment] = $this->createAdditionalResult(
            $organisation, $doctor, $completedAssignment->publication, $normal, $name, 2, 'BULK_INCOMPLETE', 'in_progress'
        );
        $recipient = $this->anonymizedRecipient($organisation);

        $this->actingAs($doctor)->post(route('doctor.results.handoff.bulk'), [
            'recipient' => $recipient->id,
            'assignment_ids' => [$completedAssignment->public_id, $incompleteAssignment->public_id],
        ])->assertSessionHasErrors('assignment_ids');

        $this->assertDatabaseCount('anonymized_result_handoffs', 0);

        $unpermissioned = User::factory()->create(['is_active' => true]);
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $unpermissioned->id, 'is_active' => true]);
        $this->actingAs($doctor)->post(route('doctor.results.handoff.bulk'), [
            'recipient' => $unpermissioned->id,
            'assignment_ids' => [$completedAssignment->public_id],
        ])->assertSessionHasErrors('recipient');
        $this->assertDatabaseCount('anonymized_result_handoffs', 0);
    }

    public function test_bulk_handoff_denies_foreign_doctor_and_cross_organisation_selections(): void
    {
        [$doctor, $organisation, , , $normal, $name, , , $ownAssignment] = $this->completedGraph();
        [$otherDoctor] = $this->member('doctor', $organisation);
        [$foreignDoctorAssignment] = $this->createAdditionalResult(
            $organisation, $otherDoctor, $ownAssignment->publication, $normal, $name, 1, 'BULK_OTHER_DOCTOR', 'submitted'
        );
        $recipient = $this->anonymizedRecipient($organisation);

        $this->actingAs($doctor)->post(route('doctor.results.handoff.bulk'), [
            'recipient' => $recipient->id,
            'assignment_ids' => [$ownAssignment->public_id, $foreignDoctorAssignment->public_id],
        ])->assertForbidden();
        $this->assertDatabaseCount('anonymized_result_handoffs', 0);

        $foreignGraph = $this->completedGraph();
        $foreignAssignment = $foreignGraph[8];
        $this->actingAs($doctor)->post(route('doctor.results.handoff.bulk'), [
            'recipient' => $recipient->id,
            'assignment_ids' => [$ownAssignment->public_id, $foreignAssignment->public_id],
        ])->assertStatus(422);
        $this->assertDatabaseCount('anonymized_result_handoffs', 0);
    }

    public function test_bulk_handoff_skips_existing_recipient_handoff_without_creating_duplicates(): void
    {
        [$doctor, $organisation, , , $normal, $name, , , $firstAssignment, $firstSubmission] = $this->completedGraph();
        [$secondAssignment] = $this->createAdditionalResult(
            $organisation, $doctor, $firstAssignment->publication, $normal, $name, 2, 'BULK_DUPLICATE', 'submitted'
        );
        $recipient = $this->anonymizedRecipient($organisation);
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$firstAssignment->patientCase, $firstAssignment]), ['recipient' => $recipient->id])->assertRedirect();

        $this->actingAs($doctor)->post(route('doctor.results.handoff.bulk'), [
            'recipient' => $recipient->id,
            'assignment_ids' => [$firstAssignment->public_id, $secondAssignment->public_id],
        ])->assertRedirect()
            ->assertSessionHas('success', __('messages.bulk_handoff_summary', ['created' => 1, 'skipped' => 1]));

        $this->assertSame(2, AnonymizedResultHandoff::where('recipient_user_id', $recipient->id)->count());
        $this->assertSame(1, AnonymizedResultHandoff::where('form_submission_id', $firstSubmission->id)->where('recipient_user_id', $recipient->id)->count());
    }

    public function test_anonymized_xlsx_separates_respondent_blocks_without_sensitive_data(): void
    {
        [$doctor, $organisation, , , $normal, $name, $email, $phone, $firstAssignment, $firstSubmission, $firstPatient] = $this->completedGraph();
        SubmissionAnswer::create(['form_submission_id' => $firstSubmission->id, 'form_component_id' => $normal->id, 'value' => '60', 'display_value' => '60', 'saved_at' => now()]);
        foreach ([[$name, 'XLSX_SENSITIVE_NAME_ONE'], [$email, 'XLSX_SENSITIVE_EMAIL_ONE'], [$phone, 'XLSX_SENSITIVE_PHONE_ONE']] as [$component, $value]) {
            SubmissionAnswer::create(['form_submission_id' => $firstSubmission->id, 'form_component_id' => $component->id, 'value' => $value, 'display_value' => $value, 'saved_at' => now()]);
        }
        [$secondAssignment, $secondSubmission, $secondPatient] = $this->createAdditionalResult(
            $organisation, $doctor, $firstAssignment->publication, $normal, $name, 2, 'XLSX_SECOND', 'submitted'
        );
        $recipient = $this->anonymizedRecipient($organisation);
        foreach ([[$firstPatient, $firstAssignment], [$secondPatient, $secondAssignment]] as [$patient, $assignment]) {
            $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertRedirect();
        }

        $handoffIds = AnonymizedResultHandoff::where('recipient_user_id', $recipient->id)->pluck('public_id')->all();
        $response = $this->actingAs($recipient)->post(route('anonymized-results.export'), ['format' => 'xlsx', 'handoff_ids' => $handoffIds])->assertDownload();
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        try {
            $this->assertTrue($zip->open($path) === true);
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sharedStrings = $zip->getFromName('xl/sharedStrings.xml');
            $this->assertIsString($sheet);
            $sharedValues = [];
            if (is_string($sharedStrings)) {
                $sharedDocument = new \DOMDocument();
                $this->assertTrue($sharedDocument->loadXML($sharedStrings, LIBXML_NONET | LIBXML_NOBLANKS));
                $sharedXPath = new \DOMXPath($sharedDocument);
                foreach ($sharedXPath->query('//*[local-name()="si"]') as $sharedItem) {
                    $value = '';
                    foreach ($sharedXPath->query('.//*[local-name()="t"]', $sharedItem) as $textNode) {
                        $value .= $textNode->textContent;
                    }
                    $sharedValues[] = $value;
                }
            }

            $sheetDocument = new \DOMDocument();
            $this->assertTrue($sheetDocument->loadXML($sheet, LIBXML_NONET | LIBXML_NOBLANKS));
            $sheetXPath = new \DOMXPath($sheetDocument);
            $cellValues = [];
            $patientRows = [];
            foreach ($sheetXPath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                $rowNumber = (int) $row->getAttribute('r');
                foreach ($sheetXPath->query('./*[local-name()="c"]', $row) as $cell) {
                    $type = $cell->getAttribute('t');
                    $value = '';

                    if ($type === 's') {
                        $valueNode = $sheetXPath->query('./*[local-name()="v"]', $cell)->item(0);
                        if ($valueNode !== null) {
                            $sharedIndex = (int) $valueNode->textContent;
                            $value = $sharedValues[$sharedIndex] ?? '';
                        }
                    } elseif ($type === 'inlineStr') {
                        foreach ($sheetXPath->query('.//*[local-name()="t"]', $cell) as $textNode) {
                            $value .= $textNode->textContent;
                        }
                    } else {
                        $valueNode = $sheetXPath->query('./*[local-name()="v"]', $cell)->item(0);
                        if ($valueNode !== null) {
                            $value = $valueNode->textContent;
                        }
                    }

                    $cellValues[] = $value;
                    if (in_array($value, [$firstPatient->patient_code, $secondPatient->patient_code], true)) {
                        $patientRows[$value] = $rowNumber;
                    }
                }
            }

            $this->assertContains($firstPatient->patient_code, $cellValues);
            $this->assertContains($secondPatient->patient_code, $cellValues);
            $this->assertContains('60', $cellValues);
            $this->assertContains('XLSX_SECOND_AGE', $cellValues);
            $exportedText = implode("\n", array_merge($sharedValues, $cellValues));
            $this->assertStringNotContainsString('SENSITIVE', $exportedText);
            $this->assertStringNotContainsString('PRIVATE_PERSON', $exportedText);
            $this->assertStringNotContainsString('PRIVATE_CODE', $exportedText);
            $this->assertStringNotContainsString('DOCTOR_NOTE', $exportedText);
            $this->assertStringNotContainsString('Secret Patient', $exportedText);
            $this->assertStringNotContainsString('Doctor note', $exportedText);

            $this->assertCount(2, $patientRows);
            $this->assertGreaterThanOrEqual(2, abs($patientRows[$firstPatient->patient_code] - $patientRows[$secondPatient->patient_code]), 'A blank row should separate respondent blocks.');
        } finally {
            if ($zip->status === \ZipArchive::ER_OK) $zip->close();
            @unlink($path);
            @unlink(substr($path, 0, -5));
        }
    }

    public function test_incomplete_other_doctor_and_unpermissioned_recipient_are_denied(): void
    {
        [$doctor, $organisation, , , , , , , $assignment, $submission, $patient] = $this->completedGraph();
        $recipient = User::factory()->create(['is_active' => true]);
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertSessionHasErrors('recipient');
        [$otherDoctor] = $this->member('doctor', $organisation);
        $this->actingAs($otherDoctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertForbidden();
        $submission->update(['status' => 'in_progress', 'submitted_at' => null]);
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertNotFound();
    }

    public function test_recipient_eligibility_requires_active_same_organisation_membership(): void
    {
        [$doctor, $organisation, , , , , , , $assignment, , $patient] = $this->completedGraph();
        $recipient = User::factory()->create(['is_active' => true]);
        $recipientMembership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $recipientMembership->roles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertRedirect();
        $handoff = \App\Models\AnonymizedResultHandoff::firstOrFail();
        $administrator = User::factory()->create(['is_active' => true]);
        $administrator->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $administrator->id, 'is_active' => true]);
        $inactive = User::factory()->create(['is_active' => true]);
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $inactive->id, 'is_active' => false]);
        $otherOrganisation = Organisation::create(['name' => 'Other', 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        $otherMembership = OrganisationMembership::create(['organisation_id' => $otherOrganisation->id, 'user_id' => $other->id, 'is_active' => true]);
        $role = Role::create(['name' => 'other-researcher', 'display_name' => 'Other researcher', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('name', 'anonymized_results.view')->firstOrFail());
        $otherMembership->roles()->attach($role);
        $eligible = app(\App\Domain\Results\AnonymizedResultHandoffService::class)->recipients($organisation);
        $this->assertTrue($eligible->contains('id', $administrator->id));
        $this->assertFalse($eligible->contains('id', $inactive->id));
        $this->assertFalse($eligible->contains('id', $other->id));
    }

    public function test_administrator_can_receive_but_inactive_organisation_and_other_recipient_cannot_view(): void
    {
        [$doctor, $organisation, , , , , , , $assignment, , $patient] = $this->completedGraph();
        $administrator = User::factory()->create(['is_active' => true]);
        $administrator->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $administrator->id, 'is_active' => true]);
        $other = User::factory()->create(['is_active' => true]);
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $other->id, 'is_active' => true]);
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $administrator->id])->assertRedirect();
        $handoff = \App\Models\AnonymizedResultHandoff::firstOrFail();
        $this->actingAs($administrator)->get(route('anonymized-results.show', $handoff))->assertOk();
        $this->actingAs($other)->get(route('anonymized-results.show', $handoff))->assertForbidden();
        $organisation->update(['is_active' => false]);
        $this->assertEmpty(app(\App\Domain\Results\AnonymizedResultHandoffService::class)->recipients($organisation));
        $this->actingAs($administrator)->get(route('anonymized-results.index'))->assertForbidden();
        $this->actingAs($administrator)->get(route('anonymized-results.show', $handoff))->assertForbidden();
        $this->actingAs($administrator)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertForbidden();
    }

    public function test_published_sensitive_component_is_preserved_in_new_draft(): void
    {
        [, , $form, $version, , $name] = $this->graph();
        $draft = app(FormAuthoringService::class)->createDraftFrom($version, User::factory()->create());
        $this->assertTrue($draft->components()->where('stable_key', $name->stable_key)->firstOrFail()->is_sensitive);
        $this->assertTrue($version->fresh()->components()->whereKey($name->id)->firstOrFail()->is_sensitive);
    }

    public function test_root_can_see_any_recipient_handoff_without_membership_but_not_inactive_organisation(): void
    {
        [$doctor, $organisation, , , , , , , $assignment, , $patient] = $this->completedGraph();
        $recipient = User::factory()->create(['is_active' => true]);
        $recipientMembership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $recipientMembership->roles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertRedirect();
        $root = User::factory()->create(['is_active' => true]);
        $root->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        $handoff = \App\Models\AnonymizedResultHandoff::firstOrFail();
        $this->actingAs($root)->get(route('anonymized-results.index'))->assertOk()->assertSee($patient->patient_code);
        $this->actingAs($root)->get(route('anonymized-results.show', $handoff))->assertOk();
        $this->actingAs($root)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertDownload();
        $organisation->update(['is_active' => false]);
        $this->actingAs($root)->get(route('anonymized-results.index'))->assertForbidden();
        $this->actingAs($root)->get(route('anonymized-results.show', $handoff))->assertForbidden();
        $this->actingAs($root)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertForbidden();
    }

    public function test_root_can_open_anonymized_results_index_before_any_organisation_exists(): void
    {
        $root = User::factory()->create(['is_active' => true]);
        $root->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());

        $this->actingAs($root)->get(route('anonymized-results.index'))->assertOk();
    }

    public function test_anonymized_handoff_timestamps_render_in_riga_and_exports_are_human_readable_without_changing_utc_storage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00', 'UTC'));

        try {
            [$doctor, $organisation, , , $normal, , , , $assignment, $submission, $patient] = $this->completedGraph();
            SubmissionAnswer::create([
                'form_submission_id' => $submission->id,
                'form_component_id' => $normal->id,
                'value' => '60',
                'display_value' => '60',
                'saved_at' => now(),
            ]);
            $submittedUtc = now()->toDateTimeString();
            $recipient = $this->anonymizedRecipient($organisation);
            $this->actingAs($doctor)->get(route('doctor.results.show', [$patient, $assignment]))
                ->assertOk()->assertSee('15.01.2026 12:00');
            Carbon::setTestNow(Carbon::parse('2026-01-15 10:30:00', 'UTC'));

            $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), [
                'recipient' => $recipient->id,
            ])->assertRedirect();
            $handoff = AnonymizedResultHandoff::firstOrFail();
            $expectedSubmitted = '15.01.2026 12:00';
            $expectedHandoff = '15.01.2026 12:30';

            $this->actingAs($recipient)->get(route('anonymized-results.index'))->assertOk()->assertSee($expectedSubmitted)->assertSee($expectedHandoff);
            $this->actingAs($recipient)->get(route('anonymized-results.show', $handoff))->assertOk()->assertSee($expectedSubmitted)->assertSee($expectedHandoff);
            $csv = $this->actingAs($recipient)->post(route('anonymized-results.export'), [
                'format' => 'csv',
                'handoff_ids' => [$handoff->public_id],
            ])->assertDownload()->streamedContent();
            $this->assertStringContainsString($expectedSubmitted, $csv);
            $this->assertStringContainsString($expectedHandoff, $csv);

            $xlsxResponse = $this->actingAs($recipient)->post(route('anonymized-results.export'), [
                'format' => 'xlsx',
                'handoff_ids' => [$handoff->public_id],
            ])->assertDownload();
            $xlsxPath = $xlsxResponse->baseResponse->getFile()->getPathname();
            try {
                $xlsxValues = $this->readXlsxText($xlsxPath);
                $this->assertContains($expectedSubmitted, $xlsxValues);
                $this->assertContains($expectedHandoff, $xlsxValues);
            } finally {
                @unlink($xlsxPath);
                @unlink(substr($xlsxPath, 0, -5));
            }

            $this->assertSame($submittedUtc, \Illuminate\Support\Facades\DB::table('form_submissions')->where('id', $submission->id)->value('submitted_at'));
            $this->assertSame('2026-01-15 10:30:00', \Illuminate\Support\Facades\DB::table('anonymized_result_handoffs')->where('id', $handoff->id)->value('handed_off_at'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_generic_submission_and_export_permissions_do_not_grant_anonymized_result_access(): void
    {
        [$doctor, $organisation, , , , , , , $assignment, , $patient] = $this->completedGraph();
        $recipient = User::factory()->create(['is_active' => true]);
        $recipientMembership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $recipientMembership->roles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $this->actingAs($doctor)->post(route('doctor.results.handoff', [$patient, $assignment]), ['recipient' => $recipient->id])->assertRedirect();
        $handoff = \App\Models\AnonymizedResultHandoff::firstOrFail();
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $role = Role::create(['name' => 'generic-exporter', 'display_name' => 'Generic exporter', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('name', ['submissions.view', 'exports.create', 'exports.download'])->pluck('id'));
        $membership->roles()->attach($role);
        $this->actingAs($user)->get(route('anonymized-results.index'))->assertForbidden();
        $this->actingAs($user)->get(route('anonymized-results.show', $handoff))->assertForbidden();
        $this->actingAs($user)->post(route('anonymized-results.export'), ['format' => 'csv', 'handoff_ids' => [$handoff->public_id]])->assertForbidden();
    }

    private function completedGraph(bool $complete = true): array
    {
        $graph = $this->graph();
        [$doctor, $organisation, , , $normal, $name, $email, $phone] = $graph;
        $patient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id, 'slot_number' => 1, 'first_name' => 'Secret', 'last_name' => 'Patient', 'note' => 'Doctor note']);
        $publication = Publication::create(['organisation_id' => $organisation->id, 'form_id' => $normal->formVersion->form_id, 'form_version_id' => $normal->form_version_id, 'public_key' => Str::random(20), 'name' => 'Study', 'status' => 'active', 'access_mode' => 'invitation', 'identified_required' => true]);
        $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', Str::random(64))]);
        $assignment = PatientFormAssignment::create(['patient_case_id' => $patient->id, 'publication_id' => $publication->id, 'invitation_id' => $invitation->id, 'label' => 'Study', 'display_order' => 1]);
        $submission = FormSubmission::create(['public_id' => Str::uuid(), 'organisation_id' => $organisation->id, 'publication_id' => $publication->id, 'form_version_id' => $normal->form_version_id, 'invitation_id' => $invitation->id, 'attempt_number' => 1, 'status' => $complete ? 'submitted' : 'in_progress', 'started_at' => now(), 'submitted_at' => $complete ? now() : null]);
        return [$doctor, $organisation, null, null, $normal, $name, $email, $phone, $assignment, $submission, $patient];
    }

    private function anonymizedRecipient(Organisation $organisation): User
    {
        $recipient = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $recipient->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', 'administrator')->firstOrFail());

        return $recipient;
    }

    private function createAdditionalResult(
        Organisation $organisation,
        User $doctor,
        Publication $publication,
        FormComponent $normal,
        FormComponent $sensitive,
        int $slot,
        string $marker,
        string $status
    ): array {
        $patient = PatientCase::create([
            'organisation_id' => $organisation->id,
            'doctor_id' => $doctor->id,
            'slot_number' => $slot,
            'first_name' => $marker.'_PRIVATE_PERSON',
            'last_name' => 'Private',
            'external_patient_code' => $marker.'_PRIVATE_CODE',
            'note' => $marker.'_DOCTOR_NOTE',
        ]);
        $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', Str::random(64))]);
        $assignment = PatientFormAssignment::create([
            'patient_case_id' => $patient->id,
            'publication_id' => $publication->id,
            'invitation_id' => $invitation->id,
            'label' => $marker.' assignment',
            'display_order' => $slot,
        ]);
        $submission = FormSubmission::create([
            'public_id' => Str::uuid(),
            'organisation_id' => $organisation->id,
            'publication_id' => $publication->id,
            'form_version_id' => $publication->form_version_id,
            'invitation_id' => $invitation->id,
            'attempt_number' => 1,
            'status' => $status,
            'started_at' => now(),
            'submitted_at' => $status === 'in_progress' ? null : now(),
        ]);
        SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $normal->id, 'value' => $marker.'_AGE', 'display_value' => $marker.'_AGE', 'saved_at' => now()]);
        SubmissionAnswer::create(['form_submission_id' => $submission->id, 'form_component_id' => $sensitive->id, 'value' => $marker.'_SENSITIVE', 'display_value' => $marker.'_SENSITIVE', 'saved_at' => now()]);

        return [$assignment, $submission, $patient];
    }

    private function graph(): array
    {
        $organisation = Organisation::create(['name' => 'Research', 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
        [$doctor] = $this->member('doctor', $organisation);
        [$creator] = $this->member('form_creator', $organisation);
        $form = app(FormAuthoringService::class)->create($organisation->id, $creator, 'Study', 'blank');
        $version = $form->versions()->firstOrFail();
        $normal = app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'number', 'label' => 'Age', 'options' => []]);
        $name = app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'short_text', 'label' => 'Name', 'is_sensitive' => true, 'options' => []]);
        $email = app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'short_text', 'label' => 'Email', 'is_sensitive' => true, 'options' => []]);
        $phone = app(FormAuthoringService::class)->addComponent($version, $version->sections()->first(), ['type' => 'short_text', 'label' => 'Phone', 'is_sensitive' => true, 'options' => []]);
        $version = app(FormAuthoringService::class)->publish($version);
        $normal->refresh(); $name->refresh(); $email->refresh(); $phone->refresh();
        return [$doctor, $organisation, $form, $version, $normal, $name, $email, $phone];
    }

    private function member(string $role, Organisation $organisation): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $role)->firstOrFail());
        return [$user, $membership];
    }

    private function readXlsxText(string $path): array
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $values = [];

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name) || !str_ends_with($name, '.xml')) continue;
                $xml = $zip->getFromIndex($index);
                if (!is_string($xml)) continue;

                $document = new \DOMDocument();
                if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) continue;
                $text = (new \DOMXPath($document))->query('//*[local-name()="t"]');
                foreach ($text as $node) $values[] = $node->textContent;
            }
        } finally {
            $zip->close();
        }

        return $values;
    }
}
