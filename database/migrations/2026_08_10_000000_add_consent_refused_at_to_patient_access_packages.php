<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_access_packages', function (Blueprint $table): void {
            $table->timestamp('consent_refused_at')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('patient_access_packages', function (Blueprint $table): void {
            $table->dropColumn('consent_refused_at');
        });
    }
};