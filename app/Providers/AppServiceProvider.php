<?php

namespace App\Providers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Super-admin bypasses all permission checks
        Gate::before(function ($user, $ability) {
            if ($user->hasRole('super-admin')) {
                return true;
            }
        });

        // La cookie de "Mantener sesión iniciada" dura 30 días, no los 400 que
        // trae Laravel de fábrica: es lo que hace que no haya que reloguearse
        // todas las mañanas, pero un navegador olvidado no queda autenticado
        // por más de un año. Se resuelve después del boot para no instanciar el
        // guard antes de que estén todos los providers arriba.
        $this->app->booted(function () {
            Auth::guard('web')->setRememberDuration(60 * 24 * 30);
        });
    }
}
