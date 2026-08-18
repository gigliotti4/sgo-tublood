<?php

namespace App\Console\Commands;

use App\Jobs\SyncVentasJob;
use App\Services\RpSistemas\VentaSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncVentasCommand extends Command
{
    protected $signature = 'ventas:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza el detalle de ventas desde la base SQL de RP Sistemas';

    public function handle(VentaSyncService $service): int
    {
        if (! $this->option('sync')) {
            SyncVentasJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        $this->info('Sincronizando ventas de forma síncrona...');

        try {
            $total = $service->sync();
            $this->info("✓ {$total} renglones de venta sincronizados.");
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
