<?php

namespace App\Console\Commands;

use App\Jobs\SyncVentaPartidasJob;
use App\Services\RpSistemas\VentaPartidaSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncVentaPartidasCommand extends Command
{
    protected $signature = 'venta-partidas:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza qué lote se despachó en cada renglón de venta, desde la base SQL de RP Sistemas';

    public function handle(VentaPartidaSyncService $service): int
    {
        if (! $this->option('sync')) {
            SyncVentaPartidasJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        $this->info('Sincronizando lotes despachados de forma síncrona...');

        try {
            $total = $service->sync();
            $this->info("✓ {$total} lotes despachados sincronizados.");
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
