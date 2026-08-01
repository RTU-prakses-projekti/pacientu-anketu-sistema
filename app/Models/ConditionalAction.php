<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPublishedVersion;
use Illuminate\Database\Eloquent\Model;

class ConditionalAction extends Model
{
    use ProtectsPublishedVersion;
    protected $guarded = [];
    public function rule() { return $this->belongsTo(ConditionalRule::class, 'conditional_rule_id'); }
    public function immutableVersion() { return $this->rule?->formVersion; }
}
