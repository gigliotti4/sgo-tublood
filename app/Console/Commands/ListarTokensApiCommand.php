<?php

namespace App\Console\Commands;

use App\Models\Integracion;
use Illuminate\Console\Command;

/** Lista las integraciones y el estado de sus tokens. Nunca muestra el token. */
class ListarTokensApiCommand extends Command
{
    protected $signature = 'api:tokens';

    protected $description = 'Lista las integraciones de la API y sus tokens (sin mostrarlos)';

    public function handle(): int
    {
        $filas = Integracion::query()->with('tokens')->orderBy('nombre')->get()->flatMap(
            fn (Integracion $i) => $i->tokens->isEmpty()
                ? [[$i->nombre, '—', '—', '—']]
                : $i->tokens->map(fn ($t) => [
                    $i->nombre,
                    $t->created_at?->format('d/m/Y H:i'),
                    $t->last_used_at?->format('d/m/Y H:i') ?? 'nunca',
                    $t->expires_at?->format('d/m/Y') ?? 'no vence',
                ])->all()
        );

        if ($filas->isEmpty()) {
            $this->info('No hay integraciones cargadas.');

            return self::SUCCESS;
        }

        $this->table(['Sistema', 'Creado', 'Último uso', 'Vence'], $filas->all());

        return self::SUCCESS;
    }
}
