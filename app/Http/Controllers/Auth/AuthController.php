<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function showLogin()
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas.']);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('home'));
    }

    public function showRegister()
    {
        return Inertia::render('Auth/Register');
    }

    /** Alta self-service de una ferretería nueva (período de prueba) */
    public function register(Request $request, TenantProvisioner $provisioner)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:150'],
            'cuit' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = $provisioner->provision(
            ['name' => $data['company_name'], 'cuit' => $data['cuit'] ?? null, 'phone' => $data['phone'] ?? null, 'email' => $data['email']],
            ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', '¡Bienvenido! Tu ferretería está lista. Tenés 15 días de prueba.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
