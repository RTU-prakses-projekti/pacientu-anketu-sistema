<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'test_id',
        'student_id',
        'student_name',
        'started_at',
        'submitted_at',
        'score',
        'total_possible',
        'is_auto_submitted'
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'is_auto_submitted' => 'boolean'
    ];

    public function test()
    {
        return $this->belongsTo(Test::class);
    }

    public function answers()
    {
        return $this->hasMany(Answer::class);
    }

    public function calculateScore()
    {
        $totalPoints = $this->test->questions->sum('points');
        $earnedPoints = $this->answers()->where('is_correct', true)->sum('points');
        
        $this->score = $earnedPoints;
        $this->total_possible = $totalPoints;
        $this->save();
        
        return $earnedPoints;
    }
}