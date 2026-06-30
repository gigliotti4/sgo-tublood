<?php

namespace App\Console\Commands;

use App\Exceptions\RpSistemasException;
use App\Jobs\SyncClientesJob;
use App\Services\RpSistemas\ClienteSyncService;
use App\Services\RpSistemas\RpSistemasClient;
use Illuminate\Console\Command;

class SyncClientesCommand extends Command
{
    protected $signature   = 'clientes:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';
    protected $description = 'Sincroniza clientes desde la API de RP Sistemas';

    public function handle(): int
    {
        if ($this->option('sync')) {
            $this->info('Sincronizando clientes de forma síncrona...');

            try {
                $service = new ClienteSyncService(new RpSistemasClient());
                $total   = $service->sync();
                $this->info("✓ {$total} clientes sincronizados.");
            } catch (RpSistemasException $e) {
                $this->error("Error de API RP Sistemas [{$e->servicio}]: {$e->getMessage()}");
                return Command::FAILURE;
            }
        } else {
            SyncClientesJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');
        }

        return Command::SUCCESS;
    }
}
