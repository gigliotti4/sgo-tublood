<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renglón de pedido de cliente con saldo pendiente.
 *
 * Espejo de solo lectura de `powerbi_pedidos_vista` (solo cant_pend > 0):
 * refresh completo en cada sincronización.
 */
class CompraPedidoPendiente extends Model
{
    protected $table = 'compras_pedidos_pendientes';

    protected $guarded = [];

    protected $casts = [
        'compro_nro' => 'integer',
        'renglon' => 'integer',
        'cliente' => 'integer',
        'fecha' => 'date',
        'cant_pend' => 'decimal:4',
        'synced_at' => 'datetime',
    ];

    /**
     * Las líneas que comprometen stock.
     *
     * ⚠️ La regla vive en config/compras.php porque no está conciliada con el
     * ERP: el campo que decide la reserva no está en la vista. Ver el comentario
     * del config antes de tocar esto.
     */
    public function scopeReservadas(Builder $query): Builder
    {
        $excluidos = config('compras.reserva.estados_excluidos', []);

        return $query
            ->where('reser', 'S')
            ->whereIn('deposito_reserva', config('compras.reserva.depositos', []))
            ->where('cant_pend', '>', 0)
            ->when($excluidos, fn (Builder $q) => $q->whereNotIn('estado', $excluidos));
    }
}
