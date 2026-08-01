<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {--name=} {--email=}';
    protected $description = 'Securely create or promote the first platform administrator';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->secret('Password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen((string) $password) < 12 || $password !== $confirmation) {
            $this->error('Valid name/email and matching password of at least 12 characters are required.');
            return self::FAILURE;
        }
        $user = User::firstOrNew(['email' => Str::lower($email)]);
        $user->fill(['name' => $name, 'password' => Hash::make($password), 'is_active' => true, 'locale' => 'lv']);
        $user->save();
        $role = Role::where('name', 'platform_admin')->firstOrFail();
        $user->globalRoles()->syncWithoutDetaching([$role->id]);
        $this->info('Platform administrator created.');
        return self::SUCCESS;
    }
}
