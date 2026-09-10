<?php

namespace App\Console\Commands;

use App\Jobs\SyncComprasJob;
use App\Services\RpSistemas\ComprasArticuloSyncService;
use App\Services\RpSistemas\OrdenCompraSyncService;
use App\Services\RpSistemas\PedidoPendienteSyncService;
use Illuminate\Console\Command;
use Throwable;

class SyncComprasCommand extends Command
{
    protected $signature = 'compras:sync {--sync : Ejecutar sincronamente (sin queue, útil para debug)}';

    protected $description = 'Sincroniza catálogo, OC pendientes y pedidos desde la base SQL de RP Sistemas';

    public function handle(
        ComprasArticuloSyncService $articulos,
        OrdenCompraSyncService $ordenes,
        PedidoPendienteSyncService $pedidos,
    ): int {
        if (! $this->option('sync')) {
            SyncComprasJob::dispatch();
            $this->info('Job de sincronización despachado a la queue.');

            return Command::SUCCESS;
        }

        $this->info('Sincronizando datos de compras de forma síncrona...');

        try {
            $this->line('  Catálogo...');
            $a = $articulos->sync();
            $this->line("  ✓ {$a} artículos.");

            $this->line('  OC pendientes...');
            $o = $ordenes->sync();
            $this->line("  ✓ {$o} renglones de OC.");

            $this->line('  Pedidos pendientes...');
            $p = $pedidos->sync();
            $this->line("  ✓ {$p} renglones de pedido.");
        } catch (Throwable $e) {
            $this->error("Error leyendo la base de RP Sistemas: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->info('✓ Compras sincronizado.');

        return Command::SUCCESS;
    }
}
