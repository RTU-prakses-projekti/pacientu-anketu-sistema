<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\Publication;
use App\Models\Role;
use App\Models\User;
use App\Domain\Forms\FormAuthoringService;
use App\Domain\Submissions\SubmissionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RoleModelSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_product_roles_cannot_bypass_doctor_ownership_and_root_is_hidden_from_assignment_ui(): void
    {
        $organisation = Organisation::create(['name' => 'Clinical', 'slug' => 'clinical-'.Str::lower(Str::random(6)), 'is_active' => true]);
        $root = User::factory()->create(['is_active' => true]);
        $root->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        $administrator = User::factory()->create(['is_active' => true]);
        $administrator->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $assistant = $this->member('organisation_admin', $organisation);
        $manager = $this->member('form_creator', $organisation);
        $doctor = $this->member('doctor', $organisation);
        $patient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id, 'slot_number' => 1]);

        $this->assertTrue($root->isBootstrapRoot());
        $this->assertTrue($root->canAdministerSystem());
        $this->assertTrue($administrator->isAdministrator());
        $this->assertTrue($administrator->canAdministerSystem());
        foreach (['submissions.view', 'exports.create', 'exports.download'] as $permission) {
            $this->assertTrue(Role::where('name', 'administrator')->firstOrFail()->permissions()->where('name', $permission)->exists());
        }
        foreach (['organisation_admin', 'form_creator', 'doctor'] as $roleName) {
            foreach (['submissions.view', 'exports.create', 'exports.download'] as $permission) {
                $this->assertFalse(Role::where('name', $roleName)->firstOrFail()->permissions()->where('name', $permission)->exists());
            }
        }
        $this->assertTrue(Gate::forUser($root)->allows('view', $patient));
        foreach ([$administrator, $assistant, $manager] as $user) {
            $this->assertTrue(Gate::forUser($user)->denies('view', $patient));
            $this->actingAs($user)->get(route('doctor.questionnaires.index', $patient))->assertForbidden();
        }
        $this->actingAs($doctor)->get(route('doctor.questionnaires.index', $patient))->assertOk();
        $this->actingAs($root)->get(route('system.users.roles.edit', $administrator))
            ->assertOk()
            ->assertDontSee('Bootstrap root')
            ->assertDontSee('value="'.Role::where('name', 'platform_admin')->value('id').'"', false);
        $this->assertDatabaseMissing('roles', ['name' => 'reviewer']);
        $this->assertDatabaseMissing('roles', ['name' => 'respondent']);

        $form = app(FormAuthoringService::class)->create($organisation->id, $manager, 'Authenticated form', 'blank');
        $published = app(FormAuthoringService::class)->publish($form->versions()->firstOrFail());
        $publication = Publication::create([
            'organisation_id' => $organisation->id, 'form_id' => $form->id, 'form_version_id' => $published->id,
            'public_key' => Str::lower(Str::random(20)), 'name' => 'Authenticated form', 'status' => 'active',
            'access_mode' => 'authenticated', 'attempt_limit' => 1, 'result_visibility' => 'completion',
            'identified_required' => true, 'anonymous_allowed' => false, 'autosave_enabled' => true, 'resume_enabled' => true,
        ]);
        $this->expectException(ValidationException::class);
        app(SubmissionService::class)->start($publication, $administrator, null, null, 'admin-browser');
    }

    private function member(string $roleName, Organisation $organisation): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return $user;
    }
}
