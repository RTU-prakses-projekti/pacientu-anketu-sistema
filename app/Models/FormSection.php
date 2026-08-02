<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use App\Models\Concerns\HasLocalizedContent;
use Illuminate\Database\Eloquent\Model;

class FormSection extends Model
{
    use HasLocalizedContent, ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['visible' => 'boolean', 'translations' => 'array'];
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function components() { return $this->hasMany(FormComponent::class)->orderBy('display_order'); }
    public function immutableVersion() { return $this->formVersion; }
    public function localizedTitle(?string $locale = null): ?string { return $this->localized('title', $this->title, $locale); }
    public function localizedDescription(?string $locale = null): ?string { return $this->localized('description', $this->description, $locale); }
    public function usesContentFallback(?string $locale = null): bool { return $this->usesAnyLocalizedFallback(['title'=>$this->title,'description'=>$this->description],$locale); }
}
