<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncClientesJob;
use App\Models\Cliente;
use App\Models\ClienteAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            'filters' => ['search' => $search],
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

    public function edit(Cliente $cliente): Response
    {
        $this->authorize('clientes.edit');

        return inertia('Admin/Clientes/Edit', [
            'cliente' => $cliente->load('attachments'),
        ]);
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('clientes.edit');

        $data = $request->validate([
            'fecha_vencimiento' => ['nullable', 'date'],
            'mail_nuevo' => ['nullable', 'email', 'max:255'],
            'categoria' => ['nullable', 'string', 'max:255'],
        ]);

        $cliente->update($data);

        return redirect()->route('clientes.edit', $cliente)
            ->with('success', 'Cliente actualizado correctamente.');
    }

    public function uploadArchivo(Request $request, Cliente $cliente): RedirectResponse
    {
        $this->authorize('clientes.edit');

        $data = $request->validate([
            'archivos' => ['required', 'array'],
            'archivos.*' => ['file', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        foreach ($data['archivos'] as $file) {
            $cliente->guardarAdjunto($file);
        }

        return redirect()->route('clientes.edit', $cliente)
            ->with('success', 'Archivos subidos correctamente.');
    }

    public function downloadArchivo(Cliente $cliente, ClienteAttachment $attachment): StreamedResponse
    {
        $this->authorize('clientes.view');

        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    public function destroyArchivo(Cliente $cliente, ClienteAttachment $attachment): RedirectResponse
    {
        $this->authorize('clientes.edit');

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();

        return redirect()->route('clientes.edit', $cliente)
            ->with('success', 'Archivo eliminado correctamente.');
    }
}
