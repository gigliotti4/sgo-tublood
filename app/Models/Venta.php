<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renglón de venta espejado de `powerbi_ventas_vista` del ERP.
 *
 * Es de **solo lectura desde el panel**: no hay alta, edición ni borrado. Toda
 * la tabla se reemplaza en cada sincronización (ver `VentaSyncService`), así que
 * cualquier cosa que se escriba acá se pierde en la corrida siguiente.
 *
 * Una fila por renglón, no por comprobante: el mismo `compro_nro` aparece
 * tantas veces como artículos tenga.
 */
class Venta extends Model
{
    protected $table = 'ventas';

    protected $fillable = [
        'compro_nro',
        'cod_comprobante',
        'grupo_compro_descrip',
        'fecha',
        'anio',
        'cliente',
        'razon_social',
        'nombre_fantasia',
        'provincia',
        'articulo',
        'descrip_arti',
        'cantidad',
        'precio_neto',
        'sub_total',
        'remito_nro',
        'vendedor',
        'codi_vende',
        'deposito',
        'transportista',
        'condi_venta',
        'synced_at',
    ];

    protected $casts = [
        'fecha' => 'date',
        'anio' => 'integer',
        'cliente' => 'integer',
        'remito_nro' => 'integer',
        'cantidad' => 'decimal:4',
        'precio_neto' => 'decimal:4',
        'sub_total' => 'decimal:4',
        'synced_at' => 'datetime',
    ];

    /** Campos que mira el buscador, en el orden en que se los suele usar. */
    private const CAMPOS_BUSCABLES = [
        'compro_nro',
        'remito_nro',
        'cliente',
        'razon_social',
        'articulo',
        'descrip_arti',
        'vendedor',
    ];

    /**
     * Búsqueda por comprobante, remito, cliente, artículo o vendedor, con la
     * posibilidad de **cruzar dos cosas**: `BOSO-AGUJA` trae las ventas de ese
     * cliente que además llevan ese artículo.
     *
     * Se resuelve en dos pasos, y el orden importa:
     *
     * 1. Se busca el texto **entero**, igual que siempre.
     * 2. Solo si eso no devuelve nada, se lo parte en términos (por espacios y
     *    guiones) y se exige que **cada uno** aparezca en algún campo.
     *
     * ⚠️ El fallback no es un capricho: partir siempre por el guion rompería la
     * búsqueda de códigos, que es el uso principal de esta pantalla. El 77% de
     * los artículos y el 100% de los comprobantes tienen guion, y medido sobre
     * los datos reales `621280-M` pasaba de 2 resultados a 150, porque la `M`
     * suelta aparece en media base. Buscando primero el texto entero, un código
     * que existe tal cual gana siempre.
     *
     * El cruce es **por cualquier campo, no posicional**: `AGUJA-BOSO` da lo
     * mismo que `BOSO-AGUJA`.
     *
     * Sin el `orderByRaw` de otros modelos: acá el orden natural es la fecha
     * (lo más reciente primero), y lo aplica el controller.
     */
    public function scopeBuscar(Builder $query, string $termino): Builder
    {
        $termino = trim($termino);

        if ($termino === '') {
            return $query;
        }

        $terminos = preg_split('/[\s-]+/', $termino, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // Un solo término no tiene nada que cruzar: no hace falta la sonda.
        if (count($terminos) < 2) {
            return $this->aplicarTermino($query, $termino);
        }

        // Se clona el builder ya filtrado (fechas, vendedor) a propósito: si la
        // sonda corriera sobre la tabla entera, "no hay resultados" se decidiría
        // ignorando esos filtros y el cruce no aparecería nunca con un rango de
        // fechas puesto.
        if ($this->aplicarTermino($query->clone(), $termino)->exists()) {
            return $this->aplicarTermino($query, $termino);
        }

        // AND de ORs: un `where` por término, cada uno mirando todos los campos.
        foreach ($terminos as $t) {
            $this->aplicarTermino($query, $t);
        }

        return $query;
    }

    /** Un término contra todos los campos buscables. */
    private function aplicarTermino(Builder $query, string $termino): Builder
    {
        return $query->where(function (Builder $q) use ($termino) {
            foreach (self::CAMPOS_BUSCABLES as $campo) {
                $q->orWhere($campo, 'like', "%{$termino}%");
            }
        });
    }
}
