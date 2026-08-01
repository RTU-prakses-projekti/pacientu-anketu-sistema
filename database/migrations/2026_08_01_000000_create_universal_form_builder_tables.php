<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->string('locale', 8)->default('lv');
        });

        Schema::create('organisations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->string('scope')->default('organisation');
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('display_name');
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('organisation_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organisation_id', 'user_id']);
        });

        Schema::create('membership_roles', function (Blueprint $table) {
            $table->foreignId('organisation_membership_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->primary(['organisation_membership_id', 'role_id'], 'membership_roles_primary');
        });

        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('status')->default('draft');
            $table->string('preset_key')->default('blank');
            $table->json('translations')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organisation_id', 'slug']);
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('draft');
            $table->json('settings')->nullable();
            $table->string('content_hash')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['form_id', 'version_number']);
            $table->index(['form_id', 'status']);
        });

        Schema::create('form_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->string('stable_key');
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('visible')->default(true);
            $table->json('translations')->nullable();
            $table->timestamps();
            $table->unique(['form_version_id', 'stable_key']);
            $table->index(['form_version_id', 'display_order']);
        });

        Schema::create('form_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_section_id')->constrained()->restrictOnDelete();
            $table->string('stable_key');
            $table->string('type');
            $table->string('label');
            $table->text('description')->nullable();
            $table->text('help_text')->nullable();
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('visible')->default(true);
            $table->decimal('max_points', 10, 2)->default(0);
            $table->boolean('manual_grading')->default(false);
            $table->json('settings')->nullable();
            $table->json('translations')->nullable();
            $table->timestamps();
            $table->unique(['form_version_id', 'stable_key']);
            $table->index(['form_section_id', 'display_order']);
            $table->index(['form_version_id', 'type']);
        });

        Schema::create('component_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_component_id')->constrained()->restrictOnDelete();
            $table->string('stable_key');
            $table->string('label');
            $table->string('value');
            $table->unsignedInteger('display_order')->default(0);
            $table->json('translations')->nullable();
            $table->timestamps();
            $table->unique(['form_component_id', 'stable_key']);
            $table->unique(['form_component_id', 'value']);
        });

        Schema::create('validation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_component_id')->constrained()->restrictOnDelete();
            $table->string('rule_type');
            $table->unsignedInteger('display_order')->default(0);
            $table->json('parameters')->nullable();
            $table->json('message_translations')->nullable();
            $table->timestamps();
        });

        Schema::create('conditional_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_component_id')->constrained('form_components')->restrictOnDelete();
            $table->string('operator');
            $table->json('comparison_value')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
            $table->index(['form_version_id', 'priority']);
        });

        Schema::create('conditional_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conditional_rule_id')->constrained()->restrictOnDelete();
            $table->string('action');
            $table->foreignId('target_component_id')->nullable()->constrained('form_components')->restrictOnDelete();
            $table->foreignId('target_section_id')->nullable()->constrained('form_sections')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('scoring_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_component_id')->constrained()->restrictOnDelete();
            $table->string('strategy');
            $table->decimal('max_points', 10, 2)->default(0);
            $table->json('rules');
            $table->timestamps();
            $table->unique('form_component_id');
        });

        Schema::create('publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->string('public_key')->unique();
            $table->string('name');
            $table->string('status')->default('inactive');
            $table->string('access_mode')->default('authenticated');
            $table->string('access_code_hash')->nullable();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedInteger('attempt_limit')->default(1);
            $table->boolean('timer_enabled')->default(false);
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('result_visibility')->default('completion');
            $table->boolean('correct_answers_visible')->default(false);
            $table->boolean('anonymous_allowed')->default(false);
            $table->boolean('identified_required')->default(true);
            $table->boolean('consent_required')->default(false);
            $table->boolean('autosave_enabled')->default(true);
            $table->boolean('resume_enabled')->default(true);
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->restrictOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('recipient_reference')->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('anonymous_key_hash', 64)->nullable();
            $table->unsignedInteger('attempt_number');
            $table->string('status')->default('in_progress');
            $table->timestamp('started_at');
            $table->timestamp('deadline_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->decimal('maximum_points', 10, 2)->default(0);
            $table->decimal('automatic_points', 10, 2)->default(0);
            $table->decimal('manual_points', 10, 2)->default(0);
            $table->decimal('final_points', 10, 2)->default(0);
            $table->decimal('percentage', 7, 2)->nullable();
            $table->string('grading_status')->default('not_required');
            $table->text('invalidation_reason')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
            $table->index(['publication_id', 'user_id']);
            $table->index(['publication_id', 'anonymous_key_hash']);
            $table->unique(['publication_id', 'user_id', 'attempt_number'], 'submission_user_attempt_unique');
            $table->unique(['publication_id', 'anonymous_key_hash', 'attempt_number'], 'submission_anon_attempt_unique');
        });

        Schema::create('submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_component_id')->constrained()->restrictOnDelete();
            $table->json('value')->nullable();
            $table->text('display_value')->nullable();
            $table->unsignedBigInteger('answer_revision')->default(1);
            $table->timestamp('saved_at');
            $table->timestamps();
            $table->unique(['form_submission_id', 'form_component_id'], 'submission_component_answer_unique');
        });

        Schema::create('attempt_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publication_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('anonymous_key_hash', 64)->nullable();
            $table->unsignedInteger('additional_attempts')->default(1);
            $table->text('reason');
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['publication_id', 'user_id']);
        });

        Schema::create('submission_mutations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->restrictOnDelete();
            $table->uuid('client_mutation_id');
            $table->unsignedBigInteger('acknowledged_revision');
            $table->timestamps();
            $table->unique(['form_submission_id', 'client_mutation_id'], 'submission_mutation_unique');
        });

        Schema::create('answer_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_answer_id')->constrained()->restrictOnDelete();
            $table->decimal('automatic_points', 10, 2)->default(0);
            $table->decimal('manual_points', 10, 2)->default(0);
            $table->decimal('final_points', 10, 2)->default(0);
            $table->text('reviewer_comment')->nullable();
            $table->foreignId('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
            $table->unique('submission_answer_id');
        });

        Schema::create('consent_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_component_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->string('decision');
            $table->string('consent_text_hash', 64);
            $table->timestamp('recorded_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
            $table->unique(['form_submission_id', 'form_component_id']);
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->nullableMorphs('attachable');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('local');
            $table->string('storage_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('status')->default('ready');
            $table->timestamps();
        });

        Schema::create('exports', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('form_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('format');
            $table->string('status')->default('pending');
            $table->json('filters')->nullable();
            $table->string('storage_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->index(['organisation_id', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->string('request_id')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['organisation_id', 'action', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'exports', 'attachments', 'consent_records', 'answer_scores', 'submission_mutations', 'attempt_grants', 'submission_answers', 'form_submissions', 'invitations', 'publications', 'scoring_rules', 'conditional_actions', 'conditional_rules', 'validation_rules', 'component_options', 'form_components', 'form_sections', 'form_versions', 'forms', 'membership_roles', 'organisation_memberships', 'user_roles', 'role_permissions', 'permissions', 'roles', 'organisations'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'locale']);
        });
    }
};
