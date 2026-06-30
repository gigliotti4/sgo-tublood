<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncClientesJob;
use App\Models\Cliente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('clientes.view');

        $search = $request->string('search')->trim()->value();

        $clientes = Cliente::query()
            ->when($search, function ($q) use ($search) {
                $q->where('razon_social', 'like', "%{$search}%")
                  ->orWhere('cuit', 'like', "%{$search}%")
                  ->orWhere('numero', 'like', "%{$search}%")
                  ->orWhere('mail', 'like', "%{$search}%");
            })
            ->orderBy('razon_social')
            ->paginate(50)
            ->withQueryString();

        $lastSync = Cliente::max('synced_at');

        return inertia('Admin/Clientes/Index', [
            'clientes' => $clientes,
            'filters'  => ['search' => $search],
            'lastSync' => $lastSync,
        ]);
    }

    public function sync(Request $request): RedirectResponse
    {
        $this->authorize('clientes.sync');

        SyncClientesJob::dispatch();

        return redirect()->route('clientes.index')
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }
}
