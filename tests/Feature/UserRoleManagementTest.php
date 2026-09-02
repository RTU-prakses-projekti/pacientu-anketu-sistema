<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserRoleManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_admin_one_creates_an_unprivileged_user_and_sees_scoped_role_summary(): void
    {
        $admin = $this->platformAdmin();
        $response = $this->actingAs($admin)->post(route('system.users.store'), [
            'name' => 'Doctor Test',
            'email' => 'doctor.test@example.com',
            'password' => 'OrdinaryPassword123',
            'password_confirmation' => 'OrdinaryPassword123',
        ]);

        $created = User::where('email', 'doctor.test@example.com')->firstOrFail();
        $response->assertRedirect(route('system.users.roles.edit', $created));
        $this->assertTrue(Hash::check('OrdinaryPassword123', $created->password));
        $this->assertFalse($created->isPlatformAdmin());
        $this->assertSame(0, $created->globalRoles()->count());
        $this->assertSame(0, $created->memberships()->count());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.created', 'subject_id' => $created->id]);

        $this->actingAs($admin)->get(route('system.users'))
            ->assertOk()
            ->assertSee('Doctor Test')
            ->assertSee('—')
            ->assertDontSee('Bootstrap root');
    }

    public function test_admin_one_assigns_every_organisation_role_to_the_correct_membership_and_can_revoke_them(): void
    {
        $admin = $this->platformAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $organisation = $this->organisation('RTU');
        $otherOrganisation = $this->organisation('LU');
        $roles = Role::where('scope', 'organisation')->whereIn('name', [
            'organisation_admin', 'form_creator', 'doctor',
        ])->get()->keyBy('name');

        $this->actingAs($admin)->get(route('system.users.roles.edit', $target))
            ->assertOk()
            ->assertSee('RTU')
            ->assertSee('LU')
            ->assertSee('Administratora palīgs')
            ->assertSee('Anketu pārvaldnieks')
            ->assertSee('Ārsts')
            ->assertDontSee('Vērtētājs')
            ->assertDontSee('Pacients');

        $this->actingAs($admin)->put(route('system.users.roles.update', $target), [
            'organisation_roles' => [
                $organisation->id => $roles->pluck('id')->all(),
                $otherOrganisation->id => [$roles['doctor']->id],
            ],
        ])->assertRedirect(route('system.users'));

        $membership = OrganisationMembership::where('organisation_id', $organisation->id)->where('user_id', $target->id)->firstOrFail();
        $this->assertTrue($membership->is_active);
        foreach (['organisation_admin', 'form_creator', 'doctor'] as $roleName) {
            $this->assertTrue($membership->roles()->where('name', $roleName)->exists(), $roleName.' was not assigned.');
        }
        $this->assertSame(1, OrganisationMembership::where('organisation_id', $otherOrganisation->id)->where('user_id', $target->id)->firstOrFail()->roles()->count());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.roles_updated', 'subject_id' => $target->id]);

        $this->actingAs($admin)->put(route('system.users.roles.update', $target), [])->assertRedirect(route('system.users'));
        $membership->refresh();
        $this->assertFalse($membership->is_active);
        $this->assertSame(0, $membership->roles()->count());
    }

    public function test_role_scope_validation_rejects_global_roles_in_memberships_and_unknown_organisations(): void
    {
        $admin = $this->platformAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $organisation = $this->organisation('RTU');
        $platformRole = Role::where('name', 'platform_admin')->firstOrFail();
        $doctorRole = Role::where('name', 'doctor')->firstOrFail();

        $this->actingAs($admin)->from(route('system.users.roles.edit', $target))
            ->put(route('system.users.roles.update', $target), [
                'organisation_roles' => [$organisation->id => [$platformRole->id]],
            ])->assertSessionHasErrors('organisation_roles.'.$organisation->id.'.0');
        $this->assertSame(0, $target->memberships()->count());

        $this->actingAs($admin)->from(route('system.users.roles.edit', $target))
            ->put(route('system.users.roles.update', $target), [
                'organisation_roles' => [999999 => [$doctorRole->id]],
            ])->assertSessionHasErrors('organisation_roles');
        $this->assertSame(0, $target->memberships()->count());
    }

    public function test_only_admin_one_can_use_system_wide_user_and_role_management(): void
    {
        $organisation = $this->organisation('RTU');
        $target = User::factory()->create(['is_active' => true]);
        [$admin2] = $this->organisationMember('organisation_admin', $organisation);
        $ordinary = User::factory()->create(['is_active' => true]);

        foreach ([$admin2, $ordinary] as $actor) {
            $this->actingAs($actor)->get(route('system.users'))->assertForbidden();
            $this->actingAs($actor)->get(route('system.users.roles.edit', $target))->assertForbidden();
            $this->actingAs($actor)->put(route('system.users.roles.update', $target), [])->assertForbidden();
            $this->actingAs($actor)->post(route('system.users.store'), [
                'name' => 'Blocked',
                'email' => Str::random(8).'@example.test',
                'password' => 'BlockedPassword123',
                'password_confirmation' => 'BlockedPassword123',
            ])->assertForbidden();
        }
    }

    public function test_product_administrator_assignment_is_audited_while_bootstrap_root_is_not_assignable(): void
    {
        $admin = $this->platformAdmin();
        $candidate = User::factory()->create(['is_active' => true]);
        $platformRole = Role::where('name', 'platform_admin')->firstOrFail();
        $administratorRole = Role::where('name', 'administrator')->firstOrFail();

        $this->actingAs($admin)->put(route('system.users.roles.update', $candidate), [
            'global_roles' => [$administratorRole->id],
        ])->assertRedirect(route('system.users'));
        $this->assertTrue($candidate->fresh()->isAdministrator());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.roles_updated', 'subject_id' => $candidate->id]);

        $this->actingAs($admin)->put(route('system.users.roles.update', $candidate), [])->assertRedirect(route('system.users'));
        $this->assertFalse($candidate->fresh()->isAdministrator());

        $this->actingAs($admin)->from(route('system.users.roles.edit', $admin))
            ->put(route('system.users.roles.update', $admin), ['global_roles' => [$platformRole->id]])
            ->assertSessionHasErrors('global_roles.0');
        $this->assertTrue($admin->fresh()->isBootstrapRoot());
    }

    public function test_doctor_assignment_redirects_the_doctor_without_weakening_admin_clinical_privacy(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $doctor = User::factory()->create(['is_active' => true]);
        $organisation = $this->organisation('RTU');
        $doctorRole = Role::where('name', 'doctor')->firstOrFail();

        $this->actingAs($admin)->put(route('system.users.roles.update', $doctor), [
            'organisation_roles' => [$organisation->id => [$doctorRole->id]],
        ])->assertRedirect(route('system.users'));
        $this->assertTrue($doctor->fresh()->hasDoctorWorkspace());
        $this->actingAs($doctor)->get(route('dashboard'))->assertRedirect(route('doctor.dashboard'));

        $patient = PatientCase::create([
            'organisation_id' => $organisation->id,
            'doctor_id' => $doctor->id,
            'slot_number' => 1,
            'first_name' => 'Clinical Secret',
            'last_name' => 'Private',
            'note' => 'Doctor only',
        ]);
        $this->assertSame(0, PatientCase::query()->visibleTo($admin)->count());
        $this->assertTrue(Gate::forUser($admin)->denies('view', $patient));
        $this->actingAs($admin)->get(route('doctor.dashboard', [
            'organisation_id' => $organisation->id,
            'doctor_id' => $doctor->id,
        ]))->assertForbidden();
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());

        return $user;
    }

    private function organisation(string $name): Organisation
    {
        return Organisation::create(['name' => $name, 'slug' => Str::lower($name).'-'.Str::lower(Str::random(6)), 'is_active' => true]);
    }

    private function organisationMember(string $roleName, Organisation $organisation): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());

        return [$user, $membership];
    }
}
