<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AccountPasswordController extends Controller
{
    public function edit(Request $request)
    {
        abort_unless($request->user()->must_change_password, 404);

        return view('auth.password-change');
    }

    public function update(Request $request, AuditService $audit)
    {
        abort_unless($request->user()->must_change_password, 404);
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', 'different:current_password', Password::min(12)->letters()->numbers()],
        ]);

        DB::transaction(function () use ($request, $data, $audit): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($user->must_change_password, 404);
            $user->update([
                'password' => Hash::make($data['password']),
                'must_change_password' => false,
            ]);
            $audit->record('user.password_changed', $user);
        });

        $request->user()->refresh();
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', __('messages.password_changed'));
    }
}
