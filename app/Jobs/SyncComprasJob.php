<?php

namespace App\Jobs;

use App\Services\RpSistemas\ComprasArticuloSyncService;
use App\Services\RpSistemas\OrdenCompraSyncService;
use App\Services\RpSistemas\PedidoPendienteSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincroniza las tres fuentes del módulo Compras de una sola vez.
 *
 * ⚠️ Un solo job para las tres tablas, a diferencia del resto del proyecto que
 * tiene un job por tabla. El tablero cruza catálogo, OC y pedidos entre sí: un
 * refresh parcial mostraría un stock de hoy contra un reservado de ayer, y esa
 * incoherencia es invisible en pantalla.
 *
 * Las ventas NO se tocan acá: ya las sincroniza `ventas:sync` a las 04:00.
 */
class SyncComprasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~5.200 artículos + 21.500 OC + 35.600 pedidos, las tres borrando e insertando. */
    public int $timeout = 900;

    public function handle(
        ComprasArticuloSyncService $articulos,
        OrdenCompraSyncService $ordenes,
        PedidoPendienteSyncService $pedidos,
    ): void {
        try {
            $a = $articulos->sync();
            $o = $ordenes->sync();
            $p = $pedidos->sync();

            Log::info("SyncComprasJob: completado — {$a} artículos, {$o} OC pendientes, {$p} pedidos pendientes");
        } catch (Throwable $e) {
            // Acá no hay `RpSistemasException`: lo que puede fallar es la
            // conexión SQL (driver ausente, firewall, credenciales) y viene
            // como PDOException.
            Log::error('SyncComprasJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
