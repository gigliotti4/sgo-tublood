<?php

namespace App\Jobs;

use App\Services\RpSistemas\VentaPartidaSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncVentaPartidasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~164.000 filas: se borra y se reinserta la tabla entera. */
    public int $timeout = 1800;

    public function handle(VentaPartidaSyncService $service): void
    {
        try {
            $total = $service->sync();
            Log::info("SyncVentaPartidasJob: completado — {$total} lotes despachados sincronizados");
        } catch (Throwable $e) {
            Log::error('SyncVentaPartidasJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
