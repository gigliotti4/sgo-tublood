<?php

namespace App\Jobs;

use App\Services\RpSistemas\ArticuloSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncArticulosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Holgado: la consulta cruza `ARTICULOS` (5.236 filas) con la lista de
    // precios, las agrupaciones y el stock por depósito.
    public int $timeout = 600;

    public function handle(ArticuloSyncService $service): void
    {
        try {
            $resultado = $service->sync();
            Log::info("SyncArticulosJob: completado — {$resultado['procesados']} artículos sincronizados ({$resultado['activos']} activos, {$resultado['desactivados']} desactivados, {$resultado['proveedores_vinculados']} proveedores vinculados)");
        } catch (Throwable $e) {
            // `Throwable` y no `RpSistemasException`: desde el 30/9/2026 esto
            // lee SQL Server y lo que falla es la conexión, que llega como
            // `PDOException`. Mismo criterio que los demás jobs de SQL.
            Log::error('SyncArticulosJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
