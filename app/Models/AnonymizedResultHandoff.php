<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnonymizedResultHandoff extends Model
{
    protected $guarded = [];
    protected $casts = ['handed_off_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(fn (self $handoff) => $handoff->public_id ??= (string) Str::uuid());
    }

    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function assignment() { return $this->belongsTo(PatientFormAssignment::class, 'patient_form_assignment_id'); }
    public function submission() { return $this->belongsTo(FormSubmission::class, 'form_submission_id'); }
    public function recipient() { return $this->belongsTo(User::class, 'recipient_user_id'); }
    public function hander() { return $this->belongsTo(User::class, 'handed_off_by'); }
    public function getRouteKeyName(): string { return 'public_id'; }
}
