<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedContent;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class FormVersion extends Model
{
    use HasLocalizedContent;

    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'translations' => 'array', 'published_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            if (in_array($version->getOriginal('status'), ['published', 'archived'], true)) {
                throw new LogicException('Published form versions are immutable.');
            }
        });
        static::deleting(function (self $version): void {
            if ($version->status !== 'draft') {
                throw new LogicException('Published form versions cannot be deleted.');
            }
        });
    }

    public function form() { return $this->belongsTo(Form::class); }
    public function sections() { return $this->hasMany(FormSection::class)->orderBy('display_order'); }
    public function components() { return $this->hasMany(FormComponent::class)->orderBy('display_order'); }
    public function conditionalRules() { return $this->hasMany(ConditionalRule::class); }
    public function attachments() { return $this->morphMany(Attachment::class, 'attachable'); }
    public function localizedTitle(?string $locale = null): ?string { return $this->localized('title', $this->title, $locale); }
    public function localizedDescription(?string $locale = null): ?string { return $this->localized('description', $this->description, $locale); }
    public function localizedCompletionText(?string $locale = null): ?string { return $this->localized('completion_text', data_get($this->settings, 'completion_text'), $locale); }
    public function localizedResultText(?string $locale = null): ?string { return $this->localized('result_text', data_get($this->settings, 'result_text'), $locale); }
    public function usesContentFallback(?string $locale = null): bool { return $this->usesAnyLocalizedFallback(['title'=>$this->title,'description'=>$this->description,'completion_text'=>data_get($this->settings,'completion_text'),'result_text'=>data_get($this->settings,'result_text')],$locale); }
}
