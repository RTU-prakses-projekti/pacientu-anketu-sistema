<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentRecord extends Model
{
    protected $guarded = [];
    protected $casts = ['recorded_at' => 'datetime', 'withdrawn_at' => 'datetime'];
}
