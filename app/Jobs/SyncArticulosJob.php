<?php

namespace App\Jobs;

use App\Exceptions\RpSistemasException;
use App\Services\RpSistemas\ArticuloSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncArticulosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Más holgado que el de clientes: el ERP devuelve las ~4000 filas del
    // catálogo en una sola respuesta, así que la request tarda.
    public int $timeout = 600;

    public function handle(RpSistemasClient $client): void
    {
        $service = new ArticuloSyncService($client);

        try {
            $resultado = $service->sync();
            Log::info("SyncArticulosJob: completado — {$resultado['procesados']} artículos sincronizados ({$resultado['activos']} activos, {$resultado['desactivados']} desactivados)");
        } catch (RpSistemasException $e) {
            Log::error('SyncArticulosJob: error de API RP Sistemas', [
                'servicio' => $e->servicio,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
