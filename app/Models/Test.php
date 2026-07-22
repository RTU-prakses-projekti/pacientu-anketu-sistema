<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Test extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'duration_minutes',
        'is_active',
        'available_from',
        'available_until'
    ];

    protected $casts = [
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'is_active' => 'boolean'
    ];

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function isAvailable()
    {
        if (!$this->is_active) return false;
        
        $now = now();
        if ($this->available_from && $now < $this->available_from) return false;
        if ($this->available_until && $now > $this->available_until) return false;
        
        return true;
    }
}