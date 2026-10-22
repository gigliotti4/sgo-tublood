<?php

namespace App\Jobs;

use App\Services\RpSistemas\ProformaSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sincroniza las facturas proforma del ERP para el tablero de urgencias. */
class SyncProformasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~14.000 proformas agregadas en SQL Server: tarda segundos, el margen es por la red. */
    public int $timeout = 300;

    public function handle(ProformaSyncService $proformas): void
    {
        try {
            $total = $proformas->sync();

            Log::info("SyncProformasJob: completado — {$total} proformas");
        } catch (Throwable $e) {
            Log::error('SyncProformasJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
