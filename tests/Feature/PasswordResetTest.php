<?php

namespace Tests\Feature;

use App\Models\Organisation;
use App\Models\OrganisationMembership;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_platform_root_resets_password_and_user_must_change_it_before_using_the_app(): void
    {
        $root = User::factory()->create(['is_active' => true]);
        $root->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        $target = User::factory()->create(['is_active' => true, 'password' => Hash::make('OriginalPassword123')]);
        $this->assertFalse($target->must_change_password);
        $temporaryPassword = 'TemporaryPassword123';

        $this->actingAs($root)->post(route('system.users.password-reset', $target), [
            'password' => $temporaryPassword,
            'password_confirmation' => $temporaryPassword,
        ])->assertRedirect();

        $target->refresh();
        $this->assertTrue(Hash::check($temporaryPassword, $target->password));
        $this->assertNotSame($temporaryPassword, $target->password);
        $this->assertTrue($target->must_change_password);
        $audit = \App\Models\AuditLog::where('action', 'user.password_reset')->where('subject_id', $target->id)->firstOrFail();
        $this->assertTrue($audit->metadata['must_change_password']);
        $this->assertStringNotContainsString($temporaryPassword, json_encode($audit->getAttributes()));
        $this->assertSame(['must_change_password' => true], $audit->metadata);

        $this->post(route('logout'))->assertRedirect('/login');
        $this->from(route('login'))->post(route('login'), ['email' => $target->email, 'password' => $temporaryPassword])
            ->assertRedirect(route('account.password.change'));
        $this->get(route('dashboard'))->assertRedirect(route('account.password.change'));
        $this->get(route('account.password.change'))->assertOk();
        $this->post(route('logout'))->assertRedirect('/login');

        $this->post(route('login'), ['email' => $target->email, 'password' => $temporaryPassword])
            ->assertRedirect(route('account.password.change'));
        $this->post(route('locale', 'en'))->assertRedirect();
        $this->assertSame('en', $target->fresh()->locale);
        $newPassword = 'ReplacementPassword456';
        $this->put(route('account.password.update'), [
            'current_password' => $temporaryPassword,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertRedirect(route('dashboard'));

        $target->refresh();
        $this->assertFalse($target->must_change_password);
        $this->assertTrue(Hash::check($newPassword, $target->password));
        $this->assertFalse(Hash::check($temporaryPassword, $target->password));
        $this->get(route('dashboard'))->assertOk();
        $this->post(route('logout'));
        $this->from(route('login'))->post(route('login'), ['email' => $target->email, 'password' => $temporaryPassword])
            ->assertSessionHasErrors('email');
        $this->post(route('login'), ['email' => $target->email, 'password' => $newPassword])
            ->assertRedirect(route('dashboard'));
    }

    public function test_only_authorized_global_or_same_organisation_administrators_can_reset_passwords(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $administrator->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $root = User::factory()->create(['is_active' => true]);
        $root->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());
        $this->actingAs($administrator)->post(route('system.users.password-reset', $root), [
            'password' => 'TemporaryPassword123',
            'password_confirmation' => 'TemporaryPassword123',
        ])->assertForbidden();
        $adminManagedUser = User::factory()->create(['is_active' => true, 'password' => Hash::make('OriginalPassword123')]);
        $this->actingAs($administrator)->post(route('system.users.password-reset', $adminManagedUser), [
            'password' => 'ProductAdminTemp123',
            'password_confirmation' => 'ProductAdminTemp123',
        ])->assertRedirect();
        $this->assertTrue($adminManagedUser->fresh()->must_change_password);

        $organisation = Organisation::create(['name' => 'Managed', 'slug' => Str::lower(Str::random(12)), 'is_active' => true]);
        $otherOrganisation = Organisation::create(['name' => 'Foreign', 'slug' => Str::lower(Str::random(12)), 'is_active' => true]);
        $organisationAdmin = User::factory()->create(['is_active' => true]);
        $adminMembership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $organisationAdmin->id, 'is_active' => true]);
        $adminMembership->roles()->attach(Role::where('name', 'organisation_admin')->firstOrFail());
        $member = User::factory()->create(['is_active' => true, 'password' => Hash::make('OriginalPassword123')]);
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $member->id, 'is_active' => true]);
        $foreignMember = User::factory()->create(['is_active' => true, 'password' => Hash::make('OriginalPassword123')]);
        OrganisationMembership::create(['organisation_id' => $otherOrganisation->id, 'user_id' => $foreignMember->id, 'is_active' => true]);
        $globalAdministrator = User::factory()->create(['is_active' => true, 'password' => Hash::make('OriginalPassword123')]);
        $globalAdministrator->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $globalAdministrator->id, 'is_active' => true]);

        $temporaryPassword = 'LocalTemporary123';
        $this->actingAs($organisationAdmin)->post(route('users.password-reset', [$organisation, $member]), [
            'password' => $temporaryPassword,
            'password_confirmation' => $temporaryPassword,
        ])->assertRedirect();
        $this->assertTrue($member->fresh()->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $member->fresh()->password));

        $originalHash = $foreignMember->password;
        $this->post(route('users.password-reset', [$organisation, $foreignMember]), [
            'password' => 'ForeignTemporary123',
            'password_confirmation' => 'ForeignTemporary123',
        ])->assertNotFound();
        $this->assertSame($originalHash, $foreignMember->fresh()->password);
        $this->assertFalse($foreignMember->fresh()->must_change_password);
        $globalHash = $globalAdministrator->password;
        $this->post(route('users.password-reset', [$organisation, $globalAdministrator]), [
            'password' => 'AdminTemporary123',
            'password_confirmation' => 'AdminTemporary123',
        ])->assertForbidden();
        $this->assertSame($globalHash, $globalAdministrator->fresh()->password);
        $this->assertFalse($globalAdministrator->fresh()->must_change_password);

        $doctor = User::factory()->create(['is_active' => true]);
        $doctorMembership = OrganisationMembership::create(['organisation_id' => $organisation->id, 'user_id' => $doctor->id, 'is_active' => true]);
        $doctorMembership->roles()->attach(Role::where('name', 'doctor')->firstOrFail());
        $this->actingAs($doctor)->post(route('system.users.password-reset', $member), [
            'password' => 'BlockedTemporary123',
            'password_confirmation' => 'BlockedTemporary123',
        ])->assertForbidden();
        $this->assertTrue($member->fresh()->must_change_password);
        $this->assertTrue(Hash::check($temporaryPassword, $member->fresh()->password));
    }
}
