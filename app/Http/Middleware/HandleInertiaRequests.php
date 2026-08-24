<?php

namespace App\Http\Middleware;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use App\Support\Configuracion;
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
            // Marca y textos administrables. Se comparte en **todas** las
            // requests, no solo las autenticadas: el login y el portal público
            // son justamente los que más lo necesitan. Va cacheado, así que no
            // agrega una query por request. Ver App\Support\Configuracion.
            'configuracion' => Configuracion::paraCompartir(),
            // Solo son datos publicos del broadcaster. El App ID y el secret
            // permanecen siempre del lado del servidor.
            'broadcasting' => $this->broadcastingConfig(),
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
                // Subconjunto de los de arriba de los que a este usuario le
                // avisaron. Insiste en el modal (el descarte es del lado del
                // cliente, ver AppLayout.vue) hasta que se clasifique el caso.
                'externas' => $this->externasPendientes($user, $sinClasificar),
                // Los otros dos bloques del mismo modal: lo que este usuario
                // tiene en gestión y lo que sigue sin gestionar. Son
                // independientes de `externas` — una persona puede ver los
                // tres, algunos, o ninguno.
                'asignadas' => $this->asignadasAbiertas($user),
                'seguimiento' => $this->enSeguimiento($user),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function broadcastingConfig(): array
    {
        $driver = config('broadcasting.default');
        $connection = config("broadcasting.connections.{$driver}", []);
        $options = $connection['options'] ?? [];

        return [
            'driver' => in_array($driver, ['pusher', 'reverb'], true) ? $driver : 'null',
            'key' => $connection['key'] ?? null,
            'cluster' => $options['cluster'] ?? null,
            'host' => $options['host'] ?? null,
            'port' => isset($options['port']) ? (int) $options['port'] : null,
            'scheme' => $options['scheme'] ?? null,
        ];
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
            ->get(['id', 'numero', 'titulo', 'origen', 'contacto_nombre', 'prioridad', 'created_at']);
    }

    /**
     * De los reclamos sin clasificar, los que a este usuario le avisaron por
     * portal (el resto de los destinatarios de `sinClasificar` puede incluir
     * gente que atiende el tipo pero no recibió el aviso original).
     *
     * Se calcula como subconjunto de $sinClasificar y no con una consulta
     * aparte: así el modal nunca puede ofrecer algo que ya se clasificó, que ya
     * no existe, o que le toca a otra persona del equipo — el recorte por tipo
     * ya viene hecho de arriba, sin repetir el criterio acá.
     *
     * No filtra por `read_at`: el modal insiste hasta que el caso se clasifica
     * de verdad, así que da igual si la notificación quedó marcada leída (el
     * descarte de "ya lo vi" pasó a ser estado del cliente, ver AppLayout.vue).
     */
    private function externasPendientes(?User $user, iterable $sinClasificar): iterable
    {
        $sinClasificar = collect($sinClasificar);

        if ($user === null || $sinClasificar->isEmpty()) {
            return [];
        }

        $idsAvisados = $user->notifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->get(['id', 'data'])
            ->pluck('data.observacion_id');

        return $sinClasificar->whereIn('id', $idsAvisados)->values();
    }

    /**
     * Los casos que este usuario tiene en gestión, para recordárselos al entrar.
     *
     * A diferencia de `externasPendientes()`, acá no hay notificación de por
     * medio: es una consulta viva contra `observations`, así que el
     * recordatorio se apaga solo en cuanto el caso llega a un estado terminal
     * (`cerrada` o `cancelada`, o sea cualquiera fuera de ESTADOS_ABIERTOS) —
     * sin depender de que nadie marque nada. El descarte de "ya lo vi en esta
     * carga de la app" es estado del cliente (ver AppLayout.vue), no de acá.
     */
    private function asignadasAbiertas(?User $user): iterable
    {
        if ($user === null) {
            return [];
        }

        return Observacion::aCargoDe($user)
            ->latest()
            ->limit(20)
            ->get(['id', 'numero', 'titulo', 'estado', 'origen', 'contacto_nombre', 'prioridad', 'created_at']);
    }

    /**
     * Los casos abiertos que este usuario sigue sin gestionar: lo sumaron como
     * "a notificar" para que opine, así que puede comentar en la bitácora pero
     * no reasignar ni reclasificar (ver ObservacionPolicy::comentar()).
     *
     * Hasta ahora solo se enteraban por el mail de
     * ObservacionSeguimientoNotification, que sale **una sola vez** al sumarlos;
     * este bloque se los recuerda mientras el caso siga abierto. Igual que
     * `asignadasAbiertas()`, es una consulta viva sin descarte del lado del
     * servidor.
     */
    private function enSeguimiento(?User $user): iterable
    {
        if ($user === null) {
            return [];
        }

        return Observacion::seguidasPor($user)
            ->latest()
            ->limit(20)
            ->get(['id', 'numero', 'titulo', 'estado', 'origen', 'contacto_nombre', 'prioridad', 'created_at']);
    }
}
