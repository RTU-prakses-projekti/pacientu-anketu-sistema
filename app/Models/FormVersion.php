<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FormVersion extends Model
{
    protected $guarded = [];
    protected $casts = ['settings' => 'array', 'published_at' => 'datetime'];

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
}
