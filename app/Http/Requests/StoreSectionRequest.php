<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('update', $this->route('form')) ?? false; }
    public function rules(): array { return ['title' => ['required', 'string', 'max:255']]; }
}
