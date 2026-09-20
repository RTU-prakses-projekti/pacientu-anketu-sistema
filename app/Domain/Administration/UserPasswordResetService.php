<?php

namespace App\Domain\Administration;

use App\Domain\Audit\AuditService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserPasswordResetService
{
    public function reset(User $target, string $temporaryPassword, ?int $organisationId = null): void
    {
        DB::transaction(function () use ($target, $temporaryPassword, $organisationId): void {
            $user = User::query()->lockForUpdate()->findOrFail($target->id);
            $user->update([
                'password' => Hash::make($temporaryPassword),
                'must_change_password' => true,
            ]);

            app(AuditService::class)->record(
                'user.password_reset',
                $user,
                $organisationId,
                ['must_change_password' => true],
            );
        });
    }
}
