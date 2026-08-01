<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GradeSubmissionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('grade', $this->route('submission')) ?? false; }
    public function rules(): array { return ['scores' => ['required', 'array'], 'scores.*.points' => ['required', 'numeric', 'min:0'], 'scores.*.comment' => ['nullable', 'string', 'max:5000'], 'finalize' => ['sometimes', 'boolean']]; }
}
