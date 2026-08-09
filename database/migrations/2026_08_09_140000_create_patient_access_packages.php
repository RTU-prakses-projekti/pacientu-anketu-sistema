<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_access_packages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('patient_case_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['patient_case_id', 'revoked_at', 'expires_at'], 'patient_access_package_state_index');
        });

        Schema::table('patient_form_assignments', function (Blueprint $table) {
            $table->foreignId('patient_access_package_id')->nullable()->after('invitation_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('patient_form_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_access_package_id');
        });
        Schema::dropIfExists('patient_access_packages');
    }
};
