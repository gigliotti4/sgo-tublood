<?php

namespace App\Http\Middleware;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

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
            ],
        ]);
    }
}
