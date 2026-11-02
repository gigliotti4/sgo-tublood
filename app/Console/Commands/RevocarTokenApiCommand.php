<?php

namespace App\Console\Commands;

use App\Models\Integracion;
use Illuminate\Console\Command;

/** Da de baja todos los tokens de un sistema externo. */
class RevocarTokenApiCommand extends Command
{
    protected $signature = 'api:token-revocar {nombre : Nombre del sistema}';

    protected $description = 'Revoca todos los tokens de la API de un sistema externo';

    public function handle(): int
    {
        $integracion = Integracion::where('nombre', trim($this->argument('nombre')))->first();

        if (! $integracion) {
            $this->error('No existe una integración con ese nombre. Mirá `php artisan api:tokens`.');

            return self::FAILURE;
        }

        $cantidad = $integracion->tokens()->delete();

        $this->info("Tokens revocados para «{$integracion->nombre}»: {$cantidad}.");

        return self::SUCCESS;
    }
}
