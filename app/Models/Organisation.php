<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organisation extends Model
{
    use SoftDeletes;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean', 'settings' => 'array'];

    public function memberships() { return $this->hasMany(OrganisationMembership::class); }
    public function forms() { return $this->hasMany(Form::class); }
}
