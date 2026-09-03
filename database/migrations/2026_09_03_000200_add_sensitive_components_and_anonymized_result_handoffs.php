<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('form_components', 'is_sensitive')) {
            Schema::table('form_components', function (Blueprint $table): void {
                $table->boolean('is_sensitive')->default(false)->after('is_required');
            });
        }

        if (!Schema::hasTable('anonymized_result_handoffs')) Schema::create('anonymized_result_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_form_assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('handed_off_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('handed_off_at');
            $table->timestamps();
            $table->unique(['form_submission_id', 'recipient_user_id'], 'handoff_submission_recipient_unique');
            $table->index(['recipient_user_id', 'organisation_id'], 'handoff_recipient_org_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anonymized_result_handoffs');
        if (Schema::hasColumn('form_components', 'is_sensitive')) {
            Schema::table('form_components', function (Blueprint $table): void {
                $table->dropColumn('is_sensitive');
            });
        }
    }
};
