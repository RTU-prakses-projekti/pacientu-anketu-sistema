<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FormSubmission extends Model
{
    protected $guarded = [];
    protected $casts = ['started_at' => 'datetime', 'deadline_at' => 'datetime', 'submitted_at' => 'datetime', 'maximum_points' => 'decimal:2', 'automatic_points' => 'decimal:2', 'manual_points' => 'decimal:2', 'final_points' => 'decimal:2', 'percentage' => 'decimal:2'];
    public function publication() { return $this->belongsTo(Publication::class); }
    public function formVersion() { return $this->belongsTo(FormVersion::class); }
    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function answers() { return $this->hasMany(SubmissionAnswer::class); }
    public function consentRecords() { return $this->hasMany(ConsentRecord::class); }
    public function getRouteKeyName(): string { return 'public_id'; }
}
