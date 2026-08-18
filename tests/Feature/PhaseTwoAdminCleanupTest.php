<?php

namespace Tests\Feature;

use App\Domain\Forms\FormAuthoringService;
use App\Domain\Submissions\SubmissionService;
use App\Models\AuditLog;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Invitation;
use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\Publication;
use App\Models\QuestionnairePackagePartImport;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseTwoAdminCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_clean_draft_form_and_its_complete_draft_graph_are_deleted_without_touching_another_form(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $form = $this->cleanDraftForm($organisation, $admin, 'Disposable draft');
        $version = $form->versions()->firstOrFail();
        $component = $version->components()->firstOrFail();
        $target = app(FormAuthoringService::class)->addComponent($version, $version->sections()->firstOrFail(), [
            'type' => 'short_text', 'label' => 'Conditional target', 'options' => [],
        ]);
        DB::table('validation_rules')->insert([
            'form_component_id' => $component->id, 'rule_type' => 'required', 'display_order' => 0,
            'parameters' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $conditionalRuleId = DB::table('conditional_rules')->insertGetId([
            'form_version_id' => $version->id, 'source_component_id' => $component->id,
            'operator' => 'equals', 'comparison_value' => json_encode(['value' => 'answer']), 'priority' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('conditional_actions')->insert([
            'conditional_rule_id' => $conditionalRuleId, 'action' => 'show_component',
            'target_component_id' => $target->id, 'target_section_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        QuestionnairePackagePartImport::create([
            'organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $version->id,
            'imported_by' => $admin->id, 'content_hash' => str_repeat('a', 64), 'package_name' => 'draft-package',
        ]);
        $other = $this->cleanDraftForm($organisation, $admin, 'Keep draft');

        $this->actingAs($admin)->delete(route('forms.destroy', $form))->assertRedirect(route('forms.index', $organisation));

        $this->assertDatabaseMissing('forms', ['id' => $form->id]);
        foreach (['form_versions', 'form_sections', 'form_components', 'component_options', 'validation_rules', 'scoring_rules'] as $table) {
            $this->assertSame(0, DB::table($table)->whereIn($table === 'form_versions' ? 'id' : ($table === 'form_sections' ? 'form_version_id' : ($table === 'form_components' ? 'form_version_id' : 'form_component_id')), $table === 'form_versions' || $table === 'form_sections' || $table === 'form_components' ? [$version->id] : [$component->id])->count(), $table);
        }
        $this->assertDatabaseMissing('conditional_rules', ['id' => $conditionalRuleId]);
        $this->assertDatabaseMissing('conditional_actions', ['conditional_rule_id' => $conditionalRuleId]);
        $this->assertDatabaseMissing('questionnaire_package_part_imports', ['form_id' => $form->id]);
        $this->assertDatabaseHas('forms', ['id' => $other->id]);
    }

    public function test_published_or_submitted_form_is_denied_and_unauthorised_user_cannot_delete_another_organisation_form(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $form = app(FormAuthoringService::class)->create($organisation->id, $admin, 'Used form', 'blank');
        $published = app(FormAuthoringService::class)->publish($form->versions()->firstOrFail());
        $publication = $this->publication($form, $published);
        $respondent = User::factory()->create(['is_active' => true]);
        FormSubmission::create([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id,
            'publication_id' => $publication->id, 'form_version_id' => $published->id, 'user_id' => $respondent->id,
            'attempt_number' => 1, 'status' => 'in_progress', 'started_at' => now(),
        ]);

        $this->actingAs($admin)->delete(route('forms.destroy', $form))->assertSessionHasErrors('form');
        $this->assertDatabaseHas('forms', ['id' => $form->id]);
        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->delete(route('forms.destroy', $form))->assertForbidden();
        $this->assertDatabaseHas('form_submissions', ['publication_id' => $publication->id]);
    }

    public function test_clean_organisation_is_deleted_transactionally_with_drafts_and_memberships_but_other_organisation_remains(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $member = $this->member('form_creator', $organisation);
        $membershipId = OrganisationMembership::where('organisation_id', $organisation->id)->where('user_id', $member->id)->value('id');
        $form = $this->cleanDraftForm($organisation, $member, 'Organisation draft');
        $other = $this->organisation();

        $this->actingAs($admin)->delete(route('organisations.destroy', $organisation))->assertRedirect(route('organisations.index'));

        $this->assertDatabaseMissing('organisations', ['id' => $organisation->id]);
        $this->assertDatabaseMissing('organisation_memberships', ['organisation_id' => $organisation->id]);
        $this->assertDatabaseMissing('membership_roles', ['organisation_membership_id' => $membershipId]);
        $this->assertDatabaseMissing('forms', ['id' => $form->id]);
        $this->assertDatabaseHas('organisations', ['id' => $other->id]);
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    public function test_organisation_with_publication_submission_or_patient_data_is_denied(): void
    {
        $admin = $this->platformAdmin();
        $publishedOrganisation = $this->organisation();
        $form = app(FormAuthoringService::class)->create($publishedOrganisation->id, $admin, 'Published', 'blank');
        $published = app(FormAuthoringService::class)->publish($form->versions()->firstOrFail());
        $publication = $this->publication($form, $published);
        $respondent = User::factory()->create(['is_active' => true]);
        FormSubmission::create(['public_id' => (string) Str::uuid(), 'organisation_id' => $publishedOrganisation->id, 'publication_id' => $publication->id, 'form_version_id' => $published->id, 'user_id' => $respondent->id, 'attempt_number' => 1, 'status' => 'in_progress', 'started_at' => now()]);
        $this->actingAs($admin)->delete(route('organisations.destroy', $publishedOrganisation))->assertSessionHasErrors('organisation');

        $patientOrganisation = $this->organisation();
        PatientCase::create(['organisation_id' => $patientOrganisation->id, 'doctor_id' => $admin->id, 'slot_number' => 1]);
        $this->actingAs($admin)->delete(route('organisations.destroy', $patientOrganisation))->assertSessionHasErrors('organisation');
        $this->assertDatabaseHas('organisations', ['id' => $publishedOrganisation->id]);
        $this->assertDatabaseHas('organisations', ['id' => $patientOrganisation->id]);
    }

    public function test_export_dependency_blocks_form_and_organisation_deletion(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $form = $this->cleanDraftForm($organisation, $admin, 'Exported draft');
        DB::table('exports')->insert([
            'public_id' => (string) Str::uuid(), 'organisation_id' => $organisation->id,
            'requested_by' => $admin->id, 'form_id' => $form->id, 'format' => 'csv',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin)->delete(route('forms.destroy', $form))->assertSessionHasErrors('form');
        $this->actingAs($admin)->delete(route('organisations.destroy', $organisation))->assertSessionHasErrors('organisation');
        $this->assertDatabaseHas('forms', ['id' => $form->id]);
        $this->assertDatabaseHas('exports', ['organisation_id' => $organisation->id, 'form_id' => $form->id]);
    }

    public function test_invitation_can_be_revoked_by_owner_then_backend_rejects_old_token_and_cross_organisation_revoke_is_forbidden(): void
    {
        $owner = $this->member('form_creator', $organisation = $this->organisation());
        $form = app(FormAuthoringService::class)->create($organisation->id, $owner, 'Invitation form', 'blank');
        $published = app(FormAuthoringService::class)->publish($form->versions()->firstOrFail());
        $publication = $this->publication($form, $published, ['access_mode' => 'invitation', 'identified_required' => false]);
        $plain = 'phase-two-invitation-token';
        $invitation = Invitation::create(['publication_id' => $publication->id, 'token_hash' => hash('sha256', $plain), 'max_uses' => 1, 'uses' => 0]);
        $outsider = $this->member('form_creator', $this->organisation());

        $this->actingAs($outsider)->delete(route('invitations.revoke', [$form, $publication, $invitation]))->assertForbidden();
        $this->assertNull($invitation->fresh()->revoked_at);
        $this->actingAs($owner)->get(route('forms.show', $form))->assertOk()->assertSee(__('messages.invitation_links'));
        $this->actingAs($owner)->delete(route('invitations.revoke', [$form, $publication, $invitation]))->assertRedirect();
        $this->assertNotNull($invitation->fresh()->revoked_at);
        try {
            app(SubmissionService::class)->start($publication, null, null, $plain, 'browser');
            $this->fail('A revoked invitation must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invitation', $exception->errors());
        }
    }

    public function test_system_user_search_and_combined_role_organisation_status_filters_are_server_side(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $otherOrganisation = $this->organisation();
        $doctor = $this->member('doctor', $organisation, ['name' => 'Ronald Filter', 'email' => 'ronald@example.test']);
        $otherDoctor = $this->member('doctor', $otherOrganisation, ['name' => 'Other Doctor', 'email' => 'other@example.test']);
        $inactive = $this->member('respondent', $organisation, ['name' => 'Inactive Person', 'email' => 'inactive@example.test', 'is_active' => false]);
        $doctorRole = Role::where('name', 'doctor')->firstOrFail();

        $this->actingAs($admin)->get(route('system.users', ['q' => 'ronald@example.test']))
            ->assertOk()->assertSee($doctor->email)->assertDontSee($otherDoctor->email);
        $this->actingAs($admin)->get(route('system.users', ['q' => (string) $doctor->id]))
            ->assertOk()->assertSee($doctor->email)->assertDontSee($otherDoctor->email);
        $this->actingAs($admin)->get(route('system.users', ['organisation' => $organisation->id, 'role' => $doctorRole->id, 'status' => 'active']))
            ->assertOk()->assertSee($doctor->email)->assertDontSee($otherDoctor->email)->assertDontSee($inactive->email);
    }

    public function test_user_filter_pagination_preserves_query_parameters_and_non_admin_cannot_access_directory(): void
    {
        $admin = $this->platformAdmin();
        User::factory()->count(51)->create(['name' => 'Paged Person', 'is_active' => true]);
        $this->actingAs($admin)->get(route('system.users', ['q' => 'Paged Person', 'status' => 'active']))
            ->assertOk()->assertSee('q=Paged%20Person', false)->assertSee('status=active', false);
        $this->actingAs(User::factory()->create(['is_active' => true]))->get(route('system.users', ['q' => 'Paged']))->assertForbidden();
    }

    public function test_clean_user_is_deleted_with_membership_and_roles_while_other_organisation_data_remains(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $user = $this->member('respondent', $organisation);
        $membershipId = OrganisationMembership::where('organisation_id', $organisation->id)->where('user_id', $user->id)->value('id');
        $otherOrganisation = $this->organisation();

        $this->actingAs($admin)->delete(route('system.users.destroy', $user))->assertRedirect(route('system.users'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('organisation_memberships', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('membership_roles', ['organisation_membership_id' => $membershipId]);
        $this->assertDatabaseHas('organisations', ['id' => $otherOrganisation->id]);
    }

    public function test_user_with_audit_data_and_last_active_platform_admin_cannot_be_deleted_or_deactivated(): void
    {
        $admin = $this->platformAdmin();
        $audited = User::factory()->create(['is_active' => true]);
        AuditLog::create(['actor_id' => $audited->id, 'action' => 'test.audit', 'created_at' => now()]);
        $this->actingAs($admin)->delete(route('system.users.destroy', $audited))->assertSessionHasErrors('user');
        $this->assertDatabaseHas('users', ['id' => $audited->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $audited->id, 'action' => 'test.audit']);

        $operator = $this->platformAdmin();
        $operator->update(['is_active' => false]);
        $this->actingAs($operator)->delete(route('system.users.destroy', $admin))->assertSessionHasErrors('user');
        $this->actingAs($operator)->post(route('users.toggle', $admin))->assertSessionHasErrors('user');
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_user_that_is_an_audit_subject_cannot_be_hard_deleted_and_evidence_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $subject = User::factory()->create(['is_active' => true]);
        AuditLog::create([
            'action' => 'user.roles_updated', 'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->id, 'created_at' => now(),
        ]);

        $this->actingAs($admin)->delete(route('system.users.destroy', $subject))->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $subject->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.roles_updated', 'subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->id,
        ]);
    }

    public function test_form_with_retained_audit_evidence_cannot_be_deleted_and_evidence_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        $form = $this->cleanDraftForm($organisation, $admin, 'Audited draft');
        $version = $form->versions()->firstOrFail();
        AuditLog::create([
            'organisation_id' => $organisation->id, 'action' => 'questionnaire_package.exported',
            'subject_type' => $version->getMorphClass(), 'subject_id' => $version->id, 'created_at' => now(),
        ]);

        $this->actingAs($admin)->delete(route('forms.destroy', $form))->assertSessionHasErrors('form');

        $this->assertDatabaseHas('forms', ['id' => $form->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'questionnaire_package.exported', 'subject_type' => $version->getMorphClass(), 'subject_id' => $version->id,
        ]);
    }

    public function test_organisation_with_retained_audit_evidence_cannot_be_deleted_and_evidence_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();
        AuditLog::create([
            'organisation_id' => $organisation->id, 'action' => 'organisation.updated',
            'subject_type' => $organisation->getMorphClass(), 'subject_id' => $organisation->id, 'created_at' => now(),
        ]);

        $this->actingAs($admin)->delete(route('organisations.destroy', $organisation))->assertSessionHasErrors('organisation');

        $this->assertDatabaseHas('organisations', ['id' => $organisation->id]);
        $this->assertDatabaseHas('audit_logs', ['organisation_id' => $organisation->id, 'action' => 'organisation.updated']);
    }

    public function test_form_created_through_real_route_is_deletable_while_creation_audit_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation();

        $this->actingAs($admin)->post(route('forms.store'), [
            'organisation_id' => $organisation->id, 'name' => 'Real clean form', 'preset' => 'blank',
        ])->assertRedirect();
        $form = Form::where('organisation_id', $organisation->id)->where('name', 'Real clean form')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'form.created', 'subject_type' => $form->getMorphClass(), 'subject_id' => $form->id,
        ]);

        $this->actingAs($admin)->delete(route('forms.destroy', $form))->assertRedirect(route('forms.index', $organisation));

        $this->assertDatabaseMissing('forms', ['id' => $form->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'form.created', 'subject_type' => $form->getMorphClass(), 'subject_id' => $form->id,
        ]);
    }

    public function test_user_created_through_real_route_is_deletable_while_creation_audit_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->post(route('system.users.store'), [
            'name' => 'Real Clean User', 'email' => 'real.clean.user@example.test',
            'password' => 'CleanUserPassword123', 'password_confirmation' => 'CleanUserPassword123',
        ])->assertRedirect();
        $user = User::where('email', 'real.clean.user@example.test')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id,
        ]);

        $this->actingAs($admin)->delete(route('system.users.destroy', $user))->assertRedirect(route('system.users'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.created', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id,
        ]);
    }

    public function test_organisation_created_through_real_route_is_soft_deleted_while_creation_audit_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->post(route('organisations.store'), [
            'name' => 'Real Clean Organisation', 'slug' => '',
        ])->assertRedirect(route('organisations.index'));
        $organisation = Organisation::where('name', 'Real Clean Organisation')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'organisation_id' => $organisation->id, 'action' => 'organisation.created',
            'subject_type' => $organisation->getMorphClass(), 'subject_id' => $organisation->id,
        ]);

        $this->actingAs($admin)->delete(route('organisations.destroy', $organisation))->assertRedirect(route('organisations.index'));

        $this->assertSoftDeleted('organisations', ['id' => $organisation->id]);
        $this->assertDatabaseHas('audit_logs', ['organisation_id' => $organisation->id, 'action' => 'organisation.created']);
        $this->actingAs($admin)->get(route('organisations.index'))->assertOk()->assertDontSee('Real Clean Organisation');
    }

    public function test_real_organisation_with_real_clean_draft_form_is_removed_while_both_creation_audits_remain(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)->post(route('organisations.store'), [
            'name' => 'Organisation With Clean Draft', 'slug' => '',
        ])->assertRedirect(route('organisations.index'));
        $organisation = Organisation::where('name', 'Organisation With Clean Draft')->firstOrFail();
        $otherOrganisation = $this->organisation();
        $otherOrganisation->update(['name' => 'Unaffected Organisation']);

        $this->actingAs($admin)->post(route('forms.store'), [
            'organisation_id' => $organisation->id, 'name' => 'Real Draft Inside Organisation', 'preset' => 'blank',
        ])->assertRedirect();
        $form = Form::where('organisation_id', $organisation->id)->where('name', 'Real Draft Inside Organisation')->firstOrFail();
        $this->assertDatabaseHas('audit_logs', [
            'organisation_id' => $organisation->id, 'action' => 'organisation.created',
            'subject_type' => $organisation->getMorphClass(), 'subject_id' => $organisation->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'organisation_id' => $organisation->id, 'action' => 'form.created',
            'subject_type' => $form->getMorphClass(), 'subject_id' => $form->id,
        ]);

        $this->actingAs($admin)->delete(route('organisations.destroy', $organisation))->assertRedirect(route('organisations.index'));

        $this->assertSoftDeleted('organisations', ['id' => $organisation->id]);
        $this->assertDatabaseHas('forms', ['id' => $form->id, 'organisation_id' => $organisation->id]);
        $this->assertDatabaseHas('audit_logs', ['organisation_id' => $organisation->id, 'action' => 'organisation.created']);
        $this->assertDatabaseHas('audit_logs', ['organisation_id' => $organisation->id, 'action' => 'form.created']);
        $this->assertDatabaseHas('organisations', ['id' => $otherOrganisation->id, 'deleted_at' => null]);
        $this->actingAs($admin)->get(route('organisations.index'))
            ->assertOk()->assertDontSee('Organisation With Clean Draft')->assertSee('Unaffected Organisation');
    }

    public function test_non_admin_cannot_delete_a_user(): void
    {
        $target = User::factory()->create(['is_active' => true]);
        $outsider = User::factory()->create(['is_active' => true]);
        $this->actingAs($outsider)->delete(route('system.users.destroy', $target))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        return $user;
    }

    private function organisation(): Organisation
    {
        return Organisation::create(['name' => Str::random(10), 'slug' => Str::lower(Str::random(12)), 'is_active' => true]);
    }

    private function member(string $roleName, Organisation $organisation, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['is_active' => true], $attributes));
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        return $user;
    }

    private function cleanDraftForm(Organisation $organisation, User $creator, string $name): Form
    {
        $form = Form::create([
            'organisation_id' => $organisation->id, 'created_by' => $creator->id, 'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'status' => 'draft', 'preset_key' => 'test',
        ]);
        $version = $form->versions()->create([
            'version_number' => 1, 'status' => 'draft', 'title' => $name,
            'created_by' => $creator->id, 'settings' => [],
        ]);
        $section = $version->sections()->create([
            'stable_key' => (string) Str::uuid(), 'title' => 'Test section', 'display_order' => 0,
        ]);
        $component = $section->components()->create([
            'form_version_id' => $version->id, 'stable_key' => (string) Str::uuid(), 'type' => 'single_choice',
            'label' => 'Test question', 'display_order' => 0, 'is_required' => false, 'visible' => true,
            'max_points' => 1, 'manual_grading' => false, 'settings' => [],
        ]);
        foreach (['A', 'B'] as $index => $label) {
            $component->options()->create([
                'stable_key' => (string) Str::uuid(), 'label' => $label,
                'value' => (string) Str::uuid(), 'display_order' => $index,
            ]);
        }
        $component->scoringRule()->create([
            'strategy' => 'single_choice', 'max_points' => 1,
            'rules' => ['correct' => $component->options()->firstOrFail()->value],
        ]);
        return $form;
    }

    private function publication(Form $form, $version, array $overrides = []): Publication
    {
        return Publication::create(array_merge([
            'organisation_id' => $form->organisation_id, 'form_id' => $form->id, 'form_version_id' => $version->id,
            'public_key' => Str::lower(Str::random(20)), 'name' => 'Publication', 'status' => 'active',
            'access_mode' => 'authenticated', 'attempt_limit' => 1, 'timer_enabled' => false,
            'result_visibility' => 'completion', 'correct_answers_visible' => false, 'anonymous_allowed' => false,
            'identified_required' => true, 'consent_required' => false, 'autosave_enabled' => true, 'resume_enabled' => true,
        ], $overrides));
    }
}
