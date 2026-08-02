<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsentRecord extends Model
{
    protected $guarded = [];
    protected $casts = ['content_locale' => 'string', 'recorded_at' => 'datetime', 'withdrawn_at' => 'datetime'];
}
