<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Publication extends Model
{
    protected $guarded = [];
    protected $hidden = ['access_code_hash'];
    protected $casts = ['opens_at' => 'datetime', 'closes_at' => 'datetime', 'timer_enabled' => 'boolean', 'correct_answers_visible' => 'boolean', 'anonymous_allowed' => 'boolean', 'identified_required' => 'boolean', 'consent_required' => 'boolean', 'autosave_enabled' => 'boolean', 'resume_enabled' => 'boolean'];
    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function form() { return $this->belongsTo(Form::class); }
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function submissions() { return $this->hasMany(FormSubmission::class); }
    public function invitations() { return $this->hasMany(Invitation::class); }
    public function getRouteKeyName(): string { return 'public_key'; }
    public function isOpen(): bool { $now = now(); return $this->status === 'active' && (!$this->opens_at || $now->gte($this->opens_at)) && (!$this->closes_at || $now->lte($this->closes_at)); }
}
