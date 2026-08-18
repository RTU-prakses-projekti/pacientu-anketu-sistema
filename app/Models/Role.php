<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const SYSTEM_NAMES = [
        'platform_admin', 'organisation_admin', 'form_creator', 'doctor', 'reviewer', 'respondent',
    ];

    protected $guarded = [];
    protected $casts = ['is_system' => 'boolean'];
    public function permissions() { return $this->belongsToMany(Permission::class, 'role_permissions'); }
}
