<?php

namespace App\Services;

use App\Models\Indicador;
use App\Models\Observacion;
use App\Models\Sector;
use App\Models\User;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Support\Facades\DB;

/**
 * Dataset del tablero de gestión (pedido del cliente del 6/11/2026, "Dashboard SGO TUBLOOD"),
 * que vive en el Dashboard (`/dashboard`) para quien tenga `tablero.view`.
 *
 * Responde "¿cómo viene cada sector contra su objetivo?": fallos por sector y por
 * tipo con semáforo, índice de fallos por sector, rankings, tiempos de resolución
 * y el listado de casos detrás de cada número.
 *
 * Mismo esquema que Compras y Urgencias: el dataset viaja **entero** y compacto
 * —son cientos de observaciones por año— y los filtros (período, sector, origen y
 * lo que se toca en los gráficos) se resuelven en el navegador
 * (`resources/js/lib/tablero.ts`), así tocar una barra no es un request.
 *
 * ⚠️ **La cuenta de qué observación cuenta para qué indicador vive en el
 * navegador** (`cuenta()` en `lib/tablero.ts`), calcada de `Indicador::cuenta()`.
 * Son dos copias de la misma regla: cambiar una obliga a cambiar la otra, y hay un
 * test que fija la de PHP.
 *
 * ⚠️ **"Externo" es el TIPO de fallo, no el origen de la carga.** Un fallo externo
 * es uno de los E.1/E.2/E.3 (sector Externos: lo causó un tercero); no tiene nada
 * que ver con `observations.origen` (portal público o carga interna). Es el
 * "Origen del fallo" del prototipo del cliente.
 *
 * ⚠️ **El responsable del fallo solo viaja para quien puede verlo**
 * (`User::veResponsableDelFallo()`): el dataset entero llega al navegador, así que
 * esconderlo en la pantalla no alcanza. Es una de las puertas de esa regla.
 *
 * Las claves son de una letra por el mismo motivo que en Compras (el payload).
 */
class TableroService
{
    /** Sector que agrupa los fallos de terceros (E.1, E.2, E.3). */
    private const SECTOR_EXTERNOS = 'externos';

    /** @return array<string, mixed> */
    public function datos(User $usuario): array
    {
        $verResponsable = $usuario->veResponsableDelFallo();
        $sectores = Sector::orderBy('nombre')->get(['id', 'nombre', 'slug']);
        $etiquetas = TaxonomiaIncidencias::etiquetasTipos();
        $idExternos = $sectores->firstWhere('slug', self::SECTOR_EXTERNOS)?->id;

        // Qué sector es el dueño de cada tipo, para los casos que no traen sector.
        $sectorDeTipo = [];
        foreach (TaxonomiaIncidencias::taxonomia() as $slug => $tipos) {
            foreach (array_keys($tipos) as $clave) {
                $sectorDeTipo[$clave] = $sectores->firstWhere('slug', $slug)?->id;
            }
        }

        $obs = Observacion::query()
            ->leftJoin('users as creadores', 'creadores.id', '=', 'observations.created_by')
            ->leftJoin('clientes', 'clientes.id', '=', 'observations.cliente_id')
            ->leftJoin('personas', 'personas.id', '=', 'observations.responsable_fallo_id')
            ->orderBy('observations.created_at')
            ->get([
                'observations.id', 'observations.numero', 'observations.tipo', 'observations.estado',
                'observations.titulo', 'observations.tipo_caso', 'observations.sector_id',
                'observations.datos_especificos', 'observations.created_at', 'observations.cerrada_at',
                'observations.vence_at', 'observations.prorroga_hasta',
                'creadores.name as creador', 'creadores.apellido as creador_apellido',
                'creadores.sector_id as creador_sector_id', 'clientes.razon_social as cliente',
                'personas.nombre as responsable_fallo',
            ]);

        $filas = $obs->map(function (Observacion $o) use ($sectorDeTipo, $etiquetas, $idExternos, $verResponsable) {
            $sectorId = $o->sector_id ?? ($sectorDeTipo[$o->tipo] ?? null);
            $vence = $o->prorroga_hasta ?? $o->vence_at;
            $datos = $o->datos_especificos ?? [];
            $creador = $o->creador ? trim($o->creador.' '.($o->creador_apellido ?? '')) : null;

            return [
                'i' => $o->id,
                'n' => $o->numero,
                'f' => $o->created_at->toDateString(),
                'm' => $o->created_at->format('Y-m'),
                't' => $o->tipo,
                'l' => $etiquetas[$o->tipo] ?? $o->tipo,
                's' => $sectorId,
                'x' => $idExternos !== null && $sectorId === $idExternos ? 1 : 0,
                'e' => $o->estado,
                // Fecha pelada: una prórroga "hasta el 20/11" sigue vigente todo el 20/11.
                'v' => $vence?->toDateString(),
                'z' => $o->cerrada_at?->toDateString(),
                'ti' => $o->titulo,
                'mo' => $datos['motivo'] ?? null,
                'cl' => $o->cliente,
                'tc' => $o->tipo_caso,
                'cr' => $creador,
                'cs' => $o->creador_sector_id,
                // El proveedor o transporte que cargó el fallo externo (hoy es lo único
                // que lo identifica: los productos del caso pueden no estar).
                'pv' => $datos['transporte'] ?? $datos['proveedor'] ?? null,
                'rf' => $verResponsable ? $o->responsable_fallo : null,
            ];
        })->values();

        $codigos = fn (array $tipos) => collect($tipos)
            ->map(fn ($clave) => explode(' ', $etiquetas[$clave] ?? '', 2)[0])
            ->filter()->unique()->implode(' · ');

        return [
            'sectores' => $sectores,
            'observaciones' => $filas,
            'indicadores' => Indicador::with('valores:indicador_id,mes,valor')
                ->where('activo', true)->orderBy('sector')->orderBy('id')->get()->map(fn (Indicador $i) => [
                    'id' => $i->id,
                    'sector' => $i->sector,
                    'sector_id' => $i->sector_id,
                    'fallo' => $i->fallo,
                    'origen_dato' => $i->origen_dato,
                    'codigos' => $i->tipos ? $codigos($i->tipos) : 'fuera del SGO',
                    'tipos' => $i->tipos ?? [],
                    'criterios' => $i->criterios ?? [],
                    'objetivo' => $i->objetivo,
                    'tolerancia' => $i->tolerancia,
                    'tipo_meta' => $i->tipo_meta,
                    'cant_pedidos' => $i->cant_pedidos,
                    'importancia' => $i->importancia,
                    'estado' => $i->estado,
                    'valores' => $i->valores->pluck('valor', 'mes'),
                ])->values(),
            // Los pedidos del mes escalan los objetivos que dependen de ellos (armado).
            // Se usan las proformas como medida: son los pedidos que entran por mes.
            // ⚠️ Agrupado en PHP y no con DATE_FORMAT: es de MySQL y los tests corren en SQLite.
            'pedidosPorMes' => DB::table('proformas')->pluck('fecha')
                ->countBy(fn ($fecha) => substr((string) $fecha, 0, 7)),
            'puedeVerResponsableFallo' => $verResponsable,
            'hoy' => now()->toDateString(),
        ];
    }
}
