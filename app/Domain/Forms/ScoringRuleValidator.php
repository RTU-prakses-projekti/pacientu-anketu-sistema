<?php

namespace App\Domain\Forms;

use App\Models\FormComponent;
use Illuminate\Validation\ValidationException;

class ScoringRuleValidator
{
    public function validate(FormComponent $component, string $strategy, array $rules, bool $requireCorrect = false): array
    {
        if ($strategy === 'none' || $strategy === 'manual') return $rules;
        if ($strategy === 'single_choice') {
            if (!in_array($component->type, ['single_choice', 'dropdown'], true)) $this->invalid();
            $correct = $rules['correct'] ?? null;
            if ($correct === null || $correct === '') { if ($requireCorrect) $this->invalid(); return $rules; }
            if (is_array($correct) || !$component->options()->where('value', (string) $correct)->exists()) $this->invalid();
            $rules['correct'] = (string) $correct;
        } elseif (in_array($strategy, ['multiple_all_or_nothing', 'multiple_partial'], true)) {
            if ($component->type !== 'multiple_choice') $this->invalid();
            $correct = array_values(array_unique(array_map('strval', (array) ($rules['correct'] ?? []))));
            if (($requireCorrect && $correct === []) || $component->options()->whereIn('value', $correct)->count() !== count($correct)) $this->invalid();
            $rules['correct'] = $correct;
        } elseif ($strategy === 'yes_no') {
            if (($rules['correct'] ?? null) === null || $rules['correct'] === '') { if ($requireCorrect) $this->invalid(); return $rules; }
            if ($component->type !== 'yes_no' || !in_array((string) ($rules['correct'] ?? ''), ['0', '1'], true)) $this->invalid();
            $rules['correct'] = (string) $rules['correct'];
        } elseif (in_array($strategy, ['numeric_exact', 'numeric_tolerance'], true)) {
            if (($rules['correct'] ?? null) === null || $rules['correct'] === '') { if ($requireCorrect) $this->invalid(); return $rules; }
            if ($component->type !== 'number' || !is_numeric($rules['correct'] ?? null)) $this->invalid();
            $rules['correct'] = (float) $rules['correct'];
            if ($strategy === 'numeric_tolerance') {
                if (!is_numeric($rules['tolerance'] ?? null) || (float) $rules['tolerance'] < 0) $this->invalid();
                $rules['tolerance'] = (float) $rules['tolerance'];
            }
        }
        return $rules;
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['scoring_rules' => __('messages.invalid_scoring_rule')]);
    }
}
