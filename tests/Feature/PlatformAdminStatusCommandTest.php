<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command as ConsoleCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_status_reports_missing_without_changing_the_database(): void
    {
        $this->artisan('app:platform-admin-status')
            ->expectsOutput('MISSING')
            ->assertExitCode(ConsoleCommand::SUCCESS);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('user_roles', 0);
    }

    public function test_status_reports_exists_for_a_user_with_the_bootstrap_role(): void
    {
        $user = User::factory()->create();
        $user->globalRoles()->attach(Role::where('name', 'platform_admin')->firstOrFail());

        $this->artisan('app:platform-admin-status')
            ->expectsOutput('EXISTS')
            ->assertExitCode(ConsoleCommand::SUCCESS);
    }
}
