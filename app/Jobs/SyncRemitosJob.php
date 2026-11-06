<?php

namespace App\Jobs;

use App\Services\RpSistemas\RemitoSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sincroniza los remitos de venta del ERP para la tasa de fallos del tablero. */
class SyncRemitosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~68.000 renglones agregados en SQL Server: tarda segundos, el margen es por la red. */
    public int $timeout = 300;

    public function handle(RemitoSyncService $remitos): void
    {
        try {
            $total = $remitos->sync();

            Log::info("SyncRemitosJob: completado — {$total} remitos");
        } catch (Throwable $e) {
            Log::error('SyncRemitosJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
