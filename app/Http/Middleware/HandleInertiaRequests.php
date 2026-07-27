<?php

namespace App\Http\Middleware;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();
        $sinClasificar = $this->sinClasificar($user);

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'permissions' => $user->getAllPermissions()->pluck('name'),
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'notificaciones' => [
                'vencimientos' => $user?->can('clientes.view')
                    ? Cliente::query()
                        ->whereNotNull('fecha_vencimiento')
                        ->where('fecha_vencimiento', '<=', now()->addDays(30))
                        ->orderBy('fecha_vencimiento')
                        ->limit(20)
                        ->get(['id', 'numero', 'razon_social', 'fecha_vencimiento'])
                    : [],
                // Alertas de vencimiento/escalamiento de observaciones: las deja
                // el comando `observaciones:alertas` en el canal `database`. Los
                // reclamos externos quedan afuera a propósito: tienen su propia
                // sección (`sinClasificar`), y mezclarlos acá los duplicaría.
                'alertas' => $user
                    ? $user->unreadNotifications()
                        ->where('type', '!=', ObservacionExternaRecibidaNotification::class)
                        ->limit(20)
                        ->get(['id', 'data', 'created_at'])
                    : [],
                // Reclamos pendientes de clasificación, para la sección propia de
                // la campana del equipo de Garantía de Calidad.
                'sinClasificar' => $sinClasificar,
                // Subconjunto de los de arriba que dispara el modal una sola vez.
                'externas' => $this->externasNuevas($user, $sinClasificar),
            ],
        ]);
    }

    /**
     * Observaciones pendientes de clasificación, para la campana.
     *
     * Es una consulta viva sobre `observations`, no la tabla de notificaciones:
     * así se autolimpia sola en cuanto alguien clasifica el caso (sea desde acá
     * o desde el modal de edición del listado), y no puede quedar desincronizada
     * ni acumular filas que apunten a una observación ya borrada.
     *
     * Se filtra por estado y no por origen a propósito: hoy solo las externas
     * nacen en `pendiente_clasificacion` (la carga interna nace `clasificada`),
     * pero si alguien devuelve un caso a ese estado, también tiene que aparecer.
     */
    private function sinClasificar(?User $user): iterable
    {
        if (! $user?->esDeCalidad()) {
            return [];
        }

        return Observacion::where('estado', 'pendiente_clasificacion')
            ->latest()
            ->limit(20)
            ->get(['id', 'numero', 'titulo', 'origen', 'contacto_nombre', 'created_at']);
    }

    /**
     * De los reclamos sin clasificar, los que este usuario todavía no vio: el
     * subconjunto que abre el modal (una sola vez, hasta que lo cierre).
     *
     * Se calcula como subconjunto de $sinClasificar y no con una consulta
     * aparte: así el popup nunca puede ofrecer algo que ya se clasificó o que ya
     * no existe, sin necesidad de un filtro extra para eso.
     */
    private function externasNuevas(?User $user, iterable $sinClasificar): iterable
    {
        $sinClasificar = collect($sinClasificar);

        if (! $user?->esDeCalidad() || $sinClasificar->isEmpty()) {
            return [];
        }

        $idsAvisados = $user->unreadNotifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->get(['id', 'data'])
            ->pluck('data.observacion_id');

        return $sinClasificar->whereIn('id', $idsAvisados)->values();
    }
}
