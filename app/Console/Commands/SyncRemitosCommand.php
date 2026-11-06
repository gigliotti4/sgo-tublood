<?php

namespace App\Console\Commands;

use App\Jobs\SyncRemitosJob;
use App\Services\RpSistemas\RemitoSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncRemitosCommand extends Command
{
    protected $signature = 'remitos:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza los remitos de venta desde la base SQL de RP Sistemas (tasa de fallos del tablero)';

    public function handle(RemitoSyncService $remitos): int
    {
        if (! $this->option('sync')) {
            SyncRemitosJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        try {
            $total = $remitos->sync();
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->info("✓ {$total} remitos sincronizados.");

        return Command::SUCCESS;
    }
}
