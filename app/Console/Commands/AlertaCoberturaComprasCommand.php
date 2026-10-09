<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ComprasCoberturaNotification;
use App\Services\Compras\AlertaCobertura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Mail mensual a Compras con los productos que no cubren stock.
 *
 * Los filtros y los destinatarios viven en `config('compras.alerta')`. Si no
 * hay nada para comprar no se manda nada: un mail mensual que dice "0" se
 * aprende a ignorar, y entonces se ignora también el que importa.
 *
 * ⚠️ Los destinatarios son una LISTA FIJA de direcciones y no "quien tenga
 * `compras.view`": medido el 9/10/2026, ese permiso lo tenían 22 usuarios
 * (ventas, cobranzas, depósito…) porque viene con roles generales, y el mail le
 * habría llegado a media empresa. Compras pidió que vaya a compras@ y a
 * Emanuel Durán.
 */
class AlertaCoberturaComprasCommand extends Command
{
    protected $signature = 'compras:alerta-cobertura
        {--ver : Mostrar el resultado en la consola sin mandar nada}
        {--a= : Mandarlo solo a esta dirección de mail (para probar)}';

    protected $description = 'Avisa por mail los productos que no cubren stock (agendado el día 1 de cada mes)';

    public function handle(AlertaCobertura $alerta): int
    {
        $resultado = $alerta->calcular();
        $filas = $resultado['filas'];
        $total = count($filas);
        $top = array_slice($filas, 0, (int) config('compras.alerta.top', 10));

        $this->info("{$total} productos para comprar · ventas de {$resultado['periodo']}");
        $this->line('Criterio: '.$alerta->criterio());

        if ($this->option('ver')) {
            $this->table(
                ['Producto', 'Proveedor', 'Stock disp.', 'Prom. mensual', 'A comprar'],
                array_map(fn ($r) => [
                    mb_strimwidth($r['grupo']['n'], 0, 45, '…'),
                    mb_strimwidth(implode(' / ', $r['proveedores']), 0, 30, '…'),
                    number_format($r['stockTotal'], 0, ',', '.'),
                    number_format($r['promMensual'], 1, ',', '.').($r['estimada'] ? ' est.' : ''),
                    number_format($r['cantComprar'], 0, ',', '.'),
                ], $top),
            );

            return Command::SUCCESS;
        }

        if ($total === 0) {
            Log::info('compras:alerta-cobertura: no hay productos para comprar, no se manda nada');
            $this->info('No hay productos para comprar: no se manda nada.');

            return Command::SUCCESS;
        }

        $notificacion = new ComprasCoberturaNotification(
            total: $total,
            periodo: $resultado['periodo'],
            top: array_map(fn ($r) => [
                'producto' => $r['grupo']['n'],
                'proveedor' => implode(' / ', $r['proveedores']),
                'comprar' => $r['cantComprar'],
                'entrega' => $r['proximaEntrega'],
            ], $top),
            excel: $alerta->excel($filas, $resultado['periodo']),
            criterio: $alerta->criterio(),
        );

        if ($direccion = $this->option('a')) {
            Notification::route('mail', $direccion)->notify($notificacion);
            $this->info("Mandado a {$direccion}.");

            return Command::SUCCESS;
        }

        $direcciones = $this->destinatarios();

        if ($direcciones === []) {
            Log::warning('compras:alerta-cobertura: no hay destinatarios en config(compras.alerta.destinatarios)');
            $this->warn('No hay destinatarios configurados: no se manda nada.');

            return Command::SUCCESS;
        }

        // Un mail por dirección y no uno con todos en copia. Si la dirección es
        // de un usuario del sistema se le manda a él, así el saludo lleva su
        // nombre; si no (una casilla compartida como compras@), va suelta.
        foreach ($direcciones as $direccion) {
            $usuario = User::where('email', $direccion)->first();

            $usuario
                ? $usuario->notify($notificacion)
                : Notification::route('mail', $direccion)->notify($notificacion);
        }

        $this->info('Mandado a '.implode(', ', $direcciones).'.');

        return Command::SUCCESS;
    }

    /**
     * Las direcciones de la config, limpias y sin repetidos.
     *
     * @return list<string>
     */
    private function destinatarios(): array
    {
        $direcciones = array_map(
            fn ($d) => mb_strtolower(trim((string) $d)),
            (array) config('compras.alerta.destinatarios', []),
        );

        return array_values(array_unique(array_filter($direcciones, fn ($d) => filter_var($d, FILTER_VALIDATE_EMAIL))));
    }
}
