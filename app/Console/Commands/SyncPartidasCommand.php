<?php

namespace App\Console\Commands;

use App\Jobs\SyncPartidasJob;
use App\Services\RpSistemas\PartidaSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncPartidasCommand extends Command
{
    protected $signature = 'partidas:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza el padrón de partidas (lotes) desde la base SQL de RP Sistemas';

    public function handle(PartidaSyncService $service): int
    {
        if (! $this->option('sync')) {
            SyncPartidasJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        $this->info('Sincronizando partidas de forma síncrona...');

        try {
            $total = $service->sync();
            $this->info("✓ {$total} partidas sincronizadas.");
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
