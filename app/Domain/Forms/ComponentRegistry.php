<?php

namespace App\Domain\Forms;

use App\Models\FormComponent;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ComponentRegistry
{
    private const DEFINITIONS = [
        'form_title' => ['name' => 'Form title', 'category' => 'content', 'answer' => false, 'settings' => ['layout_width']],
        'heading' => ['name' => 'Heading', 'category' => 'content', 'answer' => false, 'settings' => ['layout_width']],
        'explanatory_text' => ['name' => 'Explanatory text', 'category' => 'content', 'answer' => false, 'settings' => ['layout_width']],
        'image' => ['name' => 'Image', 'category' => 'content', 'answer' => false, 'settings' => ['attachment_id', 'image_title', 'image_caption', 'layout_width']],
        'file_attachment' => ['name' => 'File attachment', 'category' => 'content', 'answer' => false, 'settings' => ['attachment_id', 'layout_width']],
        'short_text' => ['name' => 'Short text', 'category' => 'input', 'answer' => true, 'settings' => ['placeholder', 'default_value', 'min_length', 'max_length', 'layout_width']],
        'long_text' => ['name' => 'Long text', 'category' => 'input', 'answer' => true, 'settings' => ['placeholder', 'default_value', 'min_length', 'max_length', 'layout_width']],
        'number' => ['name' => 'Number', 'category' => 'input', 'answer' => true, 'settings' => ['placeholder', 'default_value', 'minimum', 'maximum', 'tolerance', 'layout_width']],
        'date' => ['name' => 'Date', 'category' => 'input', 'answer' => true, 'settings' => ['minimum', 'maximum', 'layout_width']],
        'time' => ['name' => 'Time', 'category' => 'input', 'answer' => true, 'settings' => ['minimum', 'maximum', 'layout_width']],
        'yes_no' => ['name' => 'Yes / no', 'category' => 'input', 'answer' => true, 'settings' => ['default_value', 'layout_width']],
        'single_choice' => ['name' => 'Single choice', 'category' => 'input', 'answer' => true, 'settings' => ['randomize_options', 'layout_width']],
        'multiple_choice' => ['name' => 'Multiple choice', 'category' => 'input', 'answer' => true, 'settings' => ['randomize_options', 'partial_scoring', 'layout_width']],
        'dropdown' => ['name' => 'Dropdown', 'category' => 'input', 'answer' => true, 'settings' => ['placeholder', 'randomize_options', 'layout_width']],
        'rating_scale' => ['name' => 'Rating scale', 'category' => 'input', 'answer' => true, 'settings' => ['minimum', 'maximum', 'minimum_label', 'maximum_label', 'layout_width']],
        'linear_scale' => ['name' => 'Linear scale', 'category' => 'input', 'answer' => true, 'settings' => ['minimum', 'maximum', 'minimum_label', 'maximum_label', 'layout_width']],
        'consent_checkbox' => ['name' => 'Consent checkbox', 'category' => 'input', 'answer' => true, 'settings' => ['consent_text', 'refusal_policy', 'layout_width']],
    ];

    public function all(): array
    {
        return self::DEFINITIONS;
    }

    public function definition(string $type): array
    {
        if (!isset(self::DEFINITIONS[$type])) {
            throw ValidationException::withMessages(['type' => __('messages.invalid_component_type')]);
        }

        return ['key' => $type, ...self::DEFINITIONS[$type], 'answer_schema' => $this->answerSchema($type), 'renderer' => 'forms.components.generic'];
    }

    public function allowedSettings(string $type): array
    {
        return $this->definition($type)['settings'];
    }

    public function filterSettings(string $type, array $settings): array
    {
        return array_intersect_key($settings, array_flip($this->allowedSettings($type)));
    }

    public function validatesAnswers(string $type): bool
    {
        return (bool) $this->definition($type)['answer'];
    }

    public function normalize(FormComponent $component, mixed $value): mixed
    {
        return match ($component->type) {
            'short_text', 'long_text', 'date', 'time', 'single_choice', 'dropdown' => is_string($value) ? trim($value) : $value,
            'number', 'rating_scale', 'linear_scale' => is_numeric($value) ? (float) $value : $value,
            'yes_no' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'consent_checkbox' => $component->options()->exists() ? $this->normalizeChoices($value) : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
            'multiple_choice' => $this->normalizeChoices($value),
            default => $value,
        };
    }

    public function validateAnswer(FormComponent $component, mixed $value, bool $enforceRequired = true): mixed
    {
        if (!$this->validatesAnswers($component->type)) {
            throw ValidationException::withMessages(['answers' => __('messages.component_not_answerable')]);
        }

        $value = $this->normalize($component, $value);
        $settings = $component->settings ?? [];
        $rules = ['nullable'];

        if ($enforceRequired && $component->is_required) {
            $rules = ['required'];
        }

        $rules[] = match ($component->type) {
            'short_text', 'long_text' => 'string',
            'number' => 'numeric',
            'date' => 'date_format:Y-m-d',
            'time' => 'date_format:H:i',
            'yes_no' => 'boolean',
            'consent_checkbox' => $component->options()->exists() ? 'array' : 'boolean',
            'multiple_choice' => 'array',
            'rating_scale', 'linear_scale' => 'numeric',
            default => 'string',
        };

        if (in_array($component->type, ['short_text', 'long_text'], true)) {
            if (isset($settings['min_length'])) $rules[] = 'min:'.(int) $settings['min_length'];
            if (isset($settings['max_length'])) $rules[] = 'max:'.(int) $settings['max_length'];
        }
        if (in_array($component->type, ['number', 'rating_scale', 'linear_scale'], true)) {
            if (isset($settings['minimum'])) $rules[] = 'min:'.(float) $settings['minimum'];
            if (isset($settings['maximum'])) $rules[] = 'max:'.(float) $settings['maximum'];
        }

        Validator::make(['value' => $value], ['value' => $rules])->validate();

        if (in_array($component->type, ['single_choice', 'dropdown'], true)) {
            $allowed = $component->options->pluck('value')->map(fn ($item) => (string) $item)->all();
            if ($value !== null && $value !== '' && !in_array((string) $value, $allowed, true)) {
                throw ValidationException::withMessages(['value' => __('messages.invalid_option')]);
            }
        }
        if ($component->type === 'multiple_choice') {
            $allowed = $component->options->pluck('value')->map(fn ($item) => (string) $item)->all();
            if (array_diff($value, $allowed)) {
                throw ValidationException::withMessages(['value' => __('messages.invalid_option')]);
            }
        }
        if ($component->type === 'consent_checkbox' && $component->options()->exists()) {
            $allowed = $component->options->pluck('value')->map(fn ($item) => (string) $item)->all();
            if (array_diff((array) $value, $allowed)) {
                throw ValidationException::withMessages(['value' => __('messages.invalid_option')]);
            }
        }

        return $value;
    }

    public function formatForExport(FormComponent $component, mixed $value): string
    {
        if (in_array($component->type, ['single_choice', 'multiple_choice', 'dropdown'], true)) {
            return $component->localizedAnswerValue($value);
        }
        if (is_array($value)) return implode('; ', $value);
        if (is_bool($value)) return $value ? __('messages.yes') : __('messages.no');
        return (string) ($value ?? '');
    }

    private function answerSchema(string $type): string
    {
        return match ($type) {
            'number', 'rating_scale', 'linear_scale' => 'number|null',
            'yes_no' => 'boolean|null',
            'consent_checkbox' => 'boolean|null|string[]',
            'multiple_choice' => 'string[]',
            'form_title', 'heading', 'explanatory_text', 'image', 'file_attachment' => 'none',
            default => 'string|null',
        };
    }

    private function normalizeChoices(mixed $value): array
    {
        $items = is_array($value) ? $value : (($value === null || $value === '') ? [] : [$value]);
        return array_values(array_unique(array_filter(array_map(fn ($item) => trim((string) $item), $items), fn ($item) => $item !== '')));
    }
}
