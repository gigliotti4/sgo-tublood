<?php

namespace App\Jobs;

use App\Services\RpSistemas\ClienteSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Sincroniza los clientes del ERP (lectura por SQL: ver `ClienteSyncService`). */
class SyncClientesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(ClienteSyncService $service): void
    {
        try {
            $total = $service->sync();
            Log::info("SyncClientesJob: completado — {$total} clientes sincronizados");
        } catch (Throwable $e) {
            Log::error('SyncClientesJob: error leyendo los clientes del ERP', [
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
