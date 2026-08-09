<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_cases', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('slot_number');
            $table->string('patient_code', 32)->unique();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['organisation_id', 'doctor_id', 'slot_number'], 'patient_cases_workspace_slot_unique');
            $table->index(['organisation_id', 'doctor_id']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            DB::statement('ALTER TABLE patient_cases ADD CONSTRAINT patient_cases_slot_number_check CHECK (slot_number BETWEEN 1 AND 200)');
        }

        Schema::create('patient_form_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('patient_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('label');
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamp('assigned_at');
            $table->timestamps();
            $table->unique(['patient_case_id', 'publication_id'], 'patient_assignment_publication_unique');
            $table->unique('invitation_id');
            $table->index(['patient_case_id', 'display_order']);
        });

        $now = now();
        $permissionNames = [
            'doctor.dashboard.view' => 'Doctor dashboard view',
            'patients.view' => 'Patients view',
            'patients.update' => 'Patients update',
            'patient.questionnaires.view' => 'Patient questionnaires view',
        ];

        foreach ($permissionNames as $name => $displayName) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $displayName, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $displayNames = [
            'platform_admin' => 'Admin 1',
            'organisation_admin' => 'Admin 2',
            'form_creator' => 'Admin 3',
            'reviewer' => 'Vērtētājs',
            'respondent' => 'Pacients',
        ];
        foreach ($displayNames as $name => $displayName) {
            DB::table('roles')->where('name', $name)->update(['display_name' => $displayName, 'updated_at' => $now]);
        }

        $doctorRoleId = DB::table('roles')->where('name', 'doctor')->value('id');
        if (!$doctorRoleId) {
            $doctorRoleId = DB::table('roles')->insertGetId([
                'name' => 'doctor',
                'display_name' => 'Ārsts',
                'scope' => 'organisation',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('roles')->where('id', $doctorRoleId)->update(['display_name' => 'Ārsts', 'scope' => 'organisation', 'updated_at' => $now]);
        }

        $doctorPermissionIds = DB::table('permissions')->whereIn('name', array_keys($permissionNames))->pluck('id');
        DB::table('role_permissions')->where('role_id', $doctorRoleId)->delete();
        foreach ($doctorPermissionIds as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $doctorRoleId, 'permission_id' => $permissionId]);
        }

        $platformRoleId = DB::table('roles')->where('name', 'platform_admin')->value('id');
        if ($platformRoleId) {
            foreach ($doctorPermissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $platformRoleId, 'permission_id' => $permissionId]);
            }
        }

        $admin3RoleId = DB::table('roles')->where('name', 'form_creator')->value('id');
        if ($admin3RoleId) {
            $admin3Permissions = DB::table('permissions')->whereIn('name', [
                'organisation.view', 'forms.view', 'forms.create', 'forms.update', 'forms.publish',
                'forms.archive', 'submissions.view', 'publications.respond',
            ])->pluck('id');
            DB::table('role_permissions')->where('role_id', $admin3RoleId)->delete();
            foreach ($admin3Permissions as $permissionId) {
                DB::table('role_permissions')->insert(['role_id' => $admin3RoleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_form_assignments');
        Schema::dropIfExists('patient_cases');

        $doctorRoleId = DB::table('roles')->where('name', 'doctor')->value('id');
        if ($doctorRoleId) {
            DB::table('membership_roles')->where('role_id', $doctorRoleId)->delete();
            DB::table('role_permissions')->where('role_id', $doctorRoleId)->delete();
            DB::table('roles')->where('id', $doctorRoleId)->delete();
        }
        $permissionIds = DB::table('permissions')->whereIn('name', [
            'doctor.dashboard.view', 'patients.view', 'patients.update', 'patient.questionnaires.view',
        ])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
