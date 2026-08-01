<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use Illuminate\Database\Eloquent\Model;

class FormSection extends Model
{
    use ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['visible' => 'boolean', 'translations' => 'array'];
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function components() { return $this->hasMany(FormComponent::class)->orderBy('display_order'); }
    public function immutableVersion() { return $this->formVersion; }
}
