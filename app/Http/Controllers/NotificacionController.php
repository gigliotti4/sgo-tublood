<?php

namespace App\Http\Controllers;

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
     * Cierra el modal de reclamos externos nuevos: marca vistos esos avisos,
     * así no vuelven a aparecer hasta que entre uno nuevo.
     *
     * El reclamo no desaparece de la campana con esto: la sección "Sin
     * clasificar" sale de una consulta viva contra `observations`, no de estos
     * avisos, así que sigue ahí hasta que alguien lo clasifique de verdad.
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

        $request->user()->unreadNotifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->update(['read_at' => now()]);

        return filled($data['observacion_id'] ?? null)
            ? redirect()->route('observaciones.show', $data['observacion_id'])
            : back();
    }
}
