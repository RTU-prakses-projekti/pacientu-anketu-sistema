<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnswerScore extends Model
{
    protected $guarded = [];
    protected $casts = ['automatic_points' => 'decimal:2', 'manual_points' => 'decimal:2', 'final_points' => 'decimal:2', 'graded_at' => 'datetime'];
    public function answer() { return $this->belongsTo(SubmissionAnswer::class, 'submission_answer_id'); }
}
