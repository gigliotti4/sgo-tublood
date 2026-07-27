<?php

namespace App\Http\Middleware;

use App\Models\Cliente;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** Bandera de sesión que apaga el aviso de externas nuevas una vez visto. */
    public const EXTERNAS_AVISADAS = 'externas_avisadas';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'roles' => $request->user()->getRoleNames(),
                    'permissions' => $request->user()->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'notificaciones' => [
                'vencimientos' => $request->user()?->can('clientes.view')
                    ? Cliente::query()
                        ->whereNotNull('fecha_vencimiento')
                        ->where('fecha_vencimiento', '<=', now()->addDays(30))
                        ->orderBy('fecha_vencimiento')
                        ->limit(20)
                        ->get(['id', 'numero', 'razon_social', 'fecha_vencimiento'])
                    : [],
                // Alertas de vencimiento/escalamiento de observaciones: las deja
                // el comando `observaciones:alertas` en el canal `database`.
                'alertas' => $request->user()
                    ? $request->user()->unreadNotifications()->limit(20)->get(['id', 'data', 'created_at'])
                    : [],
                'externas' => $this->externasNuevas($request->user()),
            ],
        ]);
    }

    /**
     * Reclamos externos que este usuario de Calidad todavía no vio, para el
     * modal que se abre al entrar al panel.
     *
     * Sale de los mismos avisos no leídos que ya deja el alta del portal, así
     * que no hace falta ni una tabla ni una marca aparte. Se muestra una sola
     * vez por sesión: la bandera la levanta `notificaciones.externas.vistas` al
     * cerrarse el modal y el login la limpia, porque el aviso es para el momento
     * de entrar y no para interrumpir a mitad de una gestión.
     */
    private function externasNuevas(?User $user): iterable
    {
        if (! $user?->esDeCalidad() || session(self::EXTERNAS_AVISADAS)) {
            return [];
        }

        return $user->unreadNotifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->limit(20)
            ->get(['id', 'data', 'created_at']);
    }
}
