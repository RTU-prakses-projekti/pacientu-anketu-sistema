<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use Illuminate\Database\Eloquent\Model;

class ConditionalRule extends Model
{
    use ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['comparison_value' => 'array'];
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function sourceComponent() { return $this->belongsTo(FormComponent::class, 'source_component_id'); }
    public function actions() { return $this->hasMany(ConditionalAction::class); }
    public function immutableVersion() { return $this->formVersion; }
}
