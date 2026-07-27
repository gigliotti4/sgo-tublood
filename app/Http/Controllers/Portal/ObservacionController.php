<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\ObservationProduct;
use App\Models\Sector;
use App\Models\User;
use App\Notifications\ObservacionExternaRecibidaNotification;
use App\Notifications\ObservacionRecibidaClienteNotification;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

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
            'presentaciones' => ObservationProduct::PRESENTACIONES,
            'tipoOptions' => [
                ['value' => 'falla_producto', 'label' => 'Falla de Producto'],
                ['value' => 'disconformidad_servicio', 'label' => 'Disconformidad de Servicio'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        // El formulario oculta el bloque de productos cuando el tipo no los
        // lleva, pero la fila vacía viaja igual en el post. Se descarta acá y no
        // con reglas condicionales: si no, `productos.*` la rechaza campo por
        // campo y esos errores caen en inputs que no están en pantalla — el
        // cliente ve que "no pasa nada" al enviar y el reclamo se pierde.
        if (! TaxonomiaIncidencias::llevaProductos($request->input('tipo'))) {
            $request->merge(['productos' => []]);
        }

        $data = $request->validate([
            'tipo' => ['required', 'in:falla_producto,disconformidad_servicio'],

            'contacto_nombre' => ['required', 'string', 'max:255'],
            'contacto_email' => ['required', 'email', 'max:255'],
            'contacto_numero_cliente' => ['nullable', 'string', 'max:255'],
            'contacto_telefono' => ['nullable', 'string', 'max:255'],

            'titulo' => ['required', 'string', 'max:255'],
            'descripcion' => ['required', 'string'],

            'institucion' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'provincia' => ['required_if:tipo,falla_producto', 'nullable', 'string', 'max:255'],
            'equipamiento' => ['nullable', 'string', 'max:255'],
            'ejecutivo_cuenta' => ['nullable', 'string', 'max:255'],

            'productos' => ['required_if:tipo,falla_producto', 'array'],
            'productos.*.producto' => ['required', 'string', 'max:255'],
            'productos.*.codigo' => ['required', 'string', 'max:255'],
            'productos.*.cantidad_afectada' => ['required', 'integer', 'min:1'],
            'productos.*.tipo_presentacion' => ['required', 'in:'.implode(',', array_keys(ObservationProduct::PRESENTACIONES))],
            'productos.*.lote' => ['required', 'string', 'max:255'],
            'productos.*.fecha_vencimiento' => ['required', 'date'],
            'productos.*.numero_remito' => ['nullable', 'string', 'max:255'],
            'productos.*.tipo_comprobante' => ['nullable', 'in:factura,remito'],

            'attachments' => ['array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,pdf', 'max:3072'],
        ]);

        $observacion = DB::transaction(function () use ($data, $request) {
            $anio = (int) now()->format('Y');
            $numero = Observacion::generarNumero($anio);

            $clienteId = null;
            if (! empty($data['contacto_numero_cliente'])) {
                $clienteId = Cliente::where('numero', trim($data['contacto_numero_cliente']))->value('id');
            }

            // El cliente elige el tipo, no el sector: el sector sale de la
            // taxonomía, que ya declara los dos tipos del portal bajo Garantía
            // de Calidad. Si faltara el registro en `sectors`, queda en null y
            // el reclamo se guarda igual — nunca se pierde por esto.
            $sectorId = Sector::where('slug', TaxonomiaIncidencias::sectorDeTipo($data['tipo']))->value('id');

            $observacion = Observacion::create([
                ...Arr::except($data, ['productos', 'attachments']),
                'numero' => $numero,
                'anio' => $anio,
                'estado' => 'pendiente_clasificacion',
                'origen' => 'externa',
                'cliente_id' => $clienteId,
                'sector_id' => $sectorId,
            ]);

            foreach ($data['productos'] ?? [] as $producto) {
                $observacion->productos()->create($producto);
            }

            foreach ($request->file('attachments', []) as $file) {
                $observacion->guardarAdjunto($file);
            }

            return $observacion;
        });

        $this->avisar($observacion);

        return redirect()->route('observaciones.public.confirmacion')
            ->with('numero', $observacion->numero);
    }

    /**
     * Avisos del alta externa, fuera de la transacción: acuse de recibo al
     * cliente y aviso al equipo que lo va a clasificar.
     *
     * Los destinatarios internos son la **unión** de los usuarios del sector de
     * la observación (Garantía de Calidad, siempre en el alta externa) y los que
     * tengan el rol `garantia_calidad`. Se avisa a los dos grupos y no al sector
     * con el rol de fallback porque este mismo aviso alimenta el modal de
     * "reclamos nuevos" del panel, que es para todo el equipo de Calidad: quien
     * tiene el rol pero está cargado en otro sector también lo tiene que ver.
     *
     * Todo va envuelto en un try/catch a propósito: para cuando esto corre el
     * reclamo ya está guardado, así que un problema al avisar no puede
     * devolverle un error al cliente y hacerle creer que no se cargó (y que lo
     * mande de nuevo). Se registra y se sigue.
     */
    private function avisar(Observacion $observacion): void
    {
        try {
            Notification::route('mail', $observacion->contacto_email)
                ->notify(new ObservacionRecibidaClienteNotification($observacion));

            $calidad = User::query()
                ->where(fn ($q) => $q
                    ->when(
                        $observacion->sector_id,
                        fn ($q, $sectorId) => $q->orWhere('sector_id', $sectorId)
                    )
                    // Con `User::role(...)` no alcanza: ese scope tira RoleDoesNotExist
                    // si el rol no está creado, y eso sería un 500 en un endpoint público.
                    ->orWhereHas('roles', fn ($q) => $q->where('name', Sector::GARANTIA_CALIDAD)))
                ->get();

            if ($calidad->isNotEmpty()) {
                Notification::send($calidad, new ObservacionExternaRecibidaNotification($observacion));
            }
        } catch (Throwable $e) {
            Log::error("No se pudieron enviar los avisos de la observación {$observacion->numero}: {$e->getMessage()}");
        }
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
