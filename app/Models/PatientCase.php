<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

class PatientCase extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (self $patientCase): void {
            $patientCase->public_id ??= (string) Str::uuid();
            $patientCase->patient_code ??= self::generatePatientCode();
        });

        static::saving(function (self $patientCase): void {
            if ($patientCase->slot_number < 1 || $patientCase->slot_number > 200) {
                throw new InvalidArgumentException('Patient slot number must be between 1 and 200.');
            }
        });
    }

    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function doctor() { return $this->belongsTo(User::class, 'doctor_id'); }
    public function assignments() { return $this->hasMany(PatientFormAssignment::class)->orderBy('display_order')->orderBy('id'); }
    public function accessPackages() { return $this->hasMany(PatientAccessPackage::class); }
    public function getRouteKeyName(): string { return 'public_id'; }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where('doctor_id', $user->id);
    }

    private static function generatePatientCode(): string
    {
        do {
            $code = 'PAT-'.Str::upper(Str::random(12));
        } while (self::where('patient_code', $code)->exists());

        return $code;
    }
}
