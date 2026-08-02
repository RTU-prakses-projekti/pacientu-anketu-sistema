<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use App\Models\Concerns\HasLocalizedContent;
use Illuminate\Database\Eloquent\Model;

class ComponentOption extends Model
{
    use HasLocalizedContent, ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['translations' => 'array'];
    public function component() { return $this->belongsTo(FormComponent::class, 'form_component_id'); }
    public function immutableVersion() { return $this->component?->formVersion; }
    public function localizedLabel(?string $locale = null): ?string { return $this->localized('label', $this->label, $locale); }
}
