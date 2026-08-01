<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Domain\Audit\AuditService;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

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

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'student_id' => $validated['student_id'],
            'password' => Hash::make($validated['password']),
            'locale' => 'lv',
            'is_active' => true,
        ]);

        Auth::login($user);

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login');
    }
}
