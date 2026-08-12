<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        /*
         * 419 = el token CSRF de la página no matchea el de la sesión. Pasa
         * cuando alguien deja un formulario abierto más que SESSION_LIFETIME:
         * arranca una sesión nueva con un token nuevo y el submit no valida.
         * Ojo: el "recordarme" no evita esto, porque VerifyCsrfToken corre
         * antes de que el guard llegue a re-autenticar por cookie.
         *
         * Sin este handler, Inertia muestra el "Page Expired" crudo.
         */
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response->getStatusCode() !== 419) {
                return $response;
            }

            // El portal es público y no tiene login: mandarlo a /login sería un
            // callejón sin salida para un cliente externo.
            if ($request->routeIs('observaciones.public.*')) {
                return back()->with('error', 'El formulario estuvo abierto demasiado tiempo y expiró. Revisá los datos y volvé a enviarlo.');
            }

            // guest() guarda a dónde iba para volver ahí después del login.
            return redirect()->guest(route('login'))
                ->with('error', 'Tu sesión expiró por inactividad. Volvé a entrar.');
        });
    })->create();
