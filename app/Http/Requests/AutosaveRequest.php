<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AutosaveRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['expected_revision' => ['required', 'integer', 'min:0'], 'client_mutation_id' => ['required', 'uuid'], 'answers' => ['required', 'array', 'max:100']]; }
}
