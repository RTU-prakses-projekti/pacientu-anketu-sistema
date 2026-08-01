<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Form extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = ['translations' => 'array'];
    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function versions() { return $this->hasMany(FormVersion::class); }
    public function publications() { return $this->hasMany(Publication::class); }
    public function draftVersion() { return $this->hasOne(FormVersion::class)->where('status', 'draft')->latestOfMany(); }
}
