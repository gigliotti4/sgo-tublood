<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function solicitar()
    {
        return inertia('Auth/RecuperarPassword');
    }

    public function enviarLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Se ignora a propósito el status que devuelve el broker (mail
        // inexistente, throttled, etc.): decir siempre lo mismo evita que
        // alguien use este formulario para averiguar qué mails existen en el
        // sistema. Mismo criterio que el login, que no distingue "no existe
        // el usuario" de "contraseña incorrecta".
        return back()->with('success', __('passwords.sent'));
    }

    public function formulario(Request $request, string $token)
    {
        return inertia('Auth/RestablecerPassword', [
            'token' => $token,
            'email' => $request->string('email'),
        ]);
    }

    public function restablecer(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => $password,
                    // Un navegador ajeno con "Mantener sesión iniciada" (30
                    // días, ver AppServiceProvider) no puede seguir
                    // autenticado después de un reset.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)]);
        }

        return redirect()->route('login')->with('success', __($status));
    }
}
