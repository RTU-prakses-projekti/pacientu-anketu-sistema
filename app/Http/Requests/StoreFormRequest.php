<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('create', [\App\Models\Form::class, (int) $this->input('organisation_id')]) ?? false; }
    public function rules(): array { return ['organisation_id' => ['required', 'integer', 'exists:organisations,id'], 'name' => ['required', 'string', 'max:255'], 'preset' => ['required', Rule::in(['blank', 'test', 'patient_questionnaire'])]]; }
}
