<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Observacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ObservacionController extends Controller
{
    private const PROVINCIAS = [
        'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut',
        'Ciudad Autónoma de Buenos Aires', 'Córdoba', 'Corrientes', 'Entre Ríos',
        'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
        'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz',
        'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
    ];

    public function create()
    {
        return inertia('Portal/CargarObservacion', [
            'provincias' => self::PROVINCIAS,
            'tipoOptions' => [
                ['value' => 'falla_producto', 'label' => 'Falla de Producto'],
                ['value' => 'disconformidad_servicio', 'label' => 'Disconformidad de Servicio'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'tipo' => ['required', 'in:falla_producto,disconformidad_servicio'],

            'contacto_nombre' => ['required', 'string', 'max:255'],
            'contacto_email' => ['required', 'email', 'max:255'],
            'contacto_numero_cliente' => ['nullable', 'string', 'max:255'],
            'contacto_telefono' => ['nullable', 'string', 'max:255'],

            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string'],

            'cantidad_afectada' => ['required_if:tipo,falla_producto', 'nullable', 'integer', 'min:1'],
            'lote' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'fecha_vencimiento' => ['required_if:tipo,falla_producto', 'nullable', 'date'],
            'numero_remito' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'tipo_comprobante' => ['required_if:tipo,falla_producto', 'nullable', 'in:factura,remito'],
            'institucion' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'provincia' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'producto' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'equipamiento' => ['nullable', 'string', 'max:255'],
            'ejecutivo_cuenta' => ['nullable', 'string', 'max:255'],

            'attachments' => ['array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:3072'],
        ]);

        $numero = DB::transaction(function () use ($data, $request) {
            $anio = (int) now()->format('Y');
            $numero = Observacion::generarNumero($anio);

            $clienteId = null;
            if (! empty($data['contacto_numero_cliente'])) {
                $clienteId = Cliente::where('numero', trim($data['contacto_numero_cliente']))->value('id');
            }

            $observacion = Observacion::create([
                ...$data,
                'numero' => $numero,
                'anio' => $anio,
                'estado' => 'pendiente_clasificacion',
                'origen' => 'externa',
                'cliente_id' => $clienteId,
            ]);

            // TODO: asignar a sector Garantía de Calidad + notificar (flujo 8.1)
            // pendiente de las tablas sectors/notifications.

            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store('observaciones', 'local');

                $observacion->attachments()->create([
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ]);
            }

            return $numero;
        });

        return redirect()->route('observaciones.public.confirmacion')
            ->with('numero', $numero);
    }

    public function confirmacion(Request $request)
    {
        if (! $request->session()->has('numero')) {
            return redirect()->route('observaciones.public.create');
        }

        return inertia('Portal/ObservacionEnviada', [
            'numero' => $request->session()->get('numero'),
        ]);
    }
}
