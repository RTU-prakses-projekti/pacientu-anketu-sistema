<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePublicationRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('publish', $this->route('form')) ?? false; }
    public function rules(): array
    {
        return [
            'form_version_id' => ['required', 'integer', 'exists:form_versions,id'], 'name' => ['required', 'string', 'max:255'],
            'access_mode' => ['required', Rule::in(['authenticated', 'public', 'access_code', 'invitation'])], 'access_code' => [Rule::requiredIf(fn () => $this->input('access_mode') === 'access_code'), 'nullable', 'string', 'min:6', 'max:100'],
            'opens_at' => ['nullable', 'date'], 'closes_at' => ['nullable', 'date', 'after:opens_at'], 'attempt_limit' => ['required', 'integer', 'min:1', 'max:100'],
            'timer_enabled' => ['sometimes', 'boolean'], 'duration_minutes' => [Rule::requiredIf(fn () => $this->boolean('timer_enabled')), 'nullable', 'integer', 'min:1', 'max:1440'],
            'result_visibility' => ['required', Rule::in(['none', 'completion', 'score'])], 'correct_answers_visible' => ['sometimes', 'boolean'],
            'anonymous_allowed' => ['sometimes', 'boolean'], 'identified_required' => ['sometimes', 'boolean'], 'consent_required' => ['sometimes', 'boolean'],
            'autosave_enabled' => ['sometimes', 'boolean'], 'resume_enabled' => ['sometimes', 'boolean'], 'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('anonymous_allowed') && $this->boolean('identified_required')) $validator->errors()->add('anonymous_allowed', __('messages.contradictory_identity_settings'));
            if ($this->input('status') === 'active' && $this->route('form')?->status === 'archived') $validator->errors()->add('status', __('messages.archived_form_cannot_publish'));
        });
    }
}
