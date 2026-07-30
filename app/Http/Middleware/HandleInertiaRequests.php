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
                    // Para la autorización por sector de ObservacionPolicy en el
                    // cliente (`puedeEditar` en Index.vue): tiene que reflejar la
                    // misma regla que el backend.
                    'sector_id' => $user->sector_id,
                ] : null,
            ],
            'flash' => [
                'success' => session('success'),
                'error' => session('error'),
            ],
            'notificaciones' => [
                // Permiso propio y no `clientes.view`: seguir los vencimientos
                // es tarea de una persona puntual, no de cualquiera que pueda
                // mirar la lista de clientes.
                'vencimientos' => $user?->can('clientes.vencimientos')
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
                // la campana. Recortados al tipo que atiende cada uno.
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
     *
     * Además se recorta por tipo: Calidad de Producto ve las fallas de producto
     * y Calidad de Servicio las disconformidades. Quien es de Garantía de
     * Calidad "a secas" los ve todos — `tiposDeReclamoQueAtiende()` devuelve
     * null para ese caso.
     */
    private function sinClasificar(?User $user): iterable
    {
        if ($user === null) {
            return [];
        }

        $tipos = $user->tiposDeReclamoQueAtiende();

        // Array vacío = no atiende ningún tipo y tampoco es de Calidad.
        if ($tipos !== null && $tipos === []) {
            return [];
        }

        return Observacion::where('estado', 'pendiente_clasificacion')
            ->when($tipos !== null, fn ($q) => $q->whereIn('tipo', $tipos))
            ->latest()
            ->limit(20)
            ->get(['id', 'numero', 'titulo', 'origen', 'contacto_nombre', 'created_at']);
    }

    /**
     * De los reclamos sin clasificar, los que este usuario todavía no vio: el
     * subconjunto que abre el modal (una sola vez, hasta que lo cierre).
     *
     * Se calcula como subconjunto de $sinClasificar y no con una consulta
     * aparte: así el popup nunca puede ofrecer algo que ya se clasificó, que ya
     * no existe, o que le toca a otra persona del equipo — el recorte por tipo
     * ya viene hecho de arriba, sin repetir el criterio acá.
     */
    private function externasNuevas(?User $user, iterable $sinClasificar): iterable
    {
        $sinClasificar = collect($sinClasificar);

        if ($user === null || $sinClasificar->isEmpty()) {
            return [];
        }

        $idsAvisados = $user->unreadNotifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->get(['id', 'data'])
            ->pluck('data.observacion_id');

        return $sinClasificar->whereIn('id', $idsAvisados)->values();
    }
}
