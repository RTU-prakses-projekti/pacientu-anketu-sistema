<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use Illuminate\Database\Eloquent\Model;

class FormComponent extends Model
{
    use ProtectsPublishedVersion;
    protected $guarded = [];
    protected $casts = ['is_required' => 'boolean', 'visible' => 'boolean', 'manual_grading' => 'boolean', 'settings' => 'array', 'translations' => 'array', 'max_points' => 'decimal:2'];
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function section() { return $this->belongsTo(FormSection::class, 'form_section_id'); }
    public function options() { return $this->hasMany(ComponentOption::class)->orderBy('display_order'); }
    public function validationRules() { return $this->hasMany(ValidationRule::class)->orderBy('display_order'); }
    public function scoringRule() { return $this->hasOne(ScoringRule::class); }
    public function immutableVersion() { return $this->formVersion; }
}
