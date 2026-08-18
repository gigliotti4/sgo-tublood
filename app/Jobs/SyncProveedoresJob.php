<?php

namespace App\Jobs;

use App\Services\RpSistemas\ProveedorSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncProveedoresJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~1.850 filas por SQL: es la más rápida de las tres sincronizaciones. */
    public int $timeout = 300;

    public function handle(ProveedorSyncService $service): void
    {
        try {
            $resultado = $service->sync();
            Log::info("SyncProveedoresJob: completado — {$resultado['procesados']} proveedores, {$resultado['adoptados']} adoptados");
        } catch (Throwable $e) {
            // A diferencia de los sync por API, acá no hay RpSistemasException:
            // lo que puede fallar es la conexión SQL (driver ausente, firewall,
            // credenciales) y viene como PDOException.
            Log::error('SyncProveedoresJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
