<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Observacion;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    /**
     * Vacía el bloque de alertas de vencimiento/escalamiento de la campana.
     *
     * Excluye los avisos de reclamo externo a propósito: esos tienen su propio
     * mecanismo (`marcarExternasVistas`, más abajo) y su propia sección en la
     * campana ("Sin clasificar"), que no se vacía a mano — se limpia sola
     * cuando alguien clasifica el caso.
     */
    public function marcarLeidas(Request $request)
    {
        $request->user()->unreadNotifications()
            ->where('type', '!=', ObservacionExternaRecibidaNotification::class)
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Cierra el modal de avisos, que tiene dos bloques y se apagan distinto:
     *
     * - **Reclamos externos nuevos**: se marcan vistos esos avisos, así no
     *   vuelven a aparecer hasta que entre uno nuevo. El reclamo no desaparece
     *   de la campana con esto: la sección "Sin clasificar" sale de una consulta
     *   viva contra `observations`, así que sigue ahí hasta que alguien lo
     *   clasifique de verdad.
     * - **Casos en gestión y en seguimiento**: se anotan **uno por uno** como
     *   vistos en la sesión (ver HandleInertiaRequests::AVISOS_VISTOS), no en la
     *   base. Guardar los IDs y no un "ya cerré el modal" es lo que hace que un
     *   caso asignado *después* de cerrarlo igual aparezca, sin re-loguearse.
     *
     * Los IDs se recalculan acá con los mismos scopes que usa el middleware, en
     * vez de recibirlos del browser: si dependiera de lo que manda el cliente,
     * un bug en el front que no los enviara dejaría el modal reabriéndose en
     * loop.
     *
     * Si el usuario cerró el modal entrando a un reclamo, lo deja en el
     * detalle: así el clic es un solo viaje al servidor y no un "marcar visto"
     * seguido de un salto.
     */
    public function marcarExternasVistas(Request $request)
    {
        $data = $request->validate([
            'observacion_id' => ['nullable', 'integer', 'exists:observations,id'],
        ]);

        $user = $request->user();

        $user->unreadNotifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->update(['read_at' => now()]);

        $mostradas = Observacion::aCargoDe($user)->pluck('id')
            ->merge(Observacion::seguidasPor($user)->pluck('id'));

        $request->session()->put(
            HandleInertiaRequests::AVISOS_VISTOS,
            collect($request->session()->get(HandleInertiaRequests::AVISOS_VISTOS, []))
                ->merge($mostradas)
                ->unique()
                ->values()
                ->all()
        );

        return filled($data['observacion_id'] ?? null)
            ? redirect()->route('observaciones.show', $data['observacion_id'])
            : back();
    }
}
