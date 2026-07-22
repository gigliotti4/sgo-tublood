<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    /** Vacía la campana: las alertas ya vistas no vuelven a mostrarse. */
    public function marcarLeidas(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
