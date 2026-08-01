<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name=} {--email=}';
    protected $description = 'One-time bootstrap of the first platform administrator';

    public function handle(): int
    {
        $lock = Cache::lock('app:create-admin:first-platform-admin', 60);
        if (!$lock->get()) {
            $this->error('Another first-administrator bootstrap attempt is already in progress.');
            return self::FAILURE;
        }

        try {
            return DB::transaction(function (): int {
                // Every bootstrap attempt locks the same role row. This is the
                // database-level serialization boundary in addition to the
                // cross-process cache lock above.
                $role = Role::where('name', 'platform_admin')->lockForUpdate()->firstOrFail();
                if (DB::table('user_roles')->where('role_id', $role->id)->exists()) {
                    $this->error('A platform administrator already exists. Additional administrators must be managed through the authorised administration interface.');
                    return self::FAILURE;
                }

                $name = trim((string) ($this->option('name') ?: $this->ask('Name')));
                $email = Str::lower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
                if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $this->error('A valid name and email are required.');
                    return self::FAILURE;
                }
                if (User::where('email', $email)->exists()) {
                    $this->error('That email already belongs to an existing account. Initial bootstrap requires a new dedicated administrator account.');
                    return self::FAILURE;
                }

                $password = $this->secret('Password (minimum 12 characters)');
                $confirmation = $this->secret('Confirm password');
                if (strlen((string) $password) < 12 || !preg_match('/[A-Za-z]/', (string) $password) || !preg_match('/[0-9]/', (string) $password) || $password !== $confirmation) {
                    $this->error('A matching password of at least 12 characters containing letters and numbers is required.');
                    return self::FAILURE;
                }

                $user = User::create(['name'=>$name,'email'=>$email,'password'=>Hash::make($password),'is_active'=>true,'locale'=>'lv']);
                $user->globalRoles()->attach($role->id);
                $this->info('First platform administrator created. Future administrators must be managed through the authorised administration interface.');
                return self::SUCCESS;
            }, 3);
        } finally {
            $lock->release();
        }
    }
}
