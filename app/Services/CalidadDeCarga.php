<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\Proveedor;
use App\Models\Sector;
use App\Support\TaxonomiaIncidencias;

/**
 * Alertas de calidad de carga del tablero (punto 20 del pedido del cliente del
 * 6/11/2026): errores de carga que ensucian los números y que hoy solo se
 * encuentran leyendo caso por caso.
 *
 * Son **calculadas**, no guardadas: no hay una tabla de alertas que se
 * desincronice cuando alguien corrige el caso. Corregir la carga la hace
 * desaparecer sola.
 *
 * Tres reglas, las que pidió el cliente:
 * - `sector_distinto`: el sector al que se derivó el caso no es el dueño de su
 *   tipo de incidencia (p. ej. un 4.4 de Depósito derivado a Logística).
 * - `proveedor_como_cliente`: el "cliente" del caso es, en realidad, un proveedor
 *   del padrón (un reclamo a AD Flex cargado como si AD Flex hubiera reclamado).
 * - `reclamo_sin_cliente`: una queja o reclamo de cliente que no quedó vinculada
 *   a ningún cliente del padrón, así que no suma a "Clientes con más fallos".
 *
 * ⚠️ Una alerta es una **sospecha**, no un error probado: hay clientes que también
 * son proveedores, y un reclamo del portal puede traer un número de cliente que
 * todavía no está en el padrón. Por eso el panel dice "revisar" y no "corregir".
 */
class CalidadDeCarga
{
    public const REGLAS = [
        'sector_distinto' => 'Sector distinto del que corresponde al tipo',
        'proveedor_como_cliente' => 'Proveedor cargado como cliente',
        'reclamo_sin_cliente' => 'Reclamo de cliente sin cliente vinculado',
    ];

    /** Los reclamos de cliente, en `observations.tipo_caso` (ver `incidencias.tipos_caso`). */
    private const PREFIJO_RECLAMO = 'Queja/Reclamo de clientes';

    /**
     * @return list<array{regla: string, id: int, numero: string, titulo: string, detalle: string}>
     */
    public function alertas(): array
    {
        $sectores = Sector::pluck('nombre', 'id');
        $slugs = Sector::pluck('id', 'slug');
        $alertas = [];

        // Normalizados una sola vez: el padrón tiene ~1.800 proveedores.
        $proveedores = Proveedor::pluck('razon_social')
            ->mapWithKeys(fn ($r) => [Proveedor::normalizarRazonSocial((string) $r) => $r])
            ->all();

        $clientes = Cliente::whereIn('id', Observacion::whereNotNull('cliente_id')->select('cliente_id'))
            ->pluck('razon_social', 'id');

        Observacion::query()
            ->orderByDesc('id')
            ->get(['id', 'numero', 'titulo', 'tipo', 'tipo_caso', 'sector_id', 'cliente_id'])
            ->each(function (Observacion $o) use (&$alertas, $sectores, $slugs, $proveedores, $clientes) {
                $base = ['id' => $o->id, 'numero' => $o->numero, 'titulo' => (string) $o->titulo];

                // 1. Sector distinto del dueño del tipo.
                $esperado = $slugs[TaxonomiaIncidencias::sectorDeTipo((string) $o->tipo)] ?? null;

                if ($o->sector_id && $esperado && $o->sector_id !== $esperado) {
                    $alertas[] = [...$base, 'regla' => 'sector_distinto', 'detalle' => sprintf(
                        'Está en %s y el tipo %s es de %s.',
                        $sectores[$o->sector_id] ?? '—',
                        TaxonomiaIncidencias::etiquetasTipos()[$o->tipo] ?? $o->tipo,
                        $sectores[$esperado] ?? '—',
                    )];
                }

                // 2. El "cliente" es un proveedor del padrón.
                $cliente = $o->cliente_id ? ($clientes[$o->cliente_id] ?? null) : null;

                if ($cliente !== null && isset($proveedores[Proveedor::normalizarRazonSocial($cliente)])) {
                    $alertas[] = [...$base, 'regla' => 'proveedor_como_cliente', 'detalle' => "«{$cliente}» también figura en el padrón de proveedores."];
                }

                // 3. Un reclamo de cliente que no quedó vinculado a ningún cliente.
                if (! $o->cliente_id && str_starts_with((string) $o->tipo_caso, self::PREFIJO_RECLAMO)) {
                    $alertas[] = [...$base, 'regla' => 'reclamo_sin_cliente', 'detalle' => 'No tiene cliente: no suma a los rankings por cliente.'];
                }
            });

        return $alertas;
    }
}
