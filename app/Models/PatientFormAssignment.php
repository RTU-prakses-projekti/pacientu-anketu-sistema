<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class PatientFormAssignment extends Model
{
    protected $guarded = [];
    protected $casts = ['assigned_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            $assignment->public_id ??= (string) Str::uuid();
            $assignment->assigned_at ??= now();
        });

        static::saving(function (self $assignment): void {
            $patientCase = PatientCase::find($assignment->patient_case_id);
            $publication = Publication::find($assignment->publication_id);
            if (!$patientCase || !$publication || $patientCase->organisation_id !== $publication->organisation_id) {
                throw new LogicException('Patient assignment must stay within one organisation.');
            }
            if ($assignment->invitation_id) {
                $invitation = Invitation::find($assignment->invitation_id);
                if (!$invitation || $invitation->publication_id !== $publication->id) {
                    throw new LogicException('Patient assignment invitation must belong to its publication.');
                }
            }
        });
    }

    public function patientCase() { return $this->belongsTo(PatientCase::class); }
    public function publication() { return $this->belongsTo(Publication::class); }
    public function invitation() { return $this->belongsTo(Invitation::class); }
    public function accessPackage() { return $this->belongsTo(PatientAccessPackage::class, 'patient_access_package_id'); }
    public function submissions() { return $this->hasMany(FormSubmission::class, 'invitation_id', 'invitation_id'); }
    public function completedSubmission() { return $this->hasOne(FormSubmission::class, 'invitation_id', 'invitation_id')->whereNotNull('form_submissions.invitation_id')->whereIn('status', FormSubmission::PATIENT_COMPLETED_STATUSES)->latestOfMany('submitted_at'); }
    public function getRouteKeyName(): string { return 'public_id'; }

    public function status(): string
    {
        if ($this->completedSubmission()->exists()) return 'completed';
        if ($this->submissions()->where('status', 'in_progress')->exists()) return 'in_progress';
        return 'not_started';
    }
}
