<?php

namespace App\Models\Concerns;

use App\Domain\Forms\LocalizedContent;

trait HasLocalizedContent
{
    protected function localized(string $field, mixed $baseValue = null, ?string $locale = null): mixed
    {
        return app(LocalizedContent::class)->resolve($this->translations, $field, $baseValue, $locale);
    }

    protected function localizedSourceLocale(string $field, mixed $baseValue = null, ?string $locale = null): ?string
    {
        return app(LocalizedContent::class)->sourceLocale($this->translations, $field, $baseValue, $locale);
    }

    public function usesLocalizedFallback(string $field, mixed $baseValue = null, ?string $locale = null): bool
    {
        return app(LocalizedContent::class)->usesFallback($this->translations, $field, $baseValue, $locale);
    }

    public function usesAnyLocalizedFallback(array $fields, ?string $locale = null): bool
    {
        foreach ($fields as $field => $baseValue) {
            if ($this->usesLocalizedFallback($field, $baseValue, $locale)) return true;
        }

        return false;
    }
}
