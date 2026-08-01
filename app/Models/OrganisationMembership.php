<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrganisationMembership extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
    public function organisation() { return $this->belongsTo(Organisation::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function roles() { return $this->belongsToMany(Role::class, 'membership_roles'); }
}
