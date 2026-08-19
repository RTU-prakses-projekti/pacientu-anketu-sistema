<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questionnaire_package_part_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organisation_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_id')->constrained()->restrictOnDelete();
            $table->foreignId('form_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->string('content_hash', 64);
            $table->string('package_name');
            $table->timestamps();
            $table->unique(['form_version_id', 'content_hash'], 'questionnaire_part_version_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questionnaire_package_part_imports');
    }
};
