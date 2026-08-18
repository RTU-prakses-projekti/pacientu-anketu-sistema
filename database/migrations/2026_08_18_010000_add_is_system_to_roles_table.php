<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SYSTEM_ROLES = [
        'platform_admin', 'organisation_admin', 'form_creator', 'doctor', 'reviewer', 'respondent',
    ];

    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('scope');
        });

        DB::table('roles')->whereIn('name', self::SYSTEM_ROLES)->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
