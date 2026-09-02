<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\PatientCase;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Tests\TestCase;

class DynamicCustomRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_platform_admin_creates_organisation_custom_role_with_safe_unique_key_and_selected_permissions(): void
    {
        $admin = $this->platformAdmin();
        $selected = Permission::whereIn('name', ['forms.view', 'exports.create'])->pluck('id');

        $this->actingAs($admin)->post(route('system.roles.store'), [
            'display_name' => 'Datu operators', 'permissions' => $selected->all(),
        ])->assertRedirect(route('system.roles'));

        $role = Role::where('name', 'datu_operators')->firstOrFail();
        $this->assertSame('Datu operators', $role->display_name);
        $this->assertSame('organisation', $role->scope);
        $this->assertFalse($role->is_system);
        $this->assertMatchesRegularExpression('/^[a-z0-9_]+$/', $role->name);
        $this->assertEqualsCanonicalizing($selected->all(), $role->permissions()->pluck('permissions.id')->all());
        $this->assertFalse($role->permissions()->where('name', 'patients.view')->exists());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'role.created', 'subject_id' => $role->id]);
    }

    public function test_duplicate_reserved_and_invalid_permission_role_creation_is_rejected(): void
    {
        $admin = $this->platformAdmin();
        Role::create(['name' => 'datu_operators', 'display_name' => 'Existing', 'scope' => 'organisation', 'is_system' => false]);

        $this->actingAs($admin)->from(route('system.roles'))->post(route('system.roles.store'), [
            'display_name' => 'Datu operators',
        ])->assertSessionHasErrors('display_name');
        $this->actingAs($admin)->from(route('system.roles'))->post(route('system.roles.store'), [
            'display_name' => 'Platform Admin',
        ])->assertSessionHasErrors('display_name');
        $this->actingAs($admin)->from(route('system.roles'))->post(route('system.roles.store'), [
            'display_name' => 'Forged permission role', 'permissions' => [999999],
        ])->assertSessionHasErrors('permissions.0');
        $this->assertDatabaseMissing('roles', ['name' => 'forged_permission_role']);
    }

    public function test_non_admin_cannot_create_or_delete_custom_roles(): void
    {
        $ordinary = User::factory()->create(['is_active' => true]);
        $role = Role::create(['name' => 'custom_target', 'display_name' => 'Custom target', 'scope' => 'organisation', 'is_system' => false]);

        $this->actingAs($ordinary)->post(route('system.roles.store'), ['display_name' => 'Blocked role'])->assertForbidden();
        $this->actingAs($ordinary)->delete(route('system.roles.destroy', $role))->assertForbidden();
        $this->assertDatabaseHas('roles', ['id' => $role->id]);
    }

    public function test_custom_organisation_role_uses_existing_assignment_editor_and_stays_organisation_scoped(): void
    {
        $admin = $this->platformAdmin();
        $target = User::factory()->create(['is_active' => true]);
        $organisation = $this->organisation('Primary');
        $otherOrganisation = $this->organisation('Other');
        $role = Role::create(['name' => 'form_auditor', 'display_name' => 'Formu auditors', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('name', ['organisation.view', 'forms.view'])->pluck('id'));
        $otherMembership = OrganisationMembership::create(['organisation_id' => $otherOrganisation->id, 'user_id' => $target->id, 'is_active' => true]);
        $respondentRole = Role::where('name', 'doctor')->firstOrFail();
        $otherMembership->roles()->attach($respondentRole);

        $this->actingAs($admin)->get(route('system.users.roles.edit', $target))
            ->assertOk()->assertSee('Formu auditors');
        $this->actingAs($admin)->put(route('system.users.roles.update', $target), [
            'organisation_roles' => [
                $organisation->id => [$role->id],
                $otherOrganisation->id => [$respondentRole->id],
            ],
        ])->assertRedirect(route('system.users'));

        $membership = OrganisationMembership::where('organisation_id', $organisation->id)->where('user_id', $target->id)->firstOrFail();
        $this->assertTrue($membership->roles()->whereKey($role->id)->exists());
        $this->assertTrue($target->fresh()->hasOrganisationPermission($organisation->id, 'forms.view'));
        $this->assertFalse($target->fresh()->hasOrganisationPermission($otherOrganisation->id, 'forms.view'));
        $this->assertTrue($otherMembership->fresh()->roles()->whereKey($respondentRole->id)->exists());
        $this->assertFalse($otherMembership->roles()->whereKey($role->id)->exists());
    }

    public function test_unused_custom_role_is_deleted_with_permissions_but_audit_is_retained(): void
    {
        $admin = $this->platformAdmin();
        $role = Role::create(['name' => 'unused_custom', 'display_name' => 'Unused custom', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('name', 'forms.view')->firstOrFail());

        $this->actingAs($admin)->delete(route('system.roles.destroy', $role))->assertRedirect(route('system.roles'));

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'role.deleted', 'subject_id' => $role->id]);
    }

    public function test_assigned_custom_role_cannot_be_deleted_from_either_assignment_pivot(): void
    {
        $admin = $this->platformAdmin();
        $organisation = $this->organisation('Assignments');
        $membershipRole = Role::create(['name' => 'membership_custom', 'display_name' => 'Membership custom', 'scope' => 'organisation', 'is_system' => false]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => User::factory()->create()->id, 'is_active' => true]);
        $membership->roles()->attach($membershipRole);
        $this->actingAs($admin)->delete(route('system.roles.destroy', $membershipRole))->assertSessionHasErrors('role');

        $globalPivotRole = Role::create(['name' => 'forged_global_pivot', 'display_name' => 'Forged pivot', 'scope' => 'organisation', 'is_system' => false]);
        User::factory()->create()->globalRoles()->attach($globalPivotRole);
        $this->actingAs($admin)->delete(route('system.roles.destroy', $globalPivotRole))->assertSessionHasErrors('role');

        $this->assertDatabaseHas('roles', ['id' => $membershipRole->id]);
        $this->assertDatabaseHas('roles', ['id' => $globalPivotRole->id]);
    }

    public function test_all_system_roles_are_protected_from_manual_delete(): void
    {
        $admin = $this->platformAdmin();
        $custom = Role::create(['name' => 'visible_custom_delete', 'display_name' => 'Visible custom', 'scope' => 'organisation', 'is_system' => false]);
        $page = $this->actingAs($admin)->get(route('system.roles'))->assertOk()
            ->assertSee('data-custom-role-delete="'.$custom->id.'"', false);
        foreach (Role::SYSTEM_NAMES as $name) {
            $role = Role::where('name', $name)->firstOrFail();
            $this->assertTrue($role->is_system, $name);
            $page->assertDontSee('data-custom-role-delete="'.$role->id.'"', false);
            $this->actingAs($admin)->delete(route('system.roles.destroy', $role))->assertSessionHasErrors('role');
            $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => $name]);
        }
    }

    public function test_role_permission_seeder_preserves_custom_role_and_assignments(): void
    {
        $organisation = $this->organisation('Seeder');
        $role = Role::create(['name' => 'preserved_custom', 'display_name' => 'Preserved custom', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->attach(Permission::where('name', 'forms.view')->firstOrFail());
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => User::factory()->create()->id, 'is_active' => true]);
        $membership->roles()->attach($role);

        $this->seed(RolePermissionSeeder::class);

        $role->refresh();
        $this->assertFalse($role->is_system);
        $this->assertTrue($role->permissions()->where('name', 'forms.view')->exists());
        $this->assertTrue($membership->roles()->whereKey($role->id)->exists());
    }

    public function test_custom_patient_permissions_do_not_bypass_doctor_role_or_patient_ownership(): void
    {
        $organisation = $this->organisation('Clinical');
        $doctor = $this->organisationMember('doctor', $organisation);
        $customUser = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $customUser->id, 'is_active' => true]);
        $role = Role::create(['name' => 'clinical_observer', 'display_name' => 'Clinical observer', 'scope' => 'organisation', 'is_system' => false]);
        $role->permissions()->sync(Permission::whereIn('name', ['doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view'])->pluck('id'));
        $membership->roles()->attach($role);
        $patient = PatientCase::create(['organisation_id' => $organisation->id, 'doctor_id' => $doctor->id, 'slot_number' => 1]);

        $this->assertFalse($customUser->fresh()->hasDoctorWorkspace());
        $this->assertTrue(Gate::forUser($customUser)->denies('view', $patient));
        $this->assertTrue(Gate::forUser($customUser)->denies('update', $patient));
        $this->actingAs($customUser)->get(route('doctor.dashboard'))->assertForbidden();
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        return $user;
    }

    private function organisation(string $name): Organisation
    {
        return Organisation::create(['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)), 'is_active' => true]);
    }

    private function organisationMember(string $roleName, Organisation $organisation): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $membership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $user->id, 'is_active' => true]);
        $membership->roles()->attach(Role::where('name', $roleName)->firstOrFail());
        return $user;
    }
}
