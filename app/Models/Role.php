<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public const SYSTEM_NAMES = [
        'platform_admin', 'administrator', 'organisation_admin', 'form_creator', 'doctor',
    ];

    protected $guarded = [];
    protected $casts = ['is_system' => 'boolean'];
    public function permissions() { return $this->belongsToMany(Permission::class, 'role_permissions'); }

    public function label(): string
    {
        return match ($this->name) {
            'administrator' => __('messages.role_administrator'),
            'organisation_admin' => __('messages.role_organisation_admin'),
            'form_creator' => __('messages.role_form_creator'),
            'doctor' => __('messages.role_doctor'),
            default => $this->display_name,
        };
    }
}
