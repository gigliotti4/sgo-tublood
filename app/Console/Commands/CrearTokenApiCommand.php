<?php

namespace App\Console\Commands;

use App\Models\Integracion;
use Illuminate\Console\Command;

/**
 * Emite un token de la API para un sistema externo.
 *
 * El token se muestra **una sola vez**: Sanctum guarda solo su hash. Si se
 * pierde no se recupera, se emite otro (y se revoca el viejo con
 * `api:token-revocar`). Sin pantalla a propósito: hoy hay un solo consumidor.
 */
class CrearTokenApiCommand extends Command
{
    protected $signature = 'api:token-crear {nombre : Nombre del sistema que lo va a usar} {--vence= : Días de vigencia (sin esto, no vence)}';

    protected $description = 'Emite un token de solo lectura para un sistema externo';

    public function handle(): int
    {
        $dias = $this->option('vence');

        if ($dias !== null && (! ctype_digit((string) $dias) || (int) $dias < 1)) {
            $this->error('--vence tiene que ser un número de días mayor a 0.');

            return self::FAILURE;
        }

        $integracion = Integracion::firstOrCreate(['nombre' => trim($this->argument('nombre'))]);

        $token = $integracion->createToken(
            'api',
            ['observaciones:leer'],
            $dias ? now()->addDays((int) $dias) : null,
        );

        $this->info("Token creado para «{$integracion->nombre}»".($dias ? " (vence en {$dias} días)." : ' (sin vencimiento).'));
        $this->newLine();
        $this->line($token->plainTextToken);
        $this->newLine();
        $this->warn('Guardalo ahora: no se vuelve a mostrar. Pasalo por un canal seguro, no por mail ni chat.');

        return self::SUCCESS;
    }
}
