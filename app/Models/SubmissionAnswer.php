<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionAnswer extends Model
{
    protected $guarded = [];
    protected $casts = ['value' => 'array', 'saved_at' => 'datetime'];
    public function submission() { return $this->belongsTo(FormSubmission::class, 'form_submission_id'); }
    public function component() { return $this->belongsTo(FormComponent::class, 'form_component_id'); }
    public function score() { return $this->hasOne(AnswerScore::class); }
}
