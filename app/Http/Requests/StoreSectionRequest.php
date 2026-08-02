<?php

namespace App\Http\Requests;

use App\Domain\Forms\LocalizedContentRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreSectionRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()?->can('update', $this->route('form')) ?? false; }
    public function rules(): array { return LocalizedContentRules::for('translations', ['title' => ['string', 'max:255'], 'description' => ['string', 'max:5000']], ['title']); }
}
