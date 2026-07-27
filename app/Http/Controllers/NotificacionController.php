<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    /** Vacía la campana: las alertas ya vistas no vuelven a mostrarse. */
    public function marcarLeidas(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }

    /**
     * Cierra el modal de reclamos externos nuevos.
     *
     * Marca vistos esos avisos (para que no vuelvan en la próxima sesión) y
     * levanta la bandera que lo apaga por lo que queda de esta. Si el usuario
     * cerró el modal entrando a un reclamo, lo deja en el detalle: así el clic
     * es un solo viaje al servidor y no un "marcar visto" seguido de un salto.
     */
    public function marcarExternasVistas(Request $request)
    {
        $data = $request->validate([
            'observacion_id' => ['nullable', 'integer', 'exists:observations,id'],
        ]);

        $request->user()->unreadNotifications()
            ->where('type', ObservacionExternaRecibidaNotification::class)
            ->update(['read_at' => now()]);

        $request->session()->put(HandleInertiaRequests::EXTERNAS_AVISADAS, true);

        return filled($data['observacion_id'] ?? null)
            ? redirect()->route('observaciones.show', $data['observacion_id'])
            : back();
    }
}
