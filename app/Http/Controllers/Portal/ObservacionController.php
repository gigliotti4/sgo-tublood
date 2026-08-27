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
use App\Support\ReglasObservacion;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
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

            ...ReglasObservacion::productos(),
            ...ReglasObservacion::adjuntos(),
        ], [], ReglasObservacion::atributos());

        $observacion = $this->guardar($data, $request);

        $this->avisar($observacion);

        return redirect()->route('observaciones.public.confirmacion')
            ->with('numero', $observacion->numero);
    }

    /**
     * El alta en sí, con la red por si se cae.
     *
     * Para cuando esto corre el cliente ya completó un formulario largo. Si el
     * guardado falla por algo que no es culpa de lo que cargó (la base, el
     * disco, dos altas peleándose el mismo número), dejar propagar la excepción
     * le muestra una pantalla de error cruda y le borra todo lo que escribió.
     *
     * Por eso se relanza como error de validación: Inertia trata el 422 sin
     * navegar (mantiene `preserveState`), así que el formulario queda intacto
     * —archivos elegidos incluidos— con el mensaje arriba y el cliente puede
     * reintentar sin volver a cargar nada. Un `back()->with('error')` no sirve:
     * es un redirect, remonta el componente y se lleva puesto el formulario.
     */
    private function guardar(array $data, Request $request): Observacion
    {
        try {
            return $this->crear($data, $request);
        } catch (Throwable $e) {
            Log::error('No se pudo guardar el reclamo del portal público', [
                'email' => $data['contacto_email'] ?? null,
                'tipo' => $data['tipo'] ?? null,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw ValidationException::withMessages([
                'guardado' => ReglasObservacion::mensajeDeFalla(),
            ]);
        }
    }

    private function crear(array $data, Request $request): Observacion
    {
        $anio = (int) now()->format('Y');

        return Observacion::altaConNumero($anio, function (string $numero) use ($data, $request, $anio) {
            $clienteId = null;
            if (! empty($data['contacto_numero_cliente'])) {
                $clienteId = Cliente::where('numero', trim($data['contacto_numero_cliente']))->value('id');
            }

            // El mail que carga el propio cliente vale más que el que trae el
            // ERP (que suele estar viejo o vacío). Va a `mail_nuevo` y no a
            // `mail` porque la sincronización pisa `mail` cada 5 minutos.
            if ($clienteId) {
                Cliente::whereKey($clienteId)->update(['mail_nuevo' => $data['contacto_email']]);
            }

            // El cliente elige el tipo, no el sector: el sector sale de la
            // taxonomía, que ya declara los dos tipos del portal bajo Garantía
            // de Calidad. Si faltara el registro en `sectors`, queda en null y
            // el reclamo se guarda igual — nunca se pierde por esto.
            $sectorId = Sector::where('slug', TaxonomiaIncidencias::sectorDeTipo($data['tipo']))->value('id');

            $observacion = new Observacion([
                ...Arr::except($data, ['productos', 'attachments']),
                'numero' => $numero,
                'anio' => $anio,
                'estado' => 'pendiente_clasificacion',
                'origen' => 'externa',
                'cliente_id' => $clienteId,
                'sector_id' => $sectorId,
                'responsable_id' => $this->responsableDelTipo($data['tipo']),
            ]);

            // La persona que queda a cargo se entera por el aviso de reclamo
            // nuevo que manda `avisar()`; el de asignación sería el mismo hecho
            // contado dos veces.
            $observacion->omitirAvisoDeAsignacion = true;
            $observacion->save();

            foreach ($data['productos'] ?? [] as $producto) {
                $observacion->productos()->create($producto);
            }

            foreach ($request->file('attachments', []) as $file) {
                $observacion->guardarAdjunto($file);
            }

            return $observacion;
        });
    }

    /**
     * Avisos del alta externa, fuera de la transacción: acuse de recibo al
     * cliente y aviso a quien lo va a clasificar.
     *
     * El destinatario interno sale del **tipo** de reclamo, no del sector: los
     * dos tipos del portal cuelgan de Garantía de Calidad, así que el sector no
     * alcanza para repartirlos entre las personas del equipo. El mapeo tipo→rol
     * vive en `incidencias.roles_por_tipo`.
     *
     * Si nadie tiene todavía ese rol cargado, cae al criterio viejo (gente del
     * sector de la observación ∪ gente con el rol `garantia_calidad`). El
     * fallback no es opcional: el portal es público y un reclamo no puede
     * quedar sin que se entere nadie porque falte configurar un rol.
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

            $destinatarios = $this->destinatariosDelTipo($observacion->tipo);

            if ($destinatarios->isEmpty()) {
                $destinatarios = $this->destinatariosDeCalidad($observacion);
            }

            if ($destinatarios->isNotEmpty()) {
                Notification::send($destinatarios, new ObservacionExternaRecibidaNotification($observacion));
            }
        } catch (Throwable $e) {
            Log::error("No se pudieron enviar los avisos de la observación {$observacion->numero}: {$e->getMessage()}");
        }
    }

    /**
     * A quién queda asignado el reclamo, según el **tipo**: el mapeo
     * `incidencias.roles_por_tipo` reparte los dos tipos del portal dentro de
     * Garantía de Calidad (falla de producto y disconformidad de servicio los
     * atienden personas distintas). Se asigna por rol y no por usuario fijo:
     * cambiar de responsable es mover el rol desde el panel, sin tocar código.
     *
     * Devuelve `null` si nadie tiene el rol, y ahí el reclamo se guarda sin
     * responsable igual que antes: `avisar()` cae a su fallback y el caso queda
     * a la vista de todo el equipo de Calidad. El portal es público y un
     * reclamo no puede fallar porque falte configurar un rol.
     *
     * Si hay más de una persona con el rol gana la de `id` más bajo: sin un
     * orden explícito el resultado dependería del motor de base de datos.
     */
    private function responsableDelTipo(?string $tipo): ?int
    {
        $rol = TaxonomiaIncidencias::rolDeTipo($tipo);

        if ($rol === null) {
            return null;
        }

        return $this->porRol($rol)->orderBy('id')->value('id');
    }

    /** Usuarios con el rol que atiende ese tipo de reclamo. */
    private function destinatariosDelTipo(?string $tipo)
    {
        $rol = TaxonomiaIncidencias::rolDeTipo($tipo);

        if ($rol === null) {
            return collect();
        }

        return $this->porRol($rol)->get();
    }

    /** Fallback: todo el equipo de Calidad, por sector o por rol. */
    private function destinatariosDeCalidad(Observacion $observacion)
    {
        return User::query()
            ->where(fn ($q) => $q
                ->when(
                    $observacion->sector_id,
                    fn ($q, $sectorId) => $q->orWhere('sector_id', $sectorId)
                )
                ->orWhereHas('roles', fn ($q) => $q->where('name', Sector::GARANTIA_CALIDAD)))
            ->get();
    }

    /**
     * Filtra por rol con `whereHas` y no con el scope `User::role(...)` de
     * Spatie: ese scope tira RoleDoesNotExist si el rol no está creado, y eso
     * sería un 500 en un endpoint público.
     */
    private function porRol(string $rol)
    {
        return User::query()->whereHas('roles', fn ($q) => $q->where('name', $rol));
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
