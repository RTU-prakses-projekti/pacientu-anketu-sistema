<?php

namespace App\Domain\Forms;

class LocalizedContentRules
{
    public static function for(string $prefix, array $fields, array $requiredLatvian = []): array
    {
        $locales = config('form_locales.supported', ['lv', 'en', 'ru']);
        $rules = [$prefix => ['nullable', 'array:'.implode(',', $locales)]];

        foreach ($locales as $locale) {
            $rules[$prefix.'.'.$locale] = ['nullable', 'array:'.implode(',', array_keys($fields))];
            foreach ($fields as $field => $fieldRules) {
                $required = $locale === config('form_locales.default', 'lv') && in_array($field, $requiredLatvian, true);
                $rules[$prefix.'.'.$locale.'.'.$field] = [$required ? 'required' : 'nullable', ...$fieldRules];
            }
        }

        return $rules;
    }
}
