<?php

namespace App\Http\Controllers;

use App\Domain\Audit\AuditService;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(LoginRequest $request, AuditService $audit)
    {
        $credentials = $request->validated();

        if (Auth::attempt($credentials) && Auth::user()->is_active) {
            $request->session()->regenerate();
            
            $audit->record('security.login', Auth::user());
            return redirect()->intended(route('dashboard'));
        }

        if (Auth::check()) Auth::logout();

        return back()->withErrors([
            'email' => 'Invalid credentials.'
        ]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
