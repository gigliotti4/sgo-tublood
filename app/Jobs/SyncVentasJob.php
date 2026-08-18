<?php

namespace App\Jobs;

use App\Services\RpSistemas\VentaSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncVentasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~60.000 renglones: se borra y se reinserta la tabla entera. */
    public int $timeout = 900;

    public function handle(VentaSyncService $service): void
    {
        try {
            $total = $service->sync();
            Log::info("SyncVentasJob: completado — {$total} renglones de venta sincronizados");
        } catch (Throwable $e) {
            Log::error('SyncVentasJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
