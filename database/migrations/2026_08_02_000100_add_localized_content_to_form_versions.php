<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_versions', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('translations')->nullable();
        });

        DB::table('forms')
            ->select(['id', 'name', 'translations'])
            ->orderBy('id')
            ->chunkById(100, function ($forms): void {
                foreach ($forms as $form) {
                    $source = $this->decodeTranslations($form->translations);
                    $translations = [];

                    foreach (['lv', 'en', 'ru'] as $locale) {
                        $localeValues = is_array($source[$locale] ?? null) ? $source[$locale] : [];
                        foreach (['title', 'description', 'completion_text', 'result_text'] as $field) {
                            $value = $localeValues[$field] ?? ($field === 'title' ? ($localeValues['name'] ?? null) : null);
                            if (is_string($value) && trim($value) !== '') {
                                $translations[$locale][$field] = trim($value);
                            }
                        }
                    }

                    if (!isset($translations['lv']['title']) && is_string($form->name) && trim($form->name) !== '') {
                        $translations['lv']['title'] = trim($form->name);
                    }

                    DB::table('form_versions')
                        ->where('form_id', $form->id)
                        ->update([
                            'title' => $form->name,
                            'translations' => $translations ? json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('form_versions', function (Blueprint $table) {
            $table->dropColumn(['title', 'description', 'translations']);
        });
    }

    private function decodeTranslations(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
};
