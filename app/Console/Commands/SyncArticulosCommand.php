<?php

namespace App\Console\Commands;

use App\Jobs\SyncArticulosJob;
use App\Services\RpSistemas\ArticuloSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncArticulosCommand extends Command
{
    protected $signature = 'articulos:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza el catálogo de artículos desde la base SQL de RP Sistemas';

    public function handle(ArticuloSyncService $service): int
    {
        if (! $this->option('sync')) {
            SyncArticulosJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        $this->info('Sincronizando artículos de forma síncrona...');

        try {
            $resultado = $service->sync();
            $this->info("✓ {$resultado['procesados']} artículos sincronizados ({$resultado['activos']} activos, {$resultado['desactivados']} desactivados, {$resultado['proveedores_vinculados']} proveedores vinculados).");
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
