<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'locale', 'is_active'])]
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

    public function patientCases()
    {
        return $this->hasMany(PatientCase::class, 'doctor_id');
    }

    public function doctorMemberships()
    {
        return $this->memberships()
            ->where('is_active', true)
            ->whereHas('organisation', fn ($query) => $query->where('is_active', true))
            ->whereHas('roles', fn ($query) => $query->where('roles.name', 'doctor'));
    }

    public function hasDoctorWorkspace(): bool
    {
        return $this->doctorMemberships()->exists();
    }

    public function hasMembershipPermission(int $organisationId, string $permission): bool
    {
        return $this->memberships()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->whereHas('organisation', fn ($query) => $query->where('is_active', true))
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.name', $permission))
            ->exists();
    }

    public function hasDoctorPermission(int $organisationId, string $permission): bool
    {
        if ($this->isBootstrapRoot()) {
            return true;
        }

        return $this->memberships()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->whereHas('organisation', fn ($query) => $query->where('is_active', true))
            ->whereHas('roles', fn ($query) => $query
                ->where('roles.name', 'doctor')
                ->whereHas('permissions', fn ($permissions) => $permissions->where('permissions.name', $permission)))
            ->exists();
    }

    public function isDoctorOnly(): bool
    {
        if ($this->canAdministerSystem() || !$this->doctorMemberships()->exists()) {
            return false;
        }

        $administrativePermissions = [
            'organisation.manage', 'forms.view', 'forms.create', 'forms.update', 'forms.publish',
            'submissions.view', 'exports.create',
            'audit.view', 'users.manage',
        ];

        return !$this->memberships()
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->whereIn('permissions.name', $administrativePermissions))
            ->exists();
    }

    public function isBootstrapRoot(): bool
    {
        return $this->globalRoles()->where('name', 'platform_admin')->exists();
    }

    /** @deprecated Use isBootstrapRoot() for the technical root account. */
    public function isPlatformAdmin(): bool
    {
        return $this->isBootstrapRoot();
    }

    public function isAdministrator(): bool
    {
        return $this->globalRoles()->where('name', 'administrator')->exists();
    }

    public function canAdministerSystem(): bool
    {
        return $this->isBootstrapRoot() || $this->isAdministrator();
    }

    public function hasOrganisationPermission(int $organisationId, string $permission): bool
    {
        if ($this->canAdministerSystem()) {
            return true;
        }

        return $this->memberships()
            ->where('organisation_id', $organisationId)
            ->where('is_active', true)
            ->whereHas('roles.permissions', fn ($query) => $query->where('permissions.name', $permission))
            ->exists();
    }

    public function canViewAnonymizedResults(int $organisationId): bool
    {
        return $this->isBootstrapRoot()
            || $this->hasOrganisationPermission($organisationId, 'anonymized_results.view');
    }

    public function canReceiveAnonymizedResults(): bool
    {
        return $this->isBootstrapRoot()
            || $this->globalRoles()->whereHas('permissions', fn ($permissions) => $permissions->where('permissions.name', 'anonymized_results.view'))->exists()
            || $this->memberships()->where('is_active', true)->whereHas('roles.permissions', fn ($permissions) => $permissions->where('permissions.name', 'anonymized_results.view'))->exists();
    }
}
