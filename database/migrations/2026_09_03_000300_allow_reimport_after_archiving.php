<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questionnaire_package_imports', function (Blueprint $table) {
            $table->index(['organisation_id', 'content_hash'], 'questionnaire_import_org_hash_index');
        });

        Schema::table('questionnaire_package_imports', function (Blueprint $table) {
            $table->dropUnique('questionnaire_import_org_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('questionnaire_package_imports', function (Blueprint $table) {
            $table->unique(['organisation_id', 'content_hash'], 'questionnaire_import_org_hash_unique');
        });

        Schema::table('questionnaire_package_imports', function (Blueprint $table) {
            $table->dropIndex('questionnaire_import_org_hash_index');
        });
    }
};
