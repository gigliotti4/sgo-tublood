<?php

namespace App\Console\Commands;

use App\Jobs\SyncClientesJob;
use App\Services\RpSistemas\ClienteSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncClientesCommand extends Command
{
    protected $signature = 'clientes:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza los clientes desde la base SQL de RP Sistemas';

    public function handle(ClienteSyncService $service): int
    {
        if ($this->option('sync')) {
            $this->info('Sincronizando clientes de forma síncrona...');

            try {
                $total = $service->sync();
                $this->info("✓ {$total} clientes sincronizados.");
            } catch (Throwable $e) {
                $this->error("Error leyendo los clientes del ERP: {$e->getMessage()}");

                return Command::FAILURE;
            }
        } else {
            SyncClientesJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');
        }

        return Command::SUCCESS;
    }
}
