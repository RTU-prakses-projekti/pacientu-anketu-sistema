<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'student_id', 'locale', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function memberships()
    {
        return $this->hasMany(OrganisationMembership::class);
    }

    public function globalRoles()
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function isPlatformAdmin(): bool
    {
        return $this->globalRoles()->where('name', 'platform_admin')->exists();
    }

    public function hasOrganisationPermission(int $organisationId, string $permission): bool
    {
        if ($this->isPlatformAdmin()) {
            return true;
        }

        return $this->memberships()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.name', $permission))
            ->exists();
    }
    public function isAdmin()
{
    return $this->role === 'admin';
}

public function isStudent()
{
    return $this->role === 'student';
}
}
