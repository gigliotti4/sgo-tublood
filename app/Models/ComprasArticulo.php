<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Artículo del catálogo maestro del ERP, para el módulo Compras.
 *
 * Espejo de solo lectura de `ARTICULOS` (SQL Server): la tabla se reemplaza
 * entera en cada sincronización, así que no hay campos propios del panel.
 *
 * No confundir con `Articulo`, que es el catálogo "vendible" que viene por la
 * API HTTP y sí tiene campos editables. Ver la migración para el porqué de las
 * dos tablas.
 */
class ComprasArticulo extends Model
{
    protected $table = 'compras_articulos';

    protected $guarded = [];

    protected $casts = [
        'cant_stock' => 'decimal:4',
        'sin_stock' => 'boolean',
        'activo' => 'boolean',
        'unidades_por_envase' => 'integer',
        'synced_at' => 'datetime',
    ];

    /**
     * Unidades por envase con el default aplicado.
     *
     * El ERP deja `CODIGO_REFERENCIA` vacío o en 0 cuando el artículo se cuenta
     * de a uno; en los dos casos el multiplicador es 1. La pantalla igual
     * muestra "–" y no "1" para no ensuciar la columna.
     */
    public function envase(): int
    {
        return $this->unidades_por_envase > 0 ? $this->unidades_por_envase : 1;
    }
}
