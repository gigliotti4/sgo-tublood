<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ComprasCoberturaNotification;
use App\Services\Compras\AlertaCobertura;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Mail mensual a Compras con los productos que no cubren stock.
 *
 * Los filtros viven en `config('compras.alerta')`. Le llega a todos los
 * usuarios que pueden ver el tablero (`compras.view`, directo o por rol). Si no
 * hay nada para comprar no se manda nada: un mail mensual que dice "0" se
 * aprende a ignorar, y entonces se ignora también el que importa.
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

        $destinatarios = $this->destinatarios();

        if ($destinatarios->isEmpty()) {
            Log::warning('compras:alerta-cobertura: nadie tiene compras.view, el aviso no llega a nadie');
            $this->warn('Nadie tiene el permiso compras.view: no hay a quién mandarlo.');

            return Command::SUCCESS;
        }

        Notification::send($destinatarios, $notificacion);
        $this->info("Mandado a {$destinatarios->count()} usuarios.");

        return Command::SUCCESS;
    }

    /**
     * Quien puede ver el tablero de Compras, directo o por rol.
     *
     * ⚠️ Con `whereHas` y NUNCA con el scope `permission()` de Spatie: ese tira
     * excepción si el permiso no existe, y una tarea agendada no puede caerse
     * por eso. Misma trampa que `User::role()` en el alta del portal.
     *
     * @return Collection<int, User>
     */
    private function destinatarios()
    {
        $tienePermiso = fn (Builder $q) => $q->where('name', 'compras.view');

        return User::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('permissions', $tienePermiso)
                ->orWhereHas('roles.permissions', $tienePermiso))
            ->get();
    }
}
