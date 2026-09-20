<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\DateTimeDisplay;
use Database\Seeders\RolePermissionSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateTimeDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_utc_display_handles_riga_winter_summer_and_dst_without_mutating_source(): void
    {
        config(['app.display_timezone' => 'Europe/Riga']);
        $winter = new DateTimeImmutable('2026-01-15 10:00:00', new DateTimeZone('UTC'));
        $summer = new DateTimeImmutable('2026-07-15 10:00:00', new DateTimeZone('UTC'));
        $beforeDstJump = new DateTimeImmutable('2026-03-29 00:30:00', new DateTimeZone('UTC'));
        $afterDstJump = new DateTimeImmutable('2026-03-29 01:30:00', new DateTimeZone('UTC'));

        $this->assertSame('15.01.2026 12:00', DateTimeDisplay::format($winter));
        $this->assertSame('15.07.2026 13:00', DateTimeDisplay::format($summer));
        $this->assertSame('29.03.2026 02:30', DateTimeDisplay::format($beforeDstJump));
        $this->assertSame('29.03.2026 04:30', DateTimeDisplay::format($afterDstJump));
        $this->assertSame('UTC', $winter->getTimezone()->getName());
        $this->assertSame('2026-09-20 10:00:00', DateTimeDisplay::parseLocalInput('2026-09-20T13:00')->format('Y-m-d H:i:s'));
    }

    public function test_audit_timestamp_is_displayed_in_riga_while_database_value_remains_utc(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->globalRoles()->attach(Role::where('name', 'administrator')->firstOrFail());
        $log = AuditLog::create([
            'actor_id' => $admin->id,
            'action' => 'timezone.audit.test',
            'request_id' => 'timezone-audit-test',
            'ip_hash' => null,
            'metadata' => [],
            'created_at' => '2026-01-15 10:00:00',
        ]);

        $this->actingAs($admin)->get(route('audit.system'))->assertOk()->assertSee('15.01.2026 12:00');
        $this->assertSame('2026-01-15 10:00:00', $log->fresh()->getRawOriginal('created_at'));
    }
}
