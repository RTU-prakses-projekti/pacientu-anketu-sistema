<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REMOVED_PERMISSIONS = [
        'submissions.grade',
        'submissions.manage',
        'publications.respond',
    ];

    public function up(): void
    {
        $now = now();
        $permissions = [
            'organisation.view', 'organisation.manage', 'forms.view', 'forms.create', 'forms.update', 'forms.publish', 'forms.archive',
            'submissions.view', 'exports.create', 'exports.download', 'audit.view', 'users.manage',
            'doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view',
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission],
                ['display_name' => ucwords(str_replace(['.', '_'], ' ', $permission)), 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $administratorRoleId = DB::table('roles')->where('name', 'administrator')->value('id');
        if (!$administratorRoleId) {
            $administratorRoleId = DB::table('roles')->insertGetId([
                'name' => 'administrator',
                'display_name' => 'Administrators',
                'scope' => 'global',
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $platformRoleId = DB::table('roles')->where('name', 'platform_admin')->value('id');
        if ($platformRoleId) {
            $rootUserId = DB::table('user_roles')->where('role_id', $platformRoleId)->orderBy('user_id')->value('user_id');
            if ($rootUserId) {
                DB::table('user_roles')->where('role_id', $platformRoleId)->where('user_id', '!=', $rootUserId)->orderBy('user_id')->get()
                    ->each(function ($assignment) use ($administratorRoleId, $platformRoleId): void {
                        DB::table('user_roles')->insertOrIgnore(['user_id' => $assignment->user_id, 'role_id' => $administratorRoleId]);
                        DB::table('user_roles')->where('user_id', $assignment->user_id)->where('role_id', $platformRoleId)->delete();
                    });
            }

            DB::table('roles')->where('id', $platformRoleId)->update([
                'display_name' => 'Bootstrap root',
                'scope' => 'global',
                'is_system' => true,
                'updated_at' => $now,
            ]);
        }

        $nonClinicalPermissionIds = DB::table('permissions')->whereIn('name', array_diff($permissions, [
            'doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view',
        ]))->pluck('id');
        DB::table('role_permissions')->where('role_id', $administratorRoleId)->delete();
        foreach ($nonClinicalPermissionIds as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $administratorRoleId, 'permission_id' => $permissionId]);
        }

        $labels = [
            'organisation_admin' => 'Administratora palīgs',
            'form_creator' => 'Anketu pārvaldnieks',
            'doctor' => 'Ārsts',
        ];
        foreach ($labels as $name => $label) {
            DB::table('roles')->where('name', $name)->update(['display_name' => $label, 'updated_at' => $now]);
        }

        $rolePermissionSets = [
            'organisation_admin' => ['organisation.view', 'organisation.manage', 'forms.view', 'forms.create', 'forms.update', 'forms.publish', 'forms.archive', 'audit.view', 'users.manage'],
            'form_creator' => ['organisation.view', 'forms.view', 'forms.create', 'forms.update', 'forms.publish', 'forms.archive'],
            'doctor' => ['doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view'],
        ];
        foreach ($rolePermissionSets as $roleName => $rolePermissions) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (!$roleId) continue;
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            foreach (DB::table('permissions')->whereIn('name', $rolePermissions)->pluck('id') as $permissionId) {
                DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        $removedRoleIds = DB::table('roles')->whereIn('name', ['reviewer', 'respondent'])->pluck('id');
        if ($removedRoleIds->isNotEmpty()) {
            DB::table('membership_roles')->whereIn('role_id', $removedRoleIds)->delete();
            DB::table('user_roles')->whereIn('role_id', $removedRoleIds)->delete();
            DB::table('role_permissions')->whereIn('role_id', $removedRoleIds)->delete();
            DB::table('roles')->whereIn('id', $removedRoleIds)->delete();
        }

        $removedPermissionIds = DB::table('permissions')->whereIn('name', self::REMOVED_PERMISSIONS)->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $removedPermissionIds)->delete();
        DB::table('permissions')->whereIn('id', $removedPermissionIds)->delete();
    }

    public function down(): void
    {
        // Forward-only role restructuring: deleted reviewer/respondent assignments
        // cannot be reconstructed by this rollback.
        $now = now();
        foreach (self::REMOVED_PERMISSIONS as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission],
                ['display_name' => ucwords(str_replace(['.', '_'], ' ', $permission)), 'created_at' => $now, 'updated_at' => $now],
            );
        }
        foreach (['reviewer' => 'Vērtētājs', 'respondent' => 'Pacients'] as $name => $label) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $label, 'scope' => 'organisation', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
        $administratorRoleId = DB::table('roles')->where('name', 'administrator')->value('id');
        if ($administratorRoleId) {
            DB::table('user_roles')->where('role_id', $administratorRoleId)->delete();
            DB::table('role_permissions')->where('role_id', $administratorRoleId)->delete();
            DB::table('roles')->where('id', $administratorRoleId)->delete();
        }
    }
};
