<?php

namespace App\Jobs;

use App\Services\RpSistemas\PartidaSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPartidasJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** ~12.800 partidas: se borra y se reinserta la tabla entera. */
    public int $timeout = 900;

    public function handle(PartidaSyncService $service): void
    {
        try {
            $total = $service->sync();
            Log::info("SyncPartidasJob: completado — {$total} partidas sincronizadas");
        } catch (Throwable $e) {
            Log::error('SyncPartidasJob: error leyendo la base de RP Sistemas', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
