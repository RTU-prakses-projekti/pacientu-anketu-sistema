<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use Illuminate\Database\Eloquent\Model;

class ValidationRule extends Model
{
    use ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['parameters' => 'array', 'message_translations' => 'array'];
    public function component() { return $this->belongsTo(FormComponent::class, 'form_component_id'); }
    public function immutableVersion() { return $this->component?->formVersion; }
}
