<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_cases', function (Blueprint $table) {
            $table->string('first_name', 100)->nullable()->after('patient_code');
            $table->string('last_name', 100)->nullable()->after('first_name');
            $table->string('external_patient_code', 100)->nullable()->after('last_name');
        });

        $platformRoleId = DB::table('roles')->where('name', 'platform_admin')->value('id');
        $clinicalPermissionIds = DB::table('permissions')->whereIn('name', [
            'doctor.dashboard.view',
            'patients.view',
            'patients.update',
            'patient.questionnaires.view',
        ])->pluck('id');

        if ($platformRoleId && $clinicalPermissionIds->isNotEmpty()) {
            DB::table('role_permissions')
                ->where('role_id', $platformRoleId)
                ->whereIn('permission_id', $clinicalPermissionIds)
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('patient_cases', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'external_patient_code']);
        });
    }
};
