<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;

class PlatformAdminStatus extends Command
{
    protected $signature = 'app:platform-admin-status';
    protected $description = 'Check whether the bootstrap platform administrator exists';

    public function handle(): int
    {
        $role = Role::query()
            ->where('name', 'platform_admin')
            ->where('scope', 'global')
            ->first();

        $exists = $role !== null && User::query()
            ->whereHas('globalRoles', fn ($query) => $query->whereKey($role->getKey()))
            ->exists();

        $this->line($exists ? 'EXISTS' : 'MISSING');

        return self::SUCCESS;
    }
}
