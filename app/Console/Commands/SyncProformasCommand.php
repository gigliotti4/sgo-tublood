<?php

namespace App\Console\Commands;

use App\Jobs\SyncProformasJob;
use App\Services\RpSistemas\ProformaSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncProformasCommand extends Command
{
    protected $signature = 'proformas:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza las facturas proforma desde la base SQL de RP Sistemas (tablero de urgencias)';

    public function handle(ProformaSyncService $proformas): int
    {
        if (! $this->option('sync')) {
            SyncProformasJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        try {
            $total = $proformas->sync();
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->info("✓ {$total} proformas sincronizadas.");

        return Command::SUCCESS;
    }
}
