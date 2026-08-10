<?php

namespace App\Http\Controllers;

use App\Notifications\ObservacionExternaRecibidaNotification;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    /**
     * Vacía el bloque de alertas de vencimiento/escalamiento de la campana.
     *
     * Excluye los avisos de reclamo externo a propósito: esos tienen su propia
     * sección en la campana ("Sin clasificar"), que no se vacía a mano — se
     * limpia sola cuando alguien clasifica el caso.
     */
    public function marcarLeidas(Request $request)
    {
        $request->user()->unreadNotifications()
            ->where('type', '!=', ObservacionExternaRecibidaNotification::class)
            ->update(['read_at' => now()]);

        return back();
    }
}
