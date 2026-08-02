<?php

namespace App\Domain\Forms;

class LocalizedContent
{
    public function resolve(?array $translations, string $field, mixed $baseValue = null, ?string $locale = null): mixed
    {
        foreach ($this->candidates($translations, $field, $baseValue, $locale) as $candidate) {
            if ($this->isPresent($candidate['value'])) {
                return $this->clean($candidate['value']);
            }
        }

        return null;
    }

    public function sourceLocale(?array $translations, string $field, mixed $baseValue = null, ?string $locale = null): ?string
    {
        foreach ($this->candidates($translations, $field, $baseValue, $locale) as $candidate) {
            if ($this->isPresent($candidate['value'])) {
                return $candidate['locale'];
            }
        }

        return null;
    }

    public function usesFallback(?array $translations, string $field, mixed $baseValue = null, ?string $locale = null): bool
    {
        $requested = $this->locale($locale);
        $source = $this->sourceLocale($translations, $field, $baseValue, $requested);

        return $source !== null && $source !== $requested;
    }

    public function normalize(?array $translations, array $allowedFields): ?array
    {
        $normalized = [];
        $allowed = array_flip($allowedFields);

        foreach ($this->supported() as $locale) {
            $values = $translations[$locale] ?? null;
            if (!is_array($values)) {
                continue;
            }

            foreach (array_intersect_key($values, $allowed) as $field => $value) {
                if ($this->isPresent($value)) {
                    $normalized[$locale][$field] = $this->clean($value);
                }
            }
        }

        return $normalized ?: null;
    }

    public function supported(): array
    {
        return array_values(config('form_locales.supported', ['lv', 'en', 'ru']));
    }

    public function locale(?string $locale = null): string
    {
        $requested = $locale ?? app()->getLocale();

        return in_array($requested, $this->supported(), true)
            ? $requested
            : (string) config('form_locales.default', 'lv');
    }

    public function isPresent(mixed $value): bool
    {
        return $value !== null && (!is_string($value) || trim($value) !== '');
    }

    private function candidates(?array $translations, string $field, mixed $baseValue, ?string $locale): array
    {
        $translations ??= [];
        $requested = $this->locale($locale);
        $default = (string) config('form_locales.default', 'lv');
        $fallback = (string) config('form_locales.fallback', 'en');
        $candidates = [
            ['locale' => $requested, 'value' => $translations[$requested][$field] ?? null],
            ['locale' => $default, 'value' => $translations[$default][$field] ?? null],
            ['locale' => 'base', 'value' => $baseValue],
        ];

        if (in_array($fallback, $this->supported(), true)) {
            $candidates[] = ['locale' => $fallback, 'value' => $translations[$fallback][$field] ?? null];
        }

        foreach ($this->supported() as $supportedLocale) {
            $candidates[] = ['locale' => $supportedLocale, 'value' => $translations[$supportedLocale][$field] ?? null];
        }

        return $candidates;
    }

    private function clean(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
