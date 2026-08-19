<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Domain\Patients\PatientAccessService;
use App\Models\AuditLog;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientAccessPackage;
use App\Models\PatientCase;
use App\Models\PatientFormAssignment;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PatientQuestionnairePortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_doctor_can_assign_an_eligible_questionnaire_part(): void
    {
        [$doctor, $patient, $publication] = $this->base();
        $this->actingAs($doctor)->post(route('doctor.questionnaires.store', $patient), [
            'publication_id' => $publication->id, 'label' => 'Piekrišana', 'display_order' => 1,
        ])->assertRedirect();
        $this->assertDatabaseHas('patient_form_assignments', ['patient_case_id' => $patient->id, 'publication_id' => $publication->id, 'label' => 'Piekrišana']);
        $this->assertDatabaseCount('invitations', 1);
    }

    public function test_doctor_cannot_assign_or_issue_a_link_for_another_doctors_patient(): void
    {
        [$doctor, , $publication, $organisation] = $this->base();
        [$otherDoctor] = $this->member('doctor', $organisation);
        $otherPatient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $otherDoctor->id, 'slot_number' => 2]);
        $this->actingAs($doctor)->post(route('doctor.questionnaires.store', $otherPatient), ['publication_id' => $publication->id, 'label' => 'Secret', 'display_order' => 1])->assertForbidden();
        $this->actingAs($doctor)->post(route('doctor.patient-link.issue', $otherPatient), ['expires_in_days' => 30])->assertForbidden();
    }

    public function test_package_token_is_hash_only_and_never_written_to_audit_metadata(): void
    {
        [$doctor, $patient, $publication] = $this->base();
        $this->assign($patient, $publication, 'Part one', 1);
        [$package, $token] = $this->actingAs($doctor)->issue($patient);
        $this->assertNotSame($token, $package->getRawOriginal('token_hash'));
        $this->assertSame(hash('sha256', $token), $package->getRawOriginal('token_hash'));
        $this->assertStringNotContainsString($token, AuditLog::query()->get()->pluck('metadata')->toJson());
        $this->assertArrayNotHasKey('token_hash', $package->toArray());
    }

    public function test_invalid_expired_and_revoked_tokens_are_denied(): void
    {
        [$doctor, $patient, $publication] = $this->base(); $this->assign($patient, $publication, 'One', 1);
        $this->get(route('patient.access', Str::random(64)))->assertNotFound();
        [$expired, $expiredToken] = $this->actingAs($doctor)->issue($patient); $expired->update(['expires_at' => now()->subMinute()]);
        $this->get(route('patient.access', $expiredToken))->assertNotFound();
        [$revoked, $revokedToken] = $this->actingAs($doctor)->issue($patient); $revoked->update(['revoked_at' => now()]);
        $this->get(route('patient.access', $revokedToken))->assertNotFound();
    }

    public function test_portal_lists_dynamic_parts_in_order_without_research_identity_or_admin_navigation(): void
    {
        [$doctor, $patient, $first, $organisation] = $this->base(); $second = $this->publication($organisation, 'Second');
        $this->assign($patient, $second, 'Anketa 2', 20); $this->assign($patient, $first, 'Piekrišana', 10);
        [, $token] = $this->actingAs($doctor)->issue($patient);
        $response = $this->get(route('patient.access', $token))->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSeeInOrder(['Piekrišana', 'Anketa 2'])
            ->assertDontSee($patient->patient_code)->assertDontSee($patient->first_name)->assertDontSee(route('login'), false)->assertDontSee(route('doctor.dashboard'), false);
    }

    public function test_second_part_is_locked_until_first_is_completed(): void
    {
        [$doctor, $patient, $first, $organisation] = $this->base(); $second = $this->publication($organisation, 'Second');
        $firstAssignment = $this->assign($patient, $first, 'First', 1); $secondAssignment = $this->assign($patient, $second, 'Second', 2);
        [$package, $token] = $this->actingAs($doctor)->issue($patient); $this->get(route('patient.access', $token));
        $this->get(route('patient.portal', $package))->assertSee('data-part-status="not_started"', false)->assertSee(__('messages.locked_until_previous'));
        $this->post(route('patient.assignments.start', [$package, $secondAssignment]))->assertStatus(409);
        $this->completedSubmission($firstAssignment);
        $this->post(route('patient.assignments.start', [$package, $secondAssignment]))->assertRedirect();
    }

    public function test_patient_can_start_and_resume_the_same_submission_after_a_new_session(): void
    {
        [$doctor, $patient, $publication] = $this->base(); $assignment = $this->assign($patient, $publication, 'First', 1);
        [$package, $token] = $this->actingAs($doctor)->issue($patient); $this->flushSession();
        $this->get(route('patient.access', $token));
        $first = $this->post(route('patient.assignments.start', [$package, $assignment]))->assertRedirect();
        $submission = FormSubmission::firstOrFail();
        $this->assertSame(route('submissions.take', $submission), $first->headers->get('Location'));
        $this->flushSession(); $this->get(route('patient.access', $token));
        $second = $this->post(route('patient.assignments.start', [$package, $assignment]))->assertRedirect();
        $this->assertSame(route('submissions.take', $submission), $second->headers->get('Location'));
        $this->assertDatabaseCount('form_submissions', 1); $this->assertSame(1, $submission->attempt_number);
    }

    public function test_partial_answer_autosaves_and_is_present_after_reopening_link(): void
    {
        [$doctor, $patient, $publication, , $component] = $this->base(true); $assignment = $this->assign($patient, $publication, 'First', 1);
        [$package, $token] = $this->actingAs($doctor)->issue($patient); $this->flushSession(); $this->get(route('patient.access', $token));
        $this->post(route('patient.assignments.start', [$package, $assignment])); $submission = FormSubmission::firstOrFail();
        $this->postJson(route('submissions.autosave', $submission), ['expected_revision' => 0, 'client_mutation_id' => (string) Str::uuid(), 'answers' => [$component->id => 'Saglabāta atbilde']])->assertOk()->assertJsonPath('revision', 1);
        $this->flushSession(); $this->get(route('patient.access', $token));
        $this->post(route('patient.assignments.start', [$package, $assignment]))->assertRedirect(route('submissions.take', $submission));
        $this->get(route('submissions.take', $submission))->assertOk()->assertSee('Saglabāta atbilde');
        $this->assertDatabaseHas('submission_answers', ['form_submission_id' => $submission->id, 'form_component_id' => $component->id]);
    }

    public function test_finalize_marks_part_complete_returns_to_portal_and_prevents_editing(): void
    {
        [$doctor, $patient, $publication, , $component] = $this->base(true); $assignment = $this->assign($patient, $publication, 'First', 1);
        [$package, $token] = $this->actingAs($doctor)->issue($patient); $this->flushSession(); $this->get(route('patient.access', $token));
        $this->post(route('patient.assignments.start', [$package, $assignment])); $submission = FormSubmission::firstOrFail();
        $this->postJson(route('submissions.finalize', $submission), ['expected_revision' => 0, 'client_mutation_id' => (string) Str::uuid(), 'answers' => [$component->id => 'Final']])
            ->assertOk()->assertJsonPath('redirect', route('patient.portal', $package));
        $this->assertContains($submission->fresh()->status, FormSubmission::PATIENT_COMPLETED_STATUSES);
        $this->get(route('submissions.take', $submission))->assertStatus(409);
        $this->get(route('patient.portal', $package))->assertSee(__('messages.all_parts_completed'));
    }

    public function test_refusing_consent_ends_the_patient_flow_and_blocks_later_parts(): void
    {
        [$doctor, $patient, $first, $organisation] = $this->base(); $second = $this->publication($organisation, 'Second');
        $firstAssignment = $this->assign($patient, $first, 'Consent', 1); $secondAssignment = $this->assign($patient, $second, 'Follow-up', 2);
        [$package, $token] = $this->actingAs($doctor)->issue($patient); $this->flushSession(); $this->get(route('patient.access', $token));
        $this->post(route('patient.assignments.start', [$package, $firstAssignment])); $submission = FormSubmission::firstOrFail(); $consent = $submission->formVersion->components()->where('type', 'consent_checkbox')->firstOrFail();
        $this->postJson(route('submissions.autosave', $submission), ['expected_revision' => 0, 'client_mutation_id' => (string) Str::uuid(), 'answers' => [$consent->id => false]])->assertOk()->assertJsonPath('consent_refused', true);
        $this->assertNotNull($package->fresh()->consent_refused_at);
        $this->get(route('patient.portal', $package))->assertSee(__('messages.survey_ended_no_consent'));
        $this->post(route('patient.assignments.start', [$package, $secondAssignment]))->assertStatus(409);
    }

    public function test_doctor_dashboard_summarises_completed_and_in_progress_assignments(): void
    {
        [$doctor, $patient, $first, $organisation] = $this->base(); $second = $this->publication($organisation, 'Second');
        $firstAssignment = $this->assign($patient, $first, 'First', 1); $secondAssignment = $this->assign($patient, $second, 'Second', 2);
        $this->completedSubmission($firstAssignment); $this->inProgressSubmission($secondAssignment);
        $this->actingAs($doctor)->get(route('doctor.dashboard'))->assertOk()->assertSee(__('messages.completed_count', ['count' => 1]))->assertSee(__('messages.in_progress_count', ['count' => 1]));
    }

    public function test_admin_one_has_no_patient_management_or_portal_session_access(): void
    {
        [$doctor, $patient, $publication] = $this->base(); $this->assign($patient, $publication, 'First', 1); [$package] = $this->actingAs($doctor)->issue($patient);
        $admin = User::factory()->create(['is_active' => true]); $admin->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        $this->actingAs($admin)->get(route('doctor.questionnaires.index', $patient))->assertForbidden();
        $this->actingAs($admin)->post(route('doctor.patient-link.issue', $patient), ['expires_in_days' => 30])->assertForbidden();
        $this->get(route('patient.portal', $package))->assertForbidden();
    }

    public function test_patient_cannot_swap_package_or_assignment_ids(): void
    {
        [$doctor, $patient, $publication, $organisation] = $this->base(); $assignment = $this->assign($patient, $publication, 'First', 1); [$package, $token] = $this->actingAs($doctor)->issue($patient);
        [$otherDoctor] = $this->member('doctor', $organisation); $otherPatient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $otherDoctor->id, 'slot_number' => 2]);
        $otherPublication = $this->publication($organisation, 'Other'); $otherAssignment = $this->assign($otherPatient, $otherPublication, 'Other', 1); [$otherPackage] = $this->actingAs($otherDoctor)->issue($otherPatient);
        $this->flushSession(); $this->get(route('patient.access', $token));
        $this->get(route('patient.portal', $otherPackage))->assertForbidden();
        $this->post(route('patient.assignments.start', [$package, $otherAssignment]))->assertNotFound();
        $this->post(route('patient.assignments.start', [$otherPackage, $assignment]))->assertForbidden();
    }

    public function test_regeneration_invalidates_old_token_and_old_clean_session(): void
    {
        [$doctor, $patient, $publication] = $this->base(); $this->assign($patient, $publication, 'First', 1);
        [$oldPackage, $oldToken] = $this->actingAs($doctor)->issue($patient); $this->flushSession(); $this->get(route('patient.access', $oldToken));
        [$newPackage, $newToken] = $this->actingAs($doctor)->issue($patient);
        $this->get(route('patient.portal', $oldPackage))->assertForbidden(); $this->flushSession();
        $this->get(route('patient.access', $oldToken))->assertNotFound(); $this->get(route('patient.access', $newToken))->assertRedirect(route('patient.portal', $newPackage));
    }

    public function test_management_view_shows_link_controls_and_only_eligible_publications(): void
    {
        [$doctor, $patient, $eligible, $organisation] = $this->base();
        $ineligible = $this->publication($organisation, 'Public mode', 'public');
        $this->actingAs($doctor)->get(route('doctor.questionnaires.index', $patient))->assertOk()->assertSee($eligible->name)->assertDontSee($ineligible->name)
            ->assertSee(__('messages.create_link'))->assertSee(__('messages.assign_questionnaire'));
    }

    public function test_link_issue_safely_provisions_an_invitation_for_an_existing_assignment(): void
    {
        [$doctor, $patient, $publication] = $this->base();
        $assignment = PatientFormAssignment::create(['patient_case_id' => $patient->id, 'publication_id' => $publication->id, 'label' => 'Legacy part', 'display_order' => 1]);
        [$package] = $this->actingAs($doctor)->issue($patient);
        $assignment->refresh();
        $this->assertNotNull($assignment->invitation_id);
        $this->assertSame($package->id, $assignment->patient_access_package_id);
        $this->assertSame($publication->id, $assignment->invitation->publication_id);
    }

    private function base(bool $answerable = false): array
    {
        $organisation = Organisation::create(['name' => Str::random(8), 'slug' => Str::lower(Str::random(10)), 'is_active' => true]);
        [$doctor] = $this->member('doctor', $organisation); [$creator] = $this->member('form_creator', $organisation);
        $patient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id, 'slot_number' => 1, 'first_name' => 'Private', 'last_name' => 'Patient']);
        [$publication, $component] = $this->publicationWithCreator($organisation, $creator, 'First publication', 'invitation', $answerable);
        return [$doctor, $patient, $publication, $organisation, $component];
    }

    private function publication(Organisation $organisation, string $name, string $accessMode = 'invitation'): Publication
    {
        [$creator] = $this->member('form_creator', $organisation);
        return $this->publicationWithCreator($organisation, $creator, $name, $accessMode)[0];
    }

    private function publicationWithCreator(Organisation $organisation, User $creator, string $name, string $accessMode, bool $answerable = false): array
    {
        $authoring = app(FormAuthoringService::class); $form = $authoring->create($organisation->id, $creator, $name, 'blank'); $version = $form->versions()->firstOrFail();
        $component = $answerable ? $authoring->addComponent($version, $version->sections()->first(), ['type' => 'short_text', 'label' => 'Answer', 'is_required' => true, 'options' => []]) : null;
        $published = $authoring->publish($version);
        $publication = Publication::create(['organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $published->id,
            'public_key' => Str::lower(Str::random(20)), 'name' => $name, 'status' => 'active', 'access_mode' => $accessMode,
            'anonymous_allowed' => false, 'identified_required' => true, 'attempt_limit' => 1, 'autosave_enabled' => true, 'resume_enabled' => true]);
        return [$publication, $component];
    }

    private function assign(PatientCase $patient, Publication $publication, string $label, int $order): PatientFormAssignment
    {
        $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', Str::random(64)), 'max_uses' => 1]);
        return PatientFormAssignment::create(['patient_case_id' => $patient->id, 'publication_id' => $publication->id, 'invitation_id' => $invitation->id, 'label' => $label, 'display_order' => $order]);
    }

    private function issue(PatientCase $patient): array { return app(PatientAccessService::class)->issue($patient, auth()->id(), 30); }

    private function completedSubmission(PatientFormAssignment $assignment): FormSubmission { return $this->submission($assignment, 'submitted'); }
    private function inProgressSubmission(PatientFormAssignment $assignment): FormSubmission { return $this->submission($assignment, 'in_progress'); }
    private function submission(PatientFormAssignment $assignment, string $status): FormSubmission
    {
        return FormSubmission::create(['public_id' => (string) Str::uuid(), 'organisation_id' => $assignment->patientCase->organisation_id,
            'publication_id' => $assignment->publication_id, 'form_version_id' => $assignment->publication->form_version_id, 'invitation_id' => $assignment->invitation_id,
            'attempt_number' => 1, 'status' => $status, 'started_at' => now(), 'submitted_at' => $status === 'in_progress' ? null : now()]);
    }

    private function member(string $roleName, Organisation $organisation): array
    {
        $user = User::factory()->create(['is_active' => true]); $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail()); return [$user, $membership];
    }
}
