<?php

namespace App\Jobs;

use App\Exceptions\RpSistemasException;
use App\Services\RpSistemas\ClienteSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncClientesJob implements ShouldQueue
{
    use Queueable;

    public int $tries   = 3;
    public int $timeout = 300;

    public function handle(RpSistemasClient $client): void
    {
        $service = new ClienteSyncService($client);

        try {
            $total = $service->sync();
            Log::info("SyncClientesJob: completado — {$total} clientes sincronizados");
        } catch (RpSistemasException $e) {
            Log::error('SyncClientesJob: error de API RP Sistemas', [
                'servicio' => $e->servicio,
                'message'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
