<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    /**
     * Dos cubetas separadas, y ambas cuentan **solo los intentos fallidos**:
     *
     * - `mail|ip` frena el ataque dirigido a una cuenta puntual.
     * - `ip` frena el "password spraying" (una contraseña común probada contra
     *   muchos mails distintos), que la cubeta anterior no ve porque cada mail
     *   estrena su propio contador.
     *
     * No se usa el middleware `throttle` de la ruta a propósito: ese cuenta
     * *todos* los requests, y los 30 usuarios internos salen a internet por una
     * sola IP corporativa — un lunes a las 8:30 se bloquearían entre ellos.
     * Contando solo fallas, un login exitoso no gasta presupuesto de nadie.
     */
    private const MAX_POR_CUENTA = 5;

    private const MAX_POR_IP = 20;

    public function create()
    {
        return inertia('Auth/Login');
    }

    public function store(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $this->asegurarQueNoEstaBloqueado($request);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($this->claveCuenta($request));
            RateLimiter::hit($this->claveIp($request));

            return back()->withErrors(['email' => 'Las credenciales no son correctas.']);
        }

        RateLimiter::clear($this->claveCuenta($request));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * @throws ValidationException si alguna de las dos cubetas se pasó.
     */
    private function asegurarQueNoEstaBloqueado(Request $request): void
    {
        foreach ([
            [$this->claveCuenta($request), self::MAX_POR_CUENTA],
            [$this->claveIp($request), self::MAX_POR_IP],
        ] as [$clave, $maximo]) {
            if (! RateLimiter::tooManyAttempts($clave, $maximo)) {
                continue;
            }

            event(new Lockout($request));

            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', [
                    'seconds' => RateLimiter::availableIn($clave),
                ]),
            ]);
        }
    }

    private function claveCuenta(Request $request): string
    {
        return Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip());
    }

    private function claveIp(Request $request): string
    {
        return 'login-ip|'.$request->ip();
    }
}
