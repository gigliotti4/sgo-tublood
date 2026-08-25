<?php

namespace App\Console\Commands;

use App\Exceptions\RpSistemasException;
use App\Jobs\SyncArticulosJob;
use App\Services\RpSistemas\ArticuloSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use Illuminate\Console\Command;

class SyncArticulosCommand extends Command
{
    protected $signature = 'articulos:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza el catálogo de artículos desde la API de RP Sistemas';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Sincronizando artículos de forma síncrona...');

            try {
                $service = new ArticuloSyncService(new RpSistemasClient);
                $resultado = $service->sync();
                $this->info("✓ {$resultado['procesados']} artículos sincronizados ({$resultado['activos']} activos, {$resultado['desactivados']} desactivados, {$resultado['proveedores_vinculados']} proveedores vinculados).");
            } catch (RpSistemasException $e) {
                $this->error("Error de API RP Sistemas [{$e->servicio}]: {$e->getMessage()}");

                return Command::FAILURE;
            }
        } else {
            SyncArticulosJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');
        }

        return Command::SUCCESS;
    }
}
