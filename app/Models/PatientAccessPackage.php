<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PatientAccessPackage extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash'];
    protected $casts = ['expires_at' => 'datetime', 'revoked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(function (self $package): void {
            $package->public_id ??= (string) Str::uuid();
        });
    }

    public function patientCase() { return $this->belongsTo(PatientCase::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function assignments() { return $this->hasMany(PatientFormAssignment::class); }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function isUsable(): bool { return !$this->revoked_at && $this->expires_at->isFuture(); }
}
