<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
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
}
