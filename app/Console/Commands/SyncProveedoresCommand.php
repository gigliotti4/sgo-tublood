<?php

namespace App\Console\Commands;

use App\Jobs\SyncProveedoresJob;
use App\Services\RpSistemas\ProveedorSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncProveedoresCommand extends Command
{
    protected $signature = 'proveedores:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza el padrón de proveedores desde la base SQL de RP Sistemas';

    public function handle(ProveedorSyncService $service): int
    {
        if (! $this->option('sync')) {
            SyncProveedoresJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        $this->info('Sincronizando proveedores de forma síncrona...');

        try {
            $resultado = $service->sync();
            $this->info("✓ {$resultado['procesados']} proveedores sincronizados.");

            if ($resultado['adoptados'] > 0) {
                $this->info("✓ {$resultado['adoptados']} proveedores sin número recibieron el suyo.");
            }
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
