<?php

namespace App\Http\Requests;

use App\Domain\Forms\ComponentRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComponentRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('update', $this->route('form')) ?? false; }
    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', 'exists:form_sections,id'],
            'type' => ['required', Rule::in(array_keys(app(ComponentRegistry::class)->all()))],
            'label' => ['nullable', 'string', 'max:500'], 'description' => ['nullable', 'string', 'max:10000'], 'help_text' => ['nullable', 'string', 'max:2000'],
            'is_required' => ['sometimes', 'boolean'], 'visible' => ['sometimes', 'boolean'], 'max_points' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'manual_grading' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array'], 'options' => ['nullable', 'array', 'max:100'], 'options.*' => ['nullable', 'string', 'max:500'],
            'scoring_strategy' => ['nullable', Rule::in(['none', 'single_choice', 'multiple_all_or_nothing', 'multiple_partial', 'yes_no', 'numeric_exact', 'numeric_tolerance', 'manual'])],
            'scoring_rules' => ['nullable', 'array'], 'translations' => ['nullable', 'array'],
        ];
    }
}
