<?php

namespace App\Domain\Submissions;

use App\Models\AnswerScore;
use App\Models\FormSubmission;
use App\Models\SubmissionAnswer;

class ScoringService
{
    public function score(FormSubmission $submission, ?array $visibility = null): array
    {
        $submission->loadMissing('answers.component.scoringRule', 'formVersion.components.scoringRule');
        $maximum = 0.0; $automatic = 0.0; $manualRequired = false;

        $answers = $submission->answers->keyBy('form_component_id');
        foreach ($submission->formVersion->components as $component) {
            if ($visibility !== null && (!(bool) ($visibility['components'][$component->id] ?? $component->visible)
                || !(bool) ($visibility['sections'][$component->form_section_id] ?? $component->section?->visible))) continue;
            $rule = $component->scoringRule;
            if (!$rule && !$component->manual_grading) continue;
            $max = (float) ($rule?->max_points ?? $component->max_points);
            $maximum += $max;
            $answer = $answers->get($component->id);
            $points = $answer && $rule ? $this->scoreAnswer($answer, $rule->strategy, $rule->rules ?? [], $max) : 0.0;
            $manualRequired = $manualRequired || $component->manual_grading || ($rule?->strategy === 'manual');
            if ($answer) AnswerScore::updateOrCreate(['submission_answer_id' => $answer->id], ['automatic_points' => $points, 'final_points' => $points]);
            $automatic += $points;
        }

        return ['maximum' => $maximum, 'automatic' => $automatic, 'manual_required' => $manualRequired];
    }

    private function scoreAnswer(SubmissionAnswer $answer, string $strategy, array $rules, float $max): float
    {
        $value = $answer->value;
        return match ($strategy) {
            'all_answers_correct' => $this->hasAnswer($value) ? $max : 0.0,
            'single_choice', 'yes_no' => (string) $value === (string) ($rules['correct'] ?? '') ? $max : 0.0,
            'multiple_all_or_nothing' => $this->setEquals((array) $value, (array) ($rules['correct'] ?? [])) ? $max : 0.0,
            'multiple_partial' => $this->partial((array) $value, (array) ($rules['correct'] ?? []), $max),
            'numeric_exact' => is_numeric($value) && (float) $value === (float) ($rules['correct'] ?? NAN) ? $max : 0.0,
            'numeric_tolerance' => is_numeric($value) && abs((float) $value - (float) ($rules['correct'] ?? NAN)) <= (float) ($rules['tolerance'] ?? 0) ? $max : 0.0,
            default => 0.0,
        };
    }

    private function hasAnswer(mixed $value): bool
    {
        if (is_array($value)) return $value !== [];
        if (is_bool($value)) return $value;
        return $value !== null && $value !== '';
    }

    private function setEquals(array $a, array $b): bool
    {
        sort($a); sort($b); return $a === $b;
    }

    private function partial(array $selected, array $correct, float $max): float
    {
        if ($correct === []) return 0.0;
        $right = count(array_intersect($selected, $correct));
        $wrong = count(array_diff($selected, $correct));
        return max(0.0, min($max, $max * (($right - $wrong) / count($correct))));
    }
}
